<?php
/**
 * The text zones of a page (plugin 1.7.0, lot 11 of the automations refonte): what Tamiro may read and
 * rewrite of a page, whatever its editor. Pure functions shared by the editors' adapters: no WordPress
 * call, so that they run in the tests without WordPress.
 */

if (!defined('ABSPATH') && !defined('KAIGEN_ZONES_TESTS')) {
    exit;
}

// A request on the zones that cannot be applied: its code, the HTTP status the route answers, and what
// it names (the zones changed since they were read, the zone unknown).
class Kaigen_Zones_Error extends Exception {
    public $error_code;
    public $status;
    public $details;

    public function __construct($error_code, $message, $status = 422, $details = array()) {
        parent::__construct($message);
        $this->error_code = $error_code;
        $this->status = $status;
        $this->details = $details;
    }
}

class Kaigen_Zones_Text {

    // The roles of a zone, and the tags Tamiro's HTML may hold for each: inline only in a heading, a
    // button, a question, a paragraph and a list item; a few blocks in a free text and an answer; items
    // in a list.
    private const INLINE = array('a', 'strong', 'b', 'em', 'i', 'br', 'span', 'sup', 'sub', 'code', 'mark', 'small');
    private const BLOCKS = array('p', 'ul', 'ol', 'li', 'blockquote', 'h3', 'h4', 'h5', 'h6');
    public const ROLES = array('heading', 'paragraph', 'list', 'list_item', 'button', 'question', 'answer', 'text');
    private const ATTRIBUTES = array('href', 'title', 'target', 'rel', 'class', 'id');

    public static function hash($html) {
        return sha1(strval($html));
    }

    // The text a reader sees: tags removed, entities decoded, spaces collapsed.
    public static function plain($html) {
        $text = html_entity_decode(strip_tags(str_replace(array('<br>', '<br/>', '<br />', '</p>', '</li>'), ' ', strval($html))), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $text));
    }

    /**
     * Why a zone read on the page is not Tamiro's to rewrite, or null: it holds what a rewrite would lose,
     * an element its role does not take (an image, an embed, a nested list), or a shortcode.
     */
    public static function lock_reason($html, $role) {
        $html = strval($html);
        if (preg_match('/\[[a-zA-Z][\w-]*(\s[^\]]*)?\]/', $html)) {
            return 'shortcode';
        }
        if (preg_match('/<!--/', $html)) {
            return 'embedded';
        }
        $allowed = self::tags_for($role);
        if (preg_match_all('/<\s*\/?\s*([a-zA-Z][a-zA-Z0-9]*)/', $html, $matches)) {
            foreach ($matches[1] as $name) {
                if (!in_array(strtolower($name), $allowed, true)) {
                    return 'embedded';
                }
            }
        }
        if ($role === 'list' && preg_match('/<(ul|ol)[\s>]/i', $html)) {
            return 'nested';
        }
        return null;
    }

    public static function tags_for($role) {
        if ($role === 'list') {
            return array_merge(self::INLINE, array('li'));
        }
        if ($role === 'text' || $role === 'answer') {
            return array_merge(self::INLINE, self::BLOCKS);
        }
        return self::INLINE;
    }

    /**
     * Tamiro's HTML for a zone of this role: only the role's tags, with plain attributes (no event, no
     * style, no script address), balanced. Throws on anything else, naming the zone.
     */
    public static function assert_html($html, $role, $zone_id) {
        $html = strval($html);
        if (trim(self::plain($html)) === '') {
            throw new Kaigen_Zones_Error('empty_html', sprintf('Zone %s: the new text is empty', $zone_id));
        }
        $allowed = self::tags_for($role);
        $open = array();
        if (!preg_match_all('/<[^>]*>/', $html, $matches)) {
            return;
        }
        foreach ($matches[0] as $tag) {
            if (strpos($tag, '<!--') === 0) {
                throw new Kaigen_Zones_Error('invalid_html', sprintf('Zone %s: a comment is not allowed', $zone_id));
            }
            if (!preg_match('/^<\s*(\/)?\s*([a-zA-Z][a-zA-Z0-9]*)([^>]*)>$/', $tag, $parts)) {
                throw new Kaigen_Zones_Error('invalid_html', sprintf('Zone %s: unreadable tag %s', $zone_id, $tag));
            }
            $name = strtolower($parts[2]);
            if (!in_array($name, $allowed, true)) {
                throw new Kaigen_Zones_Error('invalid_html', sprintf('Zone %s: the tag %s is not allowed in a zone of role %s', $zone_id, $name, $role));
            }
            if ($parts[1] === '/') {
                if (empty($open) || array_pop($open) !== $name) {
                    throw new Kaigen_Zones_Error('invalid_html', sprintf('Zone %s: unbalanced tag %s', $zone_id, $name));
                }
                continue;
            }
            self::assert_attributes($parts[3], $zone_id);
            if ($name !== 'br' && substr(rtrim($parts[3]), -1) !== '/') {
                $open[] = $name;
            }
        }
        if (!empty($open)) {
            throw new Kaigen_Zones_Error('invalid_html', sprintf('Zone %s: tag %s left open', $zone_id, end($open)));
        }
    }

    private static function assert_attributes($attributes, $zone_id) {
        if (!preg_match_all('/([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*(?:=\s*("[^"]*"|\'[^\']*\'|[^\s>]+))?/', $attributes, $found, PREG_SET_ORDER)) {
            return;
        }
        foreach ($found as $attribute) {
            $name = strtolower($attribute[1]);
            if ($name === '/') {
                continue;
            }
            if (!in_array($name, self::ATTRIBUTES, true)) {
                throw new Kaigen_Zones_Error('invalid_html', sprintf('Zone %s: the attribute %s is not allowed', $zone_id, $name));
            }
            $value = isset($attribute[2]) ? trim($attribute[2], '"\'') : '';
            if ($name === 'href' && preg_match('/^\s*(javascript|data|vbscript):/i', $value)) {
                throw new Kaigen_Zones_Error('invalid_html', sprintf('Zone %s: a link to a script is not allowed', $zone_id));
            }
        }
    }
}
