<?php

if (!defined('ABSPATH')) {
    exit;
}

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

class Kaigen_Plugin_Updater
{
    private static $instance = null;

    private $update_checker = null;

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        $this->bootstrap();
    }

    private function bootstrap()
    {
        $library_path = KAIGEN_PLUGIN_DIR . 'lib/plugin-update-checker/plugin-update-checker.php';
        if (!file_exists($library_path)) {
            return;
        }

        require_once $library_path;

        if (!class_exists(PucFactory::class)) {
            return;
        }

        $this->update_checker = PucFactory::buildUpdateChecker(
            KAIGEN_GITHUB_PLUGIN_REPOSITORY,
            KAIGEN_PLUGIN_DIR . 'kaigen-connector.php',
            KAIGEN_PLUGIN_SLUG
        );

        $this->update_checker->setBranch(KAIGEN_GITHUB_PLUGIN_BRANCH);
        $this->update_checker->getVcsApi()->enableReleaseAssets('/kaigen-connector.*\.zip($|[?&#])/i');
    }

    public function get_update_checker()
    {
        return $this->update_checker;
    }
}
