<?php
/**
 * The text zones of a page (plugin 1.7.0, lot 11 of the automations refonte): read live with the page's
 * editor and the date it was modified, written by zone under a guard, an exact copy of the page kept first
 * so that Tamiro can bring it back. The editor is detected page by page: a page built with Elementor on a
 * site that stays on Gutenberg is an Elementor page.
 *
 * The copy is the plugin's own (`_kaigen_zones_backup_<id>`), never a WordPress revision: a site that keeps
 * few revisions prunes them, and restoring one copied Elementor's data without clearing its caches, the page
 * still showing the version undone (faaaster.io, 2026-10-16). A copy is dropped once restored, or after 30 days.
 *
 *   GET  /kaigen/v1/zones/<post_id>          the zones, the structures Tamiro may duplicate, the editor
 *   POST /kaigen/v1/zones/<post_id>          { expected_modified, operations }: the zones written, the copy named
 *   POST /kaigen/v1/zones/<post_id>/revert   { backup, expected_modified }: the page brought back to the copy
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once KAIGEN_PLUGIN_DIR . 'includes/zones/class-kaigen-zones-text.php';
require_once KAIGEN_PLUGIN_DIR . 'includes/zones/class-kaigen-zones-blocks.php';
require_once KAIGEN_PLUGIN_DIR . 'includes/zones/class-kaigen-zones-html.php';
require_once KAIGEN_PLUGIN_DIR . 'includes/zones/class-kaigen-zones-elementor.php';

class Kaigen_Zones {

    // The editors Tamiro writes in; any other is read as not writable, its zones left to the client.
    private const WRITABLE = array('gutenberg', 'classic', 'elementor');
    private const BACKUP_PREFIX = '_kaigen_zones_backup_';
    private const BACKUP_DAYS = 30;

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * The editor of this page, by what the page itself holds: Elementor's edit mode, Divi's builder,
     * WPBakery's rows, Beaver Builder, Oxygen, Bricks; else blocks or classic HTML.
     */
    public function editor_of($post) {
        $id = $post->ID;
        if (get_post_meta($id, '_elementor_edit_mode', true) === 'builder') {
            return 'elementor';
        }
        if (get_post_meta($id, '_et_pb_use_builder', true) === 'on') {
            return 'divi';
        }
        if (get_post_meta($id, '_fl_builder_enabled', true)) {
            return 'beaver_builder';
        }
        if (get_post_meta($id, 'ct_builder_shortcodes', true) || get_post_meta($id, '_ct_builder_json', true)) {
            return 'oxygen';
        }
        if (get_post_meta($id, '_bricks_page_content_2', true)) {
            return 'bricks';
        }
        if (strpos($post->post_content, '[vc_row') !== false) {
            return 'wpbakery';
        }
        return has_blocks($post->post_content) ? 'gutenberg' : 'classic';
    }

    public function read($post_id) {
        $post = $this->post($post_id);
        if (is_wp_error($post)) {
            return $post;
        }
        $editor = $this->editor_of($post);
        $reason = $this->not_writable($post, $editor);
        try {
            $found = $this->zones_of($post, $editor);
        } catch (Kaigen_Zones_Error $error) {
            return $this->wp_error($error);
        }
        return array(
            'post_id' => $post->ID,
            'url' => get_permalink($post),
            'title' => get_the_title($post),
            'editor' => $editor,
            'writable' => $reason === null,
            'reason' => $reason,
            'modified' => get_post_modified_time('c', true, $post),
            'zones' => $found['zones'],
            'structures' => $found['structures'],
            'sections' => $found['sections'],
        );
    }

    /**
     * Writes the zones: the page as Tamiro read it (`expected_modified`), each zone changed as read (its
     * hash), all or nothing. An exact copy of the page as it was is kept first; the answer names it, and
     * says whether the page holds what was written (`integrity`).
     */
    public function write($post_id, $data) {
        $post = $this->post($post_id);
        if (is_wp_error($post)) {
            return $post;
        }
        $editor = $this->editor_of($post);
        $reason = $this->not_writable($post, $editor);
        if ($reason !== null) {
            return new WP_Error($reason, __('Tamiro does not write the zones of this page', 'kaigen-connector'), array('status' => 422, 'editor' => $editor));
        }
        $guard = $this->guard($post, $data);
        if (is_wp_error($guard)) {
            return $guard;
        }
        $operations = isset($data['operations']) && is_array($data['operations']) ? array_values($data['operations']) : array();
        if (empty($operations)) {
            return new WP_Error('no_operation', __('No operation to apply', 'kaigen-connector'), array('status' => 400));
        }
        // The copy is taken once the operations are known to apply, right before the save.
        $copies = array();
        try {
            if ($editor === 'elementor') {
                $expected = Kaigen_Zones_Elementor::apply($this->elementor_tree($post), $operations, null, $copies);
                $backup = $this->keep_backup($post, $editor);
                $saved = $this->save_elementor($post, $expected);
            } else {
                $expected = $editor === 'gutenberg' ? Kaigen_Zones_Blocks::apply($post->post_content, $operations, $copies) : Kaigen_Zones_Html::apply($post->post_content, $operations);
                $backup = $this->keep_backup($post, $editor);
                $saved = $this->save_content($post, $expected);
            }
        } catch (Kaigen_Zones_Error $error) {
            return $this->wp_error($error);
        }
        if (is_wp_error($saved)) {
            $this->drop_backup($post->ID, $backup);
            return $saved;
        }
        $read = $this->read($post->ID);
        if (is_wp_error($read)) {
            return $read;
        }
        $read['backup'] = $backup;
        // Each section duplicated: the copy, the images it keeps from its source, to replace (lot 11,
        // second step), the anchors removed.
        $read['copies'] = $copies;
        $read['integrity'] = $this->integrity($post->ID, $editor, $expected);
        do_action('kaigen_after_zones_write', $post->ID, $backup);
        return $read;
    }

    /**
     * What the page holds once saved, against what Tamiro wrote: the content byte for byte (Gutenberg,
     * classic), the elements and every zone's text (Elementor, which may normalize the settings it owns).
     * A difference is reported, never hidden: the caller brings the page back.
     */
    private function integrity($post_id, $editor, $expected) {
        clean_post_cache($post_id);
        $post = get_post($post_id);
        try {
            if ($editor === 'elementor') {
                $differences = Kaigen_Zones_Elementor::differences($expected, $this->elementor_tree($post));
            } else {
                $differences = $post->post_content === $expected ? array() : array('content');
            }
        } catch (Kaigen_Zones_Error $error) {
            $differences = array($error->error_code);
        }
        return array('ok' => empty($differences), 'differences' => $differences);
    }

    /**
     * Brings the page back to the copy a write kept (`backup`), the page as Tamiro last wrote it
     * (`expected_modified`): a change made since then by someone else is never undone. The copy is dropped
     * once the page holds it again.
     */
    public function revert($post_id, $data) {
        $post = $this->post($post_id);
        if (is_wp_error($post)) {
            return $post;
        }
        $guard = $this->guard($post, $data);
        if (is_wp_error($guard)) {
            return $guard;
        }
        $id = isset($data['backup']) ? preg_replace('/[^a-f0-9]/', '', strval($data['backup'])) : '';
        $backup = $id !== '' ? get_post_meta($post->ID, self::BACKUP_PREFIX . $id, true) : null;
        if (!is_array($backup) || !isset($backup['editor'], $backup['content'])) {
            return new WP_Error('unknown_backup', __('No copy of this page under this name', 'kaigen-connector'), array('status' => 422));
        }
        $editor = $this->editor_of($post);
        if ($editor !== $backup['editor']) {
            return new WP_Error('editor_changed', __('The page changed editor since the copy was kept', 'kaigen-connector'), array('status' => 409, 'editor' => $editor));
        }
        if ($editor === 'elementor') {
            // Saved through Elementor, as its history does, so that its caches and CSS follow.
            $expected = json_decode(strval($backup['elementor']), true);
            if (!is_array($expected)) {
                return new WP_Error('unreadable_backup', __('The copy holds no readable Elementor data', 'kaigen-connector'), array('status' => 500));
            }
            $saved = $this->save_elementor($post, $expected);
        } else {
            $expected = strval($backup['content']);
            $saved = $this->save_content($post, $expected);
        }
        if (is_wp_error($saved)) {
            return $saved;
        }
        $read = $this->read($post->ID);
        if (is_wp_error($read)) {
            return $read;
        }
        $read['integrity'] = $this->integrity($post->ID, $editor, $expected);
        if ($read['integrity']['ok']) {
            $this->drop_backup($post->ID, $id);
        }
        return $read;
    }

    /**
     * An exact copy of the page as it is, its content and Elementor's data as stored, under a new name;
     * the page's copies older than 30 days dropped.
     */
    private function keep_backup($post, $editor) {
        foreach (array_keys(get_post_meta($post->ID)) as $key) {
            if (strpos($key, self::BACKUP_PREFIX) !== 0) {
                continue;
            }
            $old = get_post_meta($post->ID, $key, true);
            if (!is_array($old) || !isset($old['created']) || strtotime($old['created']) < time() - self::BACKUP_DAYS * DAY_IN_SECONDS) {
                delete_post_meta($post->ID, $key);
            }
        }
        $id = str_replace('-', '', wp_generate_uuid4());
        $copy = array(
            'created' => gmdate('c'),
            'editor' => $editor,
            'modified' => get_post_modified_time('c', true, $post),
            'content' => $post->post_content,
            'elementor' => $editor === 'elementor' ? strval(get_post_meta($post->ID, '_elementor_data', true)) : null,
        );
        if (!add_post_meta($post->ID, self::BACKUP_PREFIX . $id, wp_slash($copy), true)) {
            throw new Kaigen_Zones_Error('no_backup', 'The page as it is could not be copied', 500);
        }
        // The copy read back as kept: a copy that would not restore the page is no copy.
        $kept = get_post_meta($post->ID, self::BACKUP_PREFIX . $id, true);
        if (!is_array($kept) || $kept['content'] !== $copy['content'] || $kept['elementor'] !== $copy['elementor']) {
            delete_post_meta($post->ID, self::BACKUP_PREFIX . $id);
            throw new Kaigen_Zones_Error('no_backup', 'The copy of the page does not read back as the page', 500);
        }
        return $id;
    }

    private function drop_backup($post_id, $id) {
        if ($id) {
            delete_post_meta($post_id, self::BACKUP_PREFIX . $id);
        }
    }

    private function post($post_id) {
        $post = get_post(intval($post_id));
        if (!$post || $post->post_type === 'revision') {
            return new WP_Error('post_not_found', __('Post not found', 'kaigen-connector'), array('status' => 404));
        }
        return $post;
    }

    // Why this page's zones are not written, or null: its editor, Elementor's page with Elementor inactive.
    private function not_writable($post, $editor) {
        if (!in_array($editor, self::WRITABLE, true)) {
            return 'editor_' . $editor;
        }
        if ($editor === 'elementor' && !class_exists('\Elementor\Plugin')) {
            return 'elementor_inactive';
        }
        return null;
    }

    private function zones_of($post, $editor) {
        if ($editor === 'gutenberg') {
            return Kaigen_Zones_Blocks::read($post->post_content);
        }
        if ($editor === 'classic') {
            return Kaigen_Zones_Html::read($post->post_content);
        }
        if ($editor === 'elementor') {
            return Kaigen_Zones_Elementor::read($this->elementor_tree($post));
        }
        return array('zones' => array(), 'structures' => array(), 'sections' => array());
    }

    private function guard($post, $data) {
        $expected = isset($data['expected_modified']) ? strval($data['expected_modified']) : '';
        if ($expected === '') {
            return new WP_Error('expected_modified_required', __('The date the page was read is required', 'kaigen-connector'), array('status' => 400));
        }
        $current = get_post_modified_time('c', true, $post);
        if (strtotime($expected) !== strtotime($current)) {
            return new WP_Error('modified_since_read', __('The page changed since it was read', 'kaigen-connector'), array('status' => 409, 'modified' => $current));
        }
        return true;
    }

    private function elementor_tree($post) {
        $raw = get_post_meta($post->ID, '_elementor_data', true);
        $tree = is_array($raw) ? $raw : json_decode(strval($raw), true);
        if (!is_array($tree)) {
            throw new Kaigen_Zones_Error('unreadable_elementor', 'The Elementor data of this page is unreadable', 422);
        }
        return $tree;
    }

    // The content written back without the HTML filter, which would strip from the rest of the page what
    // a request without a user may not write (an iframe, a form); slashed, as wp_update_post unslashes.
    private function save_content($post, $content) {
        kses_remove_filters();
        $result = wp_update_post(wp_slash(array('ID' => $post->ID, 'post_content' => $content)), true);
        kses_init_filters();
        return is_wp_error($result) ? $result : true;
    }

    /**
     * The tree saved through Elementor's document, which keeps a revision and renders the page's CSS
     * again. Elementor saves only for a user who may edit the page: the page's author for this save.
     */
    private function save_elementor($post, $tree) {
        $author = intval($post->post_author);
        if (!$author || !user_can($author, 'edit_post', $post->ID)) {
            return new WP_Error('elementor_needs_editor', __('The page has no author who may edit it, which Elementor requires to save', 'kaigen-connector'), array('status' => 422));
        }
        $document = \Elementor\Plugin::$instance->documents->get($post->ID);
        if (!$document) {
            return new WP_Error('unreadable_elementor', __('Elementor does not open this page', 'kaigen-connector'), array('status' => 422));
        }
        $user = get_current_user_id();
        wp_set_current_user($author);
        try {
            $saved = $document->save(array('elements' => $tree));
        } finally {
            wp_set_current_user($user);
        }
        if (!$saved) {
            return new WP_Error('elementor_save_failed', __('Elementor refused to save the page', 'kaigen-connector'), array('status' => 500));
        }
        return true;
    }

    private function wp_error($error) {
        return new WP_Error($error->error_code, $error->getMessage(), array_merge(array('status' => $error->status), $error->details));
    }
}
