<?php

if (!defined('ABSPATH')) {
    exit;
}

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

class Kaigen_Plugin_Updater
{
    private static $instance = null;

    private const REQUEST_TIMEOUT_SECONDS = 15;

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
        $this->update_checker->addHttpRequestArgFilter(array($this, 'filter_http_request_args'));

        add_action('puc_api_error', array($this, 'log_update_error'), 10, 4);
    }

    public function get_update_checker()
    {
        return $this->update_checker;
    }

    public function filter_http_request_args($options)
    {
        if (!is_array($options)) {
            $options = array();
        }

        $options['timeout'] = self::REQUEST_TIMEOUT_SECONDS;

        return $options;
    }

    public function log_update_error($error, $http_response = null, $url = null, $slug = null)
    {
        if ($slug !== KAIGEN_PLUGIN_SLUG) {
            return;
        }

        if ($error instanceof WP_Error) {
            $message = $error->get_error_message();
            $code = $error->get_error_code();
        } else {
            $message = is_string($error) ? $error : 'Unknown plugin update error';
            $code = 'unknown';
        }

        $response_code = '';
        if (is_array($http_response) && isset($http_response['response']['code'])) {
            $response_code = ' http_status=' . strval($http_response['response']['code']);
        }

        error_log(
            '[Kaigen connector][plugin_update] '
            . 'slug=' . KAIGEN_PLUGIN_SLUG
            . ' code=' . strval($code)
            . ' url=' . strval($url)
            . $response_code
            . ' message=' . $message
        );
    }
}
