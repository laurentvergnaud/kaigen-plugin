<?php
/**
 * The text zones of a Gutenberg page (plugin 1.7.0): its paragraphs, headings, lists and buttons, found by
 * their block comments with their byte offsets. A change replaces the inner HTML of the blocks it names
 * and inserts new blocks after an anchor; everything else is written back byte for byte, the blocks'
 * comments and attributes included. The content is never parsed into blocks and serialized again: the
 * serializer would rewrite attributes it reads otherwise. A section (a group, columns, a column, a cover, a
 * media and text) is duplicated whole, its bytes copied, every text of the copy rewritten, its links set,
 * the blocks it does not need dropped and its anchors removed, then placed among its siblings; its images
 * stay the source's until Tamiro generates its own. A section holding a block whose text Tamiro does not
 * read (HTML, shortcode, a shared block, a quote, a table, another plugin's block) is never duplicated.
 */

if (!defined('ABSPATH') && !defined('KAIGEN_ZONES_TESTS')) {
    exit;
}

class Kaigen_Zones_Blocks {

    // The blocks whose text is a zone, by role and the tag that wraps their text.
    private const TEXT_BLOCKS = array(
        'core/paragraph' => array('role' => 'paragraph', 'tag' => 'p'),
        'core/heading' => array('role' => 'heading', 'tag' => null),
        'core/list' => array('role' => 'list', 'tag' => null),
        'core/button' => array('role' => 'button', 'tag' => 'a'),
    );
    // Blocks shared between pages or filled by the site: never read as zones, nor anything inside them.
    private const SHARED = array('core/block', 'core/template-part', 'core/post-content', 'core/pattern', 'core/navigation');
    private const DELIMITER = '/<!--\s+(\/)?wp:([a-z][a-z0-9_-]*\/)?([a-z][a-z0-9_-]*)\s+(\{.*?\}\s+)?(\/)?-->/s';
    // The blocks that are sections, the images a section may hold (kept by its copy), and the blocks it may
    // hold besides them and its text; any other block makes it not duplicable.
    private const SECTION_BLOCKS = array('core/group', 'core/columns', 'core/column', 'core/cover', 'core/media-text');
    private const SECTION_MEDIA = array('core/image', 'core/gallery', 'core/video', 'core/embed');
    private const SECTION_FREE = array('core/buttons', 'core/spacer', 'core/separator', 'core/social-links', 'core/social-link', 'core/list-item');

    public static function read($content) {
        $tree = self::tree($content);
        $zones = array();
        self::collect($content, $tree, '', $zones);
        return array('zones' => array_map(array(__CLASS__, 'public_zone'), $zones), 'structures' => array(), 'sections' => self::sections_of($content, $tree, $zones));
    }

    /**
     * The content with the operations applied: `replace` (a zone, the hash of its HTML as read, the new
     * HTML) and `insert_after` (an anchor zone, the new blocks: a heading, paragraphs, a list). All or
     * nothing: one that cannot be applied throws, naming it.
     */
    public static function apply($content, $operations, &$report = null) {
        $report = array();
        $tree = self::tree($content);
        $zones = array();
        self::collect($content, $tree, '', $zones);
        $by_id = array();
        foreach ($zones as $zone) {
            $by_id[$zone['id']] = $zone;
        }
        $sections = null;
        $edits = array();
        foreach ($operations as $index => $operation) {
            $op = isset($operation['op']) ? strval($operation['op']) : '';
            if ($op === 'replace') {
                $zone = self::zone_of($by_id, $operation, 'zone');
                if ($zone['reason'] !== null) {
                    throw new Kaigen_Zones_Error('not_editable', sprintf('Zone %s is not rewritten: %s', $zone['id'], $zone['reason']), 422, array('zone' => $zone['id'], 'reason' => $zone['reason']));
                }
                if (!isset($operation['hash']) || strval($operation['hash']) !== $zone['hash']) {
                    throw new Kaigen_Zones_Error('zone_changed', sprintf('Zone %s changed since it was read', $zone['id']), 409, array('zone' => $zone['id']));
                }
                Kaigen_Zones_Text::assert_html($operation['html'] ?? '', $zone['role'], $zone['id']);
                $edits[] = array('start' => $zone['start'], 'end' => $zone['end'], 'text' => self::inner_for($zone, strval($operation['html'])), 'order' => $index);
            } elseif ($op === 'insert_after') {
                $zone = self::zone_of($by_id, $operation, 'anchor');
                $blocks = isset($operation['blocks']) && is_array($operation['blocks']) ? $operation['blocks'] : array();
                if (empty($blocks)) {
                    throw new Kaigen_Zones_Error('invalid_operation', sprintf('Operation %d: no block to insert', $index), 400);
                }
                $text = '';
                foreach ($blocks as $block) {
                    $text .= "\n\n" . self::serialize_new($block, $zone['id']);
                }
                $edits[] = array('start' => $zone['block_end'], 'end' => $zone['block_end'], 'text' => $text, 'order' => $index);
            } elseif ($op === 'duplicate_section') {
                if ($sections === null) {
                    $sections = array();
                    foreach (self::sections_of($content, $tree, $zones) as $section) {
                        $sections[$section['id']] = $section;
                    }
                }
                $copy = self::section_copy($content, $tree, $sections, $by_id, $operation, $index);
                $edits[] = array('start' => $copy['at'], 'end' => $copy['at'], 'text' => "\n\n" . $copy['text'], 'order' => $index);
                $report[] = array('section' => null, 'from' => $copy['from'], 'media' => array(), 'media_from' => $copy['media_from'], 'anchors_removed' => $copy['anchors_removed']);
            } else {
                throw new Kaigen_Zones_Error('invalid_operation', sprintf('Operation %d: %s is not an operation of a Gutenberg page', $index, $op ?: 'nothing'), 400);
            }
        }
        // From the end of the content to its start, so that each offset still points where it was read;
        // two insertions after the same anchor keep their order.
        usort($edits, function ($a, $b) {
            return $b['start'] - $a['start'] ?: $b['order'] - $a['order'];
        });
        $previous_start = PHP_INT_MAX;
        foreach ($edits as $edit) {
            if ($edit['end'] > $previous_start) {
                throw new Kaigen_Zones_Error('overlapping_operations', 'Two operations change the same part of the page', 400);
            }
            $content = substr($content, 0, $edit['start']) . $edit['text'] . substr($content, $edit['end']);
            $previous_start = $edit['start'];
        }
        return $content;
    }

    /**
     * The sections of the page: every group, columns, column, cover and media and text, each with its
     * zones, its images, its links, the blocks it holds directly, its shape, and whether Tamiro duplicates it.
     */
    private static function sections_of($content, $blocks, $zones, $prefix = '', $parent = null) {
        $sections = array();
        foreach ($blocks as $index => $block) {
            $path = $prefix === '' ? strval($index) : $prefix . '.' . $index;
            if (in_array($block['name'], self::SHARED, true)) {
                continue;
            }
            if (in_array($block['name'], self::SECTION_BLOCKS, true)) {
                $found = array('zones' => array(), 'media' => array(), 'links' => array(), 'blockers' => array(), 'shape' => array());
                self::analyse($content, $block, $path, $zones, $found, true);
                $reason = !empty($found['blockers']) ? $found['blockers'][0] : (empty($found['zones']) ? 'no_text' : null);
                $items = array();
                foreach ($block['children'] as $i => $child) {
                    $items[] = 'b:' . $path . '.' . $i;
                }
                $sections[] = array(
                    'id' => 'b:' . $path, 'element' => $path, 'parent' => $parent, 'zones' => $found['zones'], 'media' => $found['media'], 'links' => $found['links'],
                    'items' => $items, 'shape' => self::shape($found['shape']), 'duplicable' => $reason === null, 'reason' => $reason,
                );
            }
            foreach (self::sections_of($content, $block['children'], $zones, $path, 'b:' . $path) as $inner) {
                $sections[] = $inner;
            }
        }
        return $sections;
    }

    // What a block holds, its descendants included: zone ids, image slots, link slots, the reasons it cannot
    // be duplicated, the tokens of its shape.
    private static function analyse($content, $block, $path, $zones, &$found, $is_root = false) {
        $name = $block['name'];
        if (!$is_root) {
            if (isset(self::TEXT_BLOCKS[$name])) {
                foreach ($zones as $zone) {
                    if ($zone['id'] === 'b:' . $path) {
                        if ($zone['reason'] !== null) {
                            $found['blockers'][] = $zone['reason'] . ':' . $zone['id'];
                        }
                        $found['zones'][] = $zone['id'];
                        $found['shape'][] = $zone['role'] === 'heading' ? 'h' . $zone['level'] : $zone['role'];
                    }
                }
                if ($name === 'core/button' && preg_match('/<a\s[^>]*href="/', substr($content, $block['open_end'], $block['close_start'] - $block['open_end']))) {
                    $found['links'][] = 'l:b:' . $path;
                }
                return;
            }
            if (in_array($name, self::SECTION_MEDIA, true)) {
                $found['media'][] = 'm:b:' . $path;
                $found['shape'][] = substr($name, 5);
                return;
            }
            if (!in_array($name, self::SECTION_BLOCKS, true) && !in_array($name, self::SECTION_FREE, true)) {
                $found['blockers'][] = 'block:' . $name;
                return;
            }
        }
        if ($name === 'core/cover' && (!empty($block['attrs']['url']) || !empty($block['attrs']['id']))) {
            $found['media'][] = 'm:b:' . $path;
            $found['shape'][] = 'background';
        }
        if ($name === 'core/media-text' && (!empty($block['attrs']['mediaUrl']) || !empty($block['attrs']['mediaId']))) {
            $found['media'][] = 'm:b:' . $path;
            $found['shape'][] = 'image';
        }
        foreach ($block['children'] as $i => $child) {
            self::analyse($content, $child, $path . '.' . $i, $zones, $found);
        }
    }

    private static function shape($tokens) {
        $parts = array();
        foreach ($tokens as $token) {
            $last = count($parts) - 1;
            if ($last >= 0 && $parts[$last][0] === $token) {
                $parts[$last][1]++;
            } else {
                $parts[] = array($token, 1);
            }
        }
        return implode(' · ', array_map(function ($part) { return $part[1] > 1 ? $part[0] . ' ×' . $part[1] : $part[0]; }, $parts));
    }

    private static function block_at($blocks, $path) {
        $block = null;
        foreach (explode('.', $path) as $index) {
            if (!isset($blocks[intval($index)])) {
                return null;
            }
            $block = $blocks[intval($index)];
            $blocks = $block['children'];
        }
        return $block;
    }

    /**
     * A section's copy: its bytes, the blocks it drops removed, a new text for every zone it keeps and none
     * other (never the source's), its links set, its anchors removed; placed after a sibling of the section.
     */
    private static function section_copy($content, $tree, $sections, $zones, $operation, $index) {
        $id = isset($operation['section']) ? strval($operation['section']) : '';
        if (!isset($sections[$id])) {
            throw new Kaigen_Zones_Error('unknown_section', sprintf('Section %s is not a section of this page', $id ?: '?'), 422, array('section' => $id));
        }
        $section = $sections[$id];
        if (!$section['duplicable']) {
            throw new Kaigen_Zones_Error('not_duplicable', sprintf('Section %s is not duplicated: %s', $id, $section['reason']), 422, array('section' => $id, 'reason' => $section['reason']));
        }
        $path = $section['element'];
        $block = self::block_at($tree, $path);
        $base = $block['open_start'];
        $edits = array();
        $removed = array();
        foreach (isset($operation['remove']) && is_array($operation['remove']) ? $operation['remove'] : array() as $remove) {
            $inner = preg_replace('/^b:/', '', strval($remove));
            if (strpos($inner, $path . '.') !== 0 || ($dropped = self::block_at($tree, $inner)) === null) {
                throw new Kaigen_Zones_Error('invalid_operation', sprintf('Operation %d: %s is not inside section %s', $index, $remove, $id), 400);
            }
            $removed[$inner] = $dropped;
        }
        // A block inside another the copy drops goes with it.
        foreach ($removed as $inner => $dropped) {
            foreach (array_keys($removed) as $other) {
                if ($other !== $inner && strpos($inner, $other . '.') === 0) {
                    continue 2;
                }
            }
            $edits[] = array('start' => $dropped['open_start'] - $base, 'end' => $dropped['close_end'] - $base, 'text' => '');
        }
        $removed = array_keys($removed);
        $is_removed = function ($zone_id) use ($removed) {
            foreach ($removed as $inner) {
                if ($zone_id === 'b:' . $inner || strpos($zone_id, 'b:' . $inner . '.') === 0) {
                    return true;
                }
            }
            return false;
        };
        $kept = array_values(array_filter($section['zones'], function ($zone_id) use ($is_removed) { return !$is_removed($zone_id); }));
        if (empty($kept)) {
            throw new Kaigen_Zones_Error('invalid_operation', sprintf('Operation %d: the copy of %s would hold no text', $index, $id), 400);
        }
        // The page keeps one H1: a copy holding the source's is refused, its element removed from the copy or
        // another section chosen (seventh rule, Laurent, 2026-10-16).
        foreach ($kept as $zone_id) {
            if ($zones[$zone_id]['role'] === 'heading' && $zones[$zone_id]['level'] === 1) {
                throw new Kaigen_Zones_Error('copy_keeps_h1', sprintf('Operation %d: the copy of %s keeps the H1 %s; remove it from the copy or duplicate another section', $index, $id, $zone_id), 422, array('section' => $id, 'zone' => $zone_id));
            }
        }
        $texts = isset($operation['texts']) && is_array($operation['texts']) ? $operation['texts'] : array();
        $missing = array_values(array_diff($kept, array_keys($texts)));
        if (!empty($missing)) {
            throw new Kaigen_Zones_Error('missing_text', sprintf('Operation %d: every text of the copy is rewritten, %s missing', $index, implode(', ', $missing)), 400, array('zones' => $missing));
        }
        $extra = array_values(array_diff(array_keys($texts), $kept));
        if (!empty($extra)) {
            throw new Kaigen_Zones_Error('unknown_text', sprintf('Operation %d: %s is not a text the copy keeps', $index, implode(', ', $extra)), 400, array('zones' => $extra));
        }
        foreach ($kept as $zone_id) {
            $zone = $zones[$zone_id];
            $html = strval($texts[$zone_id]);
            Kaigen_Zones_Text::assert_html($html, $zone['role'], $zone_id . ' (copy)');
            if (Kaigen_Zones_Text::plain($html) === Kaigen_Zones_Text::plain($zone['html'])) {
                throw new Kaigen_Zones_Error('unchanged_text', sprintf('Operation %d: the copy of %s repeats its text', $index, $zone_id), 400, array('zone' => $zone_id));
            }
            $edits[] = array('start' => $zone['start'] - $base, 'end' => $zone['end'] - $base, 'text' => self::inner_for($zone, $html));
        }
        foreach (isset($operation['links']) && is_array($operation['links']) ? $operation['links'] : array() as $slot => $url) {
            if (!in_array($slot, $section['links'], true) || $is_removed(substr($slot, 2))) {
                throw new Kaigen_Zones_Error('unknown_link', sprintf('Operation %d: %s is not a link the copy keeps', $index, $slot), 400);
            }
            if (!preg_match('#^(https?://[^\s"<>]+|/[^\s"<>]*|\#[\w-]+)$#i', strval($url))) {
                throw new Kaigen_Zones_Error('invalid_link', sprintf('Operation %d: %s is not an address', $index, strval($url)), 400);
            }
            $button = self::block_at($tree, substr($slot, 4));
            $inside = substr($content, $button['open_end'], $button['close_start'] - $button['open_end']);
            preg_match('/<a\s[^>]*href="([^"]*)"/', $inside, $found, PREG_OFFSET_CAPTURE);
            $at = $button['open_end'] + $found[1][1] - $base;
            $edits[] = array('start' => $at, 'end' => $at + strlen($found[1][0]), 'text' => htmlspecialchars(strval($url), ENT_QUOTES));
        }
        $text = substr($content, $base, $block['close_end'] - $base);
        usort($edits, function ($a, $b) { return $b['start'] - $a['start']; });
        $previous = PHP_INT_MAX;
        foreach ($edits as $edit) {
            // A zone or a link inside a block the copy drops goes with it.
            if ($edit['end'] > $previous) {
                continue;
            }
            $text = substr($text, 0, $edit['start']) . $edit['text'] . substr($text, $edit['end']);
            $previous = $edit['start'];
        }
        // An anchor names one element of the page: the copy's are removed.
        $text = preg_replace('/(<[a-zA-Z][a-zA-Z0-9-]*\b[^>]*?)\s+id="[^"]*"/', '$1', $text, -1, $anchors);
        $after = isset($operation['after']) && $operation['after'] !== '' ? preg_replace('/^b:/', '', strval($operation['after'])) : $path;
        $parent_of = function ($p) { $cut = strrpos($p, '.'); return $cut === false ? '' : substr($p, 0, $cut); };
        $anchor = self::block_at($tree, $after);
        if ($anchor === null || $parent_of($after) !== $parent_of($path)) {
            throw new Kaigen_Zones_Error('invalid_placement', sprintf('Operation %d: the copy of %s goes among its siblings, %s is not one', $index, $id, $after), 400);
        }
        $media_from = array_values(array_filter($section['media'], function ($slot) use ($is_removed) { return !$is_removed(substr($slot, 2)); }));
        return array('text' => $text, 'at' => $anchor['close_end'], 'from' => $id, 'media_from' => $media_from, 'anchors_removed' => $anchors);
    }

    private static function zone_of($by_id, $operation, $key) {
        $id = isset($operation[$key]) ? strval($operation[$key]) : '';
        if (!isset($by_id[$id])) {
            throw new Kaigen_Zones_Error('unknown_zone', sprintf('Zone %s is not a zone of this page', $id ?: '?'), 422, array('zone' => $id));
        }
        return $by_id[$id];
    }

    // The blocks of the content, each with its offsets: where its opening comment starts and ends, and
    // where its closing comment starts and ends; a void block has no inside.
    private static function tree($content) {
        $root = array('children' => array());
        $stack = array();
        $offset = 0;
        while (preg_match(self::DELIMITER, $content, $match, PREG_OFFSET_CAPTURE, $offset)) {
            $start = $match[0][1];
            $end = $start + strlen($match[0][0]);
            $name = ($match[2][0] !== '' ? $match[2][0] : 'core/') . $match[3][0];
            $closer = $match[1][0] === '/';
            $void = isset($match[5]) && $match[5][0] === '/';
            if ($closer) {
                $block = array_pop($stack);
                if ($block === null || $block['name'] !== $name) {
                    throw new Kaigen_Zones_Error('unreadable_blocks', 'The blocks of the page are not balanced', 422);
                }
                $block['close_start'] = $start;
                $block['close_end'] = $end;
                self::attach($root, $stack, $block);
            } else {
                $attrs = isset($match[4]) && $match[4][0] !== '' ? json_decode(trim($match[4][0]), true) : array();
                $block = array('name' => $name, 'attrs' => is_array($attrs) ? $attrs : array(), 'open_start' => $start, 'open_end' => $end, 'children' => array());
                if ($void) {
                    $block['close_start'] = $end;
                    $block['close_end'] = $end;
                    self::attach($root, $stack, $block);
                } else {
                    $stack[] = $block;
                }
            }
            $offset = $end;
        }
        if (!empty($stack)) {
            throw new Kaigen_Zones_Error('unreadable_blocks', 'A block of the page is not closed', 422);
        }
        return $root['children'];
    }

    private static function attach(&$root, &$stack, $block) {
        if (empty($stack)) {
            $root['children'][] = $block;
        } else {
            $stack[count($stack) - 1]['children'][] = $block;
        }
    }

    private static function collect($content, $blocks, $prefix, &$zones) {
        foreach ($blocks as $index => $block) {
            $path = $prefix === '' ? strval($index) : $prefix . '.' . $index;
            if (in_array($block['name'], self::SHARED, true)) {
                continue;
            }
            if (isset(self::TEXT_BLOCKS[$block['name']])) {
                $zone = self::text_zone($content, $block, $path);
                if ($zone !== null) {
                    $zones[] = $zone;
                }
                continue;
            }
            self::collect($content, $block['children'], $path, $zones);
        }
    }

    // A text block's zone: its inner HTML between its wrapping tag, or null when the block holds none.
    private static function text_zone($content, $block, $path) {
        $spec = self::TEXT_BLOCKS[$block['name']];
        $inside = substr($content, $block['open_end'], $block['close_start'] - $block['open_end']);
        $level = null;
        if ($spec['role'] === 'heading') {
            $level = isset($block['attrs']['level']) ? intval($block['attrs']['level']) : 2;
            $tag = 'h' . $level;
        } elseif ($spec['role'] === 'list') {
            $tag = !empty($block['attrs']['ordered']) ? 'ol' : 'ul';
        } else {
            $tag = $spec['tag'];
        }
        $range = self::wrapped($inside, $tag);
        if ($range === null) {
            return null;
        }
        $start = $block['open_end'] + $range[0];
        $end = $block['open_end'] + $range[1];
        $raw = substr($content, $start, $end - $start);
        // A list's items are blocks of their own since WordPress 6.1: Tamiro reads and writes them as
        // `<li>` elements, their comments kept out of its HTML.
        $html = $spec['role'] === 'list' ? trim(preg_replace('/\s*<!--\s+\/?wp:list-item\s+(\{.*?\}\s+)?-->\s*/s', '', $raw)) : $raw;
        $reason = Kaigen_Zones_Text::lock_reason($html, $spec['role']);
        return array(
            'id' => 'b:' . $path, 'role' => $spec['role'], 'level' => $level, 'html' => $html, 'hash' => Kaigen_Zones_Text::hash($html), 'reason' => $reason,
            'start' => $start, 'end' => $end, 'block_end' => $block['close_end'],
            'item_blocks' => $spec['role'] === 'list' && !empty($block['children']),
        );
    }

    // Where the text inside the first element of this tag starts and ends, relative to the block's inside.
    private static function wrapped($inside, $tag) {
        if (!preg_match('/<' . $tag . '(\s[^>]*)?>/i', $inside, $open, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $from = $open[0][1] + strlen($open[0][0]);
        $close = strripos($inside, '</' . $tag . '>');
        if ($close === false || $close < $from) {
            return null;
        }
        return array($from, $close);
    }

    // The inner HTML written for a zone: as given, or a list's items each as a list-item block when the
    // list holds them as blocks.
    private static function inner_for($zone, $html) {
        if ($zone['role'] !== 'list') {
            return $html;
        }
        $items = self::items($html, $zone['id']);
        if (!$zone['item_blocks']) {
            return implode('', array_map(function ($item) { return '<li>' . $item . '</li>'; }, $items));
        }
        return implode('', array_map(function ($item) { return "<!-- wp:list-item -->\n<li>" . $item . "</li>\n<!-- /wp:list-item -->"; }, $items));
    }

    // The items of a list's HTML, each `<li>` at its top, never a list inside an item.
    private static function items($html, $zone_id) {
        if (preg_match('/<(ul|ol)[\s>]/i', $html)) {
            throw new Kaigen_Zones_Error('invalid_html', sprintf('Zone %s: a list inside a list item is not written', $zone_id));
        }
        if (!preg_match_all('/<li(\s[^>]*)?>(.*?)<\/li>/is', $html, $matches) || trim(preg_replace('/<li(\s[^>]*)?>.*?<\/li>/is', '', $html)) !== '') {
            throw new Kaigen_Zones_Error('invalid_html', sprintf('Zone %s: a list is written as <li> items only', $zone_id));
        }
        return $matches[2];
    }

    // A new block inserted after an anchor: a heading (its level), a paragraph, or a list.
    private static function serialize_new($block, $anchor_id) {
        $role = isset($block['role']) ? strval($block['role']) : '';
        $html = isset($block['html']) ? strval($block['html']) : '';
        $label = 'new block after ' . $anchor_id;
        if ($role === 'heading') {
            $level = isset($block['level']) ? intval($block['level']) : 2;
            if ($level < 2 || $level > 6) {
                throw new Kaigen_Zones_Error('invalid_operation', sprintf('%s: a heading is of level 2 to 6', $label), 400);
            }
            Kaigen_Zones_Text::assert_html($html, 'heading', $label);
            $attrs = $level === 2 ? '' : ' {"level":' . $level . '}';
            return '<!-- wp:heading' . $attrs . " -->\n<h" . $level . ' class="wp-block-heading">' . $html . '</h' . $level . ">\n<!-- /wp:heading -->";
        }
        if ($role === 'paragraph') {
            Kaigen_Zones_Text::assert_html($html, 'paragraph', $label);
            return "<!-- wp:paragraph -->\n<p>" . $html . "</p>\n<!-- /wp:paragraph -->";
        }
        if ($role === 'list') {
            Kaigen_Zones_Text::assert_html($html, 'list', $label);
            $items = implode('', array_map(function ($item) { return "<!-- wp:list-item -->\n<li>" . $item . "</li>\n<!-- /wp:list-item -->"; }, self::items($html, $label)));
            return "<!-- wp:list -->\n<ul class=\"wp-block-list\">" . $items . "</ul>\n<!-- /wp:list -->";
        }
        throw new Kaigen_Zones_Error('invalid_operation', sprintf('%s: a heading, a paragraph or a list', $label), 400);
    }

    public static function public_zone($zone) {
        return array('id' => $zone['id'], 'role' => $zone['role'], 'level' => $zone['level'], 'html' => $zone['html'], 'text' => Kaigen_Zones_Text::plain($zone['html']), 'hash' => $zone['hash'], 'editable' => $zone['reason'] === null, 'reason' => $zone['reason'], 'group' => null);
    }
}
