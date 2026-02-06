<?php
/**
 * Editor Button handler
 * Adds "Open in Kaigen" button to post editor
 */

if (!defined('ABSPATH')) {
    exit;
}

class Kaigen_Editor_Button {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_enqueue_scripts', array($this, 'enqueue_editor_assets'));
        add_action('add_meta_boxes', array($this, 'add_meta_box'));
        add_action('admin_footer', array($this, 'add_classic_editor_button'));
    }

    /**
     * Enqueue editor assets
     */
    public function enqueue_editor_assets($hook) {
        // Only on post edit screens
        if (!in_array($hook, array('post.php', 'post-new.php'))) {
            return;
        }

        // Check if user has permission
        if (!current_user_can('kaigen_edit_posts')) {
            return;
        }

        // Check if post type is enabled
        global $post;
        if (!$post) {
            return;
        }

        $settings = get_option('kaigen_settings', array());
        $enabled_types = isset($settings['enabled_post_types']) ? $settings['enabled_post_types'] : array('post', 'page');

        if (!in_array($post->post_type, $enabled_types)) {
            return;
        }

        wp_enqueue_script(
            'kaigen-editor-button',
            KAIGEN_PLUGIN_URL . 'admin/js/kaigen-editor-button.js',
            array('jquery'),
            KAIGEN_VERSION,
            true
        );

        wp_localize_script('kaigen-editor-button', 'kaigenEditor', array(
            'postId' => $post->ID,
            'wpUrl' => home_url(),
            'editorUrl' => $this->get_editor_url($post->ID),
            'strings' => array(
                'openInKaigen' => __('Open in Kaigen', 'kaigen-connector'),
                'loading' => __('Loading...', 'kaigen-connector')
            )
        ));
    }

    /**
     * Add meta box for Gutenberg
     */
    public function add_meta_box() {
        // Check if user has permission
        if (!current_user_can('kaigen_edit_posts')) {
            return;
        }

        $settings = get_option('kaigen_settings', array());
        $enabled_types = isset($settings['enabled_post_types']) ? $settings['enabled_post_types'] : array('post', 'page');

        add_meta_box(
            'kaigen-editor-link',
            __('Kaigen', 'kaigen-connector'),
            array($this, 'render_meta_box'),
            $enabled_types,
            'side',
            'high'
        );
    }

    /**
     * Render meta box
     */
    public function render_meta_box($post) {
        $editor_url = $this->get_editor_url($post->ID);
        ?>
        <div class="kaigen-meta-box">
            <p><?php _e('Edit this post in Kaigen for AI-powered content generation and optimization.', 'kaigen-connector'); ?></p>
            <a href="<?php echo esc_url($editor_url); ?>"
               class="button button-primary button-large"
               target="_blank"
               rel="noopener noreferrer">
                <?php _e('Open in Kaigen', 'kaigen-connector'); ?>
            </a>
        </div>
        <?php
    }

    /**
     * Add button to classic editor
     */
    public function add_classic_editor_button() {
        global $post;
        $screen = get_current_screen();

        // Only on post edit screens
        if (!$screen || !in_array($screen->base, array('post'))) {
            return;
        }

        // Check if user has permission
        if (!current_user_can('kaigen_edit_posts')) {
            return;
        }

        // Check if post type is enabled
        if (!$post) {
            return;
        }

        $settings = get_option('kaigen_settings', array());
        $enabled_types = isset($settings['enabled_post_types']) ? $settings['enabled_post_types'] : array('post', 'page');

        if (!in_array($post->post_type, $enabled_types)) {
            return;
        }

        // Check if using classic editor
        if (!function_exists('use_block_editor_for_post') || use_block_editor_for_post($post)) {
            return;
        }

        $editor_url = $this->get_editor_url($post->ID);
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            if (typeof QTags !== 'undefined') {
                QTags.addButton(
                    'kaigen_open',
                    '<?php _e('Open in Kaigen', 'kaigen-connector'); ?>',
                    function() {
                        window.open('<?php echo esc_js($editor_url); ?>', '_blank');
                    }
                );
            }

            // Add button to editor toolbar
            $('#wp-content-editor-tools').append(
                '<a href="<?php echo esc_url($editor_url); ?>" ' +
                'class="button button-primary" ' +
                'target="_blank" ' +
                'rel="noopener noreferrer" ' +
                'style="margin-left: 10px;">' +
                '<?php _e('Open in Kaigen', 'kaigen-connector'); ?>' +
                '</a>'
            );
        });
        </script>
        <?php
    }

    /**
     * Get editor URL for a post
     */
    private function get_editor_url($post_id) {
        $api = Kaigen_API::get_instance();
        $auth = Kaigen_Auth::get_instance();

        // Try to get project ID from validation
        $validation = $auth->validate_with_kaigen();
        $project_id = isset($validation['project_id']) ? $validation['project_id'] : 'default';

        $api_url = $auth->get_api_url();
        $wp_url = home_url();

        // Build editor URL
        $editor_url = trailingslashit($api_url) . 'en/projects/' . $project_id . '/editor';
        $editor_url = add_query_arg(array(
            'wp_post_id' => $post_id,
            'wp_site' => urlencode($wp_url)
        ), $editor_url);

        return apply_filters('kaigen_editor_url', $editor_url, $post_id);
    }
}





