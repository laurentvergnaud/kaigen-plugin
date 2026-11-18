<?php
/**
 * The links that live outside an article's content (1.15.0, lot 2A of ADR 0212): a dead link or a link to a
 * redirect in a menu, a block navigation, a template part, a synced pattern or a widget, replaced by the
 * address Tamiro was told. Each place keeps everything else as it was: a menu item's address alone, the `href`
 * of a link or the `url` attribute of a block, byte for byte around them. A stored address is compared with the
 * one Tamiro names the way Tamiro compares pages (host without `www.`, path without its trailing slash, relative
 * addresses read against the site), so that a menu's relative link is found too. A WordPress user's request
 * (the application password mode) needs the right to edit the theme's options, as in the admin.
 *
 * Also the redirect managers the site has (lot 2B1): which of Yoast Premium, SEOPress PRO, Rank Math's
 * redirections module and Redirection is active, read in their constants and classes, never written to.
 *
 * `same_address`, `with_fragment` and `repoint_markup` are pure, so that they run in the tests without WordPress.
 */

if (!defined('ABSPATH') && !defined('KAIGEN_SITE_LINKS_TESTS')) {
    exit;
}

class Kaigen_Site_Links
{
    // The post types whose content is markup that shows on every page: block navigations, template parts and
    // synced patterns, as the site saved them (a theme's own files are not in the database).
    const MARKUP_TYPES = array('wp_navigation', 'wp_template_part', 'wp_block');

    /**
     * The key Tamiro compares addresses on: host without `www.`, lowercased, path without its trailing slash;
     * scheme, query and fragment aside. A relative address is read against the site's home. Null when it is
     * no web address (a `mailto:`, a `#fragment`, an empty value).
     */
    public static function address_key($url, $home)
    {
        $url = trim(html_entity_decode(strval($url), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($url === '' || $url[0] === '#' || preg_match('#^(mailto|tel|javascript|data):#i', $url)) {
            return null;
        }
        if (strpos($url, '//') === 0) {
            $url = 'https:' . $url;
        } elseif (!preg_match('#^https?://#i', $url)) {
            $base = rtrim(strval($home), '/');
            $url = $base . ($url[0] === '/' ? '' : '/') . $url;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return null;
        }
        $host = preg_replace('/^www\./', '', strtolower($parts['host']));
        $path = isset($parts['path']) ? rtrim($parts['path'], '/') : '';
        return $host . strtolower(rawurldecode($path));
    }

    public static function same_address($stored, $target, $home)
    {
        $a = self::address_key($stored, $home);
        return $a !== null && $a === self::address_key($target, $home);
    }

    /**
     * The new address, the old link's own fragment kept (`/old/#price` becomes `/new/#price`).
     */
    public static function with_fragment($stored, $to)
    {
        $hash = strpos(strval($stored), '#');
        if ($hash === false) {
            return $to;
        }
        $base = explode('#', $to)[0];
        return $base . substr(strval($stored), $hash);
    }

    /**
     * Replaces in markup the `href` of each link and the `url` attribute of each block whose address is one of
     * the repoints' `from`; returns the new markup and how many addresses changed. Everything else is kept byte
     * for byte. A block attribute keeps its JSON escaping (`\/` or `/`).
     */
    public static function repoint_markup($markup, $repoints, $home)
    {
        $count = 0;
        $find = function ($value) use ($repoints, $home) {
            foreach ($repoints as $repoint) {
                if (self::same_address($value, $repoint['from'], $home)) {
                    return $repoint['to'];
                }
            }
            return null;
        };
        // The href of a link, in any quoting.
        $markup = preg_replace_callback('/(<a\b[^>]*?\shref\s*=\s*)(["\'])(.*?)\2/is', function ($match) use ($find, &$count) {
            $decoded = html_entity_decode($match[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $to = $find($decoded);
            if ($to === null) {
                return $match[0];
            }
            $count++;
            return $match[1] . $match[2] . htmlspecialchars(self::with_fragment($decoded, $to), ENT_QUOTES, 'UTF-8') . $match[2];
        }, strval($markup));
        // The url attribute of a block, in its comment's JSON.
        $markup = preg_replace_callback('/(<!--\s+wp:\S+\s+\{(?:(?!-->).)*?"url"\s*:\s*")((?:[^"\\\\]|\\\\.)*)(")/s', function ($match) use ($find, &$count) {
            $decoded = json_decode('"' . $match[2] . '"');
            if (!is_string($decoded)) {
                return $match[0];
            }
            $to = $find($decoded);
            if ($to === null) {
                return $match[0];
            }
            $count++;
            $encoded = substr(json_encode(self::with_fragment($decoded, $to), JSON_UNESCAPED_UNICODE | (strpos($match[2], '\\/') !== false ? 0 : JSON_UNESCAPED_SLASHES)), 1, -1);
            return $match[1] . $encoded . $match[3];
        }, $markup);
        return array('markup' => $markup, 'count' => $count);
    }

    /**
     * The repoints as Tamiro sends them, checked: each a `from` and an http(s) `to`.
     */
    public static function valid_repoints($repoints)
    {
        if (!is_array($repoints) || count($repoints) === 0) {
            return null;
        }
        $valid = array();
        foreach ($repoints as $repoint) {
            $from = is_array($repoint) && isset($repoint['from']) ? strval($repoint['from']) : '';
            $to = is_array($repoint) && isset($repoint['to']) ? strval($repoint['to']) : '';
            if ($from === '' || !preg_match('#^https?://#i', $to)) {
                return null;
            }
            $valid[] = array('from' => $from, 'to' => $to);
        }
        return $valid;
    }

    /**
     * `POST /links/repoint`: the places outside the articles' content whose links lead to one of the `from`,
     * rewritten. Answers each place changed, its kind, its id and its name.
     */
    public static function repoint($repoints)
    {
        $repoints = self::valid_repoints($repoints);
        if ($repoints === null) {
            return new WP_Error('invalid_repoints', __('Each repoint needs a from and an http(s) to', 'kaigen-connector'), array('status' => 400));
        }
        $user = get_current_user_id();
        if ($user && !user_can($user, 'edit_theme_options')) {
            return new WP_Error('forbidden', __('Editing menus and templates needs the right to edit the theme options', 'kaigen-connector'), array('status' => 403));
        }
        foreach ($repoints as $index => $repoint) {
            $repoints[$index]['to'] = esc_url_raw($repoint['to']);
        }
        $home = home_url('/');
        $places = array();
        $menus = array();

        // Menus: a custom item keeps its address in `_menu_item_url`; an item of a page or a term follows its object.
        $items = get_posts(array('post_type' => 'nav_menu_item', 'post_status' => 'any', 'numberposts' => -1, 'meta_key' => '_menu_item_type', 'meta_value' => 'custom', 'fields' => 'ids'));
        foreach ($items as $item_id) {
            $url = get_post_meta($item_id, '_menu_item_url', true);
            foreach ($repoints as $repoint) {
                if (!self::same_address($url, $repoint['from'], $home)) {
                    continue;
                }
                update_post_meta($item_id, '_menu_item_url', esc_url_raw(self::with_fragment($url, $repoint['to'])));
                $terms = wp_get_object_terms($item_id, 'nav_menu');
                $menu = !is_wp_error($terms) && !empty($terms) ? $terms[0] : null;
                if ($menu) {
                    $menus[$menu->term_id] = true;
                }
                $places[] = array('kind' => 'menu_item', 'id' => $item_id, 'label' => $menu ? $menu->name : get_the_title($item_id));
                break;
            }
        }
        // Tell the caches that purge on a menu's save.
        foreach (array_keys($menus) as $menu_id) {
            do_action('wp_update_nav_menu', $menu_id);
        }

        // Block navigations, template parts and synced patterns.
        $posts = get_posts(array('post_type' => self::MARKUP_TYPES, 'post_status' => array('publish', 'private'), 'numberposts' => -1));
        foreach ($posts as $post) {
            $result = self::repoint_markup($post->post_content, $repoints, $home);
            if ($result['count'] === 0) {
                continue;
            }
            $updated = wp_update_post(wp_slash(array('ID' => $post->ID, 'post_content' => $result['markup'])), true);
            if (is_wp_error($updated)) {
                continue;
            }
            $kinds = array('wp_navigation' => 'navigation', 'wp_template_part' => 'template_part', 'wp_block' => 'pattern');
            $places[] = array('kind' => $kinds[$post->post_type], 'id' => $post->ID, 'label' => $post->post_title);
        }

        // Widgets: a block widget's markup, a text or HTML widget's text.
        foreach (array('widget_block' => 'content', 'widget_text' => 'text', 'widget_custom_html' => 'content') as $option => $field) {
            $widgets = get_option($option);
            if (!is_array($widgets)) {
                continue;
            }
            $changed = false;
            foreach ($widgets as $number => $widget) {
                if (!is_array($widget) || !isset($widget[$field]) || !is_string($widget[$field])) {
                    continue;
                }
                $result = self::repoint_markup($widget[$field], $repoints, $home);
                if ($result['count'] === 0) {
                    continue;
                }
                $widgets[$number][$field] = $result['markup'];
                $changed = true;
                $places[] = array('kind' => 'widget', 'id' => $option . '-' . $number, 'label' => isset($widget['title']) && is_string($widget['title']) ? $widget['title'] : $option);
            }
            if ($changed) {
                update_option($option, $widgets);
            }
        }

        return array('places' => $places);
    }

    /**
     * The redirect managers active on the site (lot 2B1 of ADR 0212), read only: Yoast Premium, SEOPress PRO,
     * Rank Math with its redirections module, Redirection.
     */
    public static function redirect_managers()
    {
        $managers = array();
        if (defined('WPSEO_PREMIUM_VERSION') || defined('WPSEO_PREMIUM_FILE') || class_exists('WPSEO_Premium')) {
            $managers[] = 'yoast_premium';
        }
        if (defined('SEOPRESS_PRO_VERSION')) {
            $managers[] = 'seopress_pro';
        }
        if (class_exists('RankMath\\Helper') && method_exists('RankMath\\Helper', 'is_module_active') && call_user_func(array('RankMath\\Helper', 'is_module_active'), 'redirections')) {
            $managers[] = 'rank_math';
        }
        if (defined('REDIRECTION_VERSION') || class_exists('Red_Item')) {
            $managers[] = 'redirection';
        }
        return $managers;
    }
}
