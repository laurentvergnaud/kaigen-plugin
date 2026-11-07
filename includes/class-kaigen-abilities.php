<?php
/**
 * The site's inventory for Tamiro (1.10.0, ADR 0188): the version of its SEO plugin, and the abilities the
 * site registers with WordPress's Abilities API (WordPress 6.9 and later), each by its name, its category and
 * what it declares of itself (read only, destructive, idempotent). Read only: nothing is executed here.
 * Served by `GET /structure?include=abilities`, so that the reads before a write stay as light as before.
 * `describe` is pure, so that it runs in the tests without WordPress.
 */

if (!defined('ABSPATH') && !defined('KAIGEN_ABILITIES_TESTS')) {
    exit;
}

class Kaigen_Abilities
{
    // The abilities listed at most: a site with many plugins registers dozens; the inventory says how many.
    const MAX_LISTED = 300;

    /**
     * The version of the active SEO plugin, read from the constant each one defines; null without one.
     */
    public static function seo_plugin_version()
    {
        foreach (array('WPSEO_VERSION', 'RANK_MATH_VERSION', 'SEOPRESS_VERSION', 'AIOSEO_VERSION') as $constant) {
            if (defined($constant)) {
                return strval(constant($constant));
            }
        }
        return null;
    }

    /**
     * The abilities registered on the site; null when WordPress has no Abilities API (before 6.9).
     */
    public static function inventory()
    {
        if (!function_exists('wp_get_abilities')) {
            return null;
        }
        $abilities = wp_get_abilities();
        return self::describe(is_array($abilities) ? $abilities : array());
    }

    /**
     * What Tamiro keeps of each ability, sorted by name: never its schemas nor its callbacks.
     */
    public static function describe(array $abilities)
    {
        $listed = array();
        foreach ($abilities as $ability) {
            if (!is_object($ability) || !method_exists($ability, 'get_name')) {
                continue;
            }
            $meta = method_exists($ability, 'get_meta') ? $ability->get_meta() : array();
            $annotations = is_array($meta) && isset($meta['annotations']) && is_array($meta['annotations']) ? $meta['annotations'] : array();
            $flag = function ($key) use ($annotations) {
                return array_key_exists($key, $annotations) && $annotations[$key] !== null ? (bool) $annotations[$key] : null;
            };
            $listed[] = array(
                'name' => strval($ability->get_name()),
                'category' => method_exists($ability, 'get_category') ? (strval($ability->get_category()) ?: null) : null,
                'readonly' => $flag('readonly'),
                'destructive' => $flag('destructive'),
                'idempotent' => $flag('idempotent'),
            );
        }
        usort($listed, function ($a, $b) {
            return strcmp($a['name'], $b['name']);
        });
        return array('count' => count($listed), 'listed' => array_slice($listed, 0, self::MAX_LISTED));
    }
}
