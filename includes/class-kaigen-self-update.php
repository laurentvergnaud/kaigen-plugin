<?php

/**
 * The plugin's own update, triggered by Tamiro (1.14.0, ADR 0199)
 *
 * Tamiro asks a site to install the latest release of this plugin, the release asset the update checker reads on the
 * plugin's own repository (never a tag or a branch, `Kaigen_Plugin_Updater`); no address, package or version comes
 * with the request, so the route can install nothing else. The update runs as WordPress's background updates do
 * (`wp_doing_cron` during the upgrade): the plugin stays active, where an update from a screen deactivates it and waits
 * for a browser to activate it again.
 *
 * Refused, before anything is read or written (security audit of 2026-11-08): a site whose files may not be modified
 * (`DISALLOW_FILE_MODS`, `wp_is_file_mod_allowed`), a multisite network (the files are the network's, one subsite's
 * key would update them all), a site where WordPress cannot write its own files, and an update already running. The
 * release is read on GitHub once a minute at most.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Kaigen_Self_Update
{
    private const LOCK = 'kaigen_self_update';
    private const LOCK_SECONDS = 600;
    private const CHECKED = 'kaigen_self_update_checked';
    private const CHECK_SECONDS = 60;
    private const UPGRADE_SECONDS = 300;

    /**
     * Installs the latest release when it is newer than the installed version.
     *
     * @return array|WP_Error `status` `updated` or `up_to_date`, `from` the version installed before, `to` the
     *                        version installed after, `latest` the release read.
     */
    public static function run()
    {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        $from = KAIGEN_VERSION;
        $checker = Kaigen_Plugin_Updater::get_instance()->get_update_checker();
        if (!$checker) {
            return new WP_Error('update_checker_missing', 'The update checker of the plugin is not loaded', array('status' => 500));
        }

        if (!wp_is_file_mod_allowed('kaigen_self_update')) {
            return new WP_Error('file_mods_disallowed', 'The site does not allow its files to be modified (DISALLOW_FILE_MODS)', array('status' => 409));
        }
        if (is_multisite()) {
            return new WP_Error('multisite', 'The plugin of a multisite network is updated by its network administrator', array('status' => 409));
        }
        // An update needs WordPress to write the plugin's files itself; a site that asks for FTP credentials is
        // updated by hand.
        if (get_filesystem_method(array(), WP_PLUGIN_DIR) !== 'direct') {
            return new WP_Error('filesystem_not_direct', 'WordPress cannot write the plugin files itself', array('status' => 409));
        }

        if (!WP_Upgrader::create_lock(self::LOCK, self::LOCK_SECONDS)) {
            return new WP_Error('update_in_progress', 'An update of the plugin is already running', array('status' => 409));
        }
        try {
            return self::update_locked($checker, $from);
        } finally {
            WP_Upgrader::release_lock(self::LOCK);
        }
    }

    private static function update_locked($checker, $from)
    {
        // The release read now, not the one cached by the site's last check; once a minute at most, GitHub's API
        // answering 60 calls an hour to an address that shared hosts share.
        if (!get_transient(self::CHECKED)) {
            $checker->checkForUpdates();
            set_transient(self::CHECKED, 1, self::CHECK_SECONDS);
        }
        $release = $checker->getUpdateState()->getUpdate();
        if (!$release || empty($release->version)) {
            foreach ((array) $checker->getLastRequestApiErrors() as $entry) {
                $error = isset($entry['error']) ? $entry['error'] : null;
                error_log('[Tamiro connector][self_update] release unreadable: ' . ($error instanceof WP_Error ? $error->get_error_message() : (is_string($error) ? $error : 'unknown')));
            }
            return new WP_Error('release_unreadable', 'The latest release of the plugin could not be read', array('status' => 502));
        }

        $latest = strval($release->version);
        if (version_compare($latest, $from, '<=')) {
            return array('status' => 'up_to_date', 'from' => $from, 'to' => $from, 'latest' => $latest);
        }

        // Downloading and replacing the files on a slow host, whatever becomes of the caller.
        if (function_exists('set_time_limit')) {
            @set_time_limit(self::UPGRADE_SECONDS);
        }
        ignore_user_abort(true);

        $skin = new Automatic_Upgrader_Skin();
        $upgrader = new Plugin_Upgrader($skin);
        add_filter('wp_doing_cron', '__return_true');
        try {
            $result = $upgrader->upgrade(KAIGEN_PLUGIN_BASENAME, array('clear_update_cache' => true));
        } finally {
            remove_filter('wp_doing_cron', '__return_true');
        }

        if ($result !== true) {
            $messages = $skin->get_upgrade_messages();
            if (is_wp_error($result)) {
                array_unshift($messages, $result->get_error_message());
            }
            // The upgrader's messages name the server's paths: logged on the site, answered without them.
            error_log('[Tamiro connector][self_update] failed: ' . implode(' | ', $messages));
            return new WP_Error('update_failed', self::without_paths($messages ? implode(' ', $messages) : 'WordPress did not install the update'), array('status' => 500));
        }

        // The constant still holds the version loaded with this request: the installed one is read from the file.
        $installed = get_file_data(WP_PLUGIN_DIR . '/' . KAIGEN_PLUGIN_BASENAME, array('version' => 'Version'));
        $to = isset($installed['version']) && $installed['version'] !== '' ? $installed['version'] : $latest;

        return array('status' => 'updated', 'from' => $from, 'to' => $to, 'latest' => $latest);
    }

    private static function without_paths($text)
    {
        $roots = array_filter(array(WP_PLUGIN_DIR, defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : '', rtrim(ABSPATH, '/')));
        return str_replace($roots, '…', $text);
    }
}
