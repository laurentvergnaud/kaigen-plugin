<?php
/**
 * The text zones of a page written in the classic editor (plugin 1.7.0): the paragraphs, headings and
 * lists at the top of its HTML. A classic post often holds its paragraphs as plain text separated by blank
 * lines, which WordPress wraps in <p> on display: a run of text between two elements is cut at its blank
 * lines into paragraphs. Any other element (a div, a table, an image, an embed) is never a zone and is
 * written back byte for byte, as is everything outside the zones changed.
 */

if (!defined('ABSPATH') && !defined('KAIGEN_ZONES_TESTS')) {
    exit;
}

class Kaigen_Zones_Html {

    private const ELEMENTS = array('p' => 'paragraph', 'h1' => 'heading', 'h2' => 'heading', 'h3' => 'heading', 'h4' => 'heading', 'h5' => 'heading', 'h6' => 'heading', 'ul' => 'list', 'ol' => 'list');
    // The tags a run of text may hold and stay a run: inline formatting and links.
    private const INLINE = array('a', 'strong', 'b', 'em', 'i', 'br', 'span', 'sup', 'sub', 'code', 'mark', 'small', 'u', 's', 'abbr');
    private const VOID = array('br', 'img', 'hr', 'input', 'meta', 'link', 'source', 'wbr', 'col', 'embed', 'param', 'track', 'area');

    public static function read($content) {
        return array('zones' => array_map(array('Kaigen_Zones_Blocks', 'public_zone'), self::zones($content)), 'structures' => array(), 'sections' => array());
    }

    public static function apply($content, $operations) {
        $zones = self::zones($content);
        $by_id = array();
        foreach ($zones as $zone) {
            $by_id[$zone['id']] = $zone;
        }
        $with_p = (bool) preg_match('/<p[\s>]/i', $content);
        $edits = array();
        foreach ($operations as $index => $operation) {
            $op = isset($operation['op']) ? strval($operation['op']) : '';
            $key = $op === 'insert_after' ? 'anchor' : 'zone';
            $id = isset($operation[$key]) ? strval($operation[$key]) : '';
            if (!isset($by_id[$id])) {
                throw new Kaigen_Zones_Error('unknown_zone', sprintf('Zone %s is not a zone of this page', $id ?: '?'), 422, array('zone' => $id));
            }
            $zone = $by_id[$id];
            if ($op === 'replace') {
                if ($zone['reason'] !== null) {
                    throw new Kaigen_Zones_Error('not_editable', sprintf('Zone %s is not rewritten: %s', $id, $zone['reason']), 422, array('zone' => $id, 'reason' => $zone['reason']));
                }
                if (!isset($operation['hash']) || strval($operation['hash']) !== $zone['hash']) {
                    throw new Kaigen_Zones_Error('zone_changed', sprintf('Zone %s changed since it was read', $id), 409, array('zone' => $id));
                }
                $html = strval($operation['html'] ?? '');
                Kaigen_Zones_Text::assert_html($html, $zone['role'], $id);
                if ($zone['role'] === 'paragraph' && $zone['bare'] && preg_match('/\n\s*\n/', $html)) {
                    throw new Kaigen_Zones_Error('invalid_html', sprintf('Zone %s: one paragraph, without a blank line', $id));
                }
                $edits[] = array('start' => $zone['start'], 'end' => $zone['end'], 'text' => $html, 'order' => $index);
            } elseif ($op === 'insert_after') {
                $blocks = isset($operation['blocks']) && is_array($operation['blocks']) ? $operation['blocks'] : array();
                if (empty($blocks)) {
                    throw new Kaigen_Zones_Error('invalid_operation', sprintf('Operation %d: no block to insert', $index), 400);
                }
                $text = '';
                foreach ($blocks as $block) {
                    $text .= "\n\n" . self::serialize_new($block, $id, $with_p);
                }
                $edits[] = array('start' => $zone['element_end'], 'end' => $zone['element_end'], 'text' => $text, 'order' => $index);
            } else {
                throw new Kaigen_Zones_Error('invalid_operation', sprintf('Operation %d: %s is not an operation of a classic page', $index, $op ?: 'nothing'), 400);
            }
        }
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

    // The zones in the order of the page, each with the offsets of its text and of its element's end.
    private static function zones($content) {
        $zones = array();
        $parts = preg_split('/(<!--.*?-->|<[^>]*>)/s', $content, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_OFFSET_CAPTURE);
        $depth = 0;
        $element = null;
        $run = null;
        foreach ($parts as $part) {
            list($token, $offset) = $part;
            if ($token === '') {
                continue;
            }
            $tag = self::tag($token);
            if ($depth === 0 && $element === null) {
                $inline = $tag === null ? $token[0] !== '<' : in_array($tag['name'], self::INLINE, true);
                if ($inline) {
                    if ($run === null) {
                        $run = array('start' => $offset);
                    }
                    $run['end'] = $offset + strlen($token);
                    continue;
                }
                if ($run !== null) {
                    self::cut_run($content, $run, $zones);
                    $run = null;
                }
                if ($tag !== null && !$tag['closing'] && isset(self::ELEMENTS[$tag['name']]) && !$tag['self_closing']) {
                    $element = array('name' => $tag['name'], 'inner_start' => $offset + strlen($token), 'depth' => 0);
                    continue;
                }
            }
            if ($tag === null || $tag['self_closing'] || in_array($tag['name'], self::VOID, true)) {
                continue;
            }
            if ($element !== null && $tag['name'] === $element['name']) {
                if ($tag['closing']) {
                    if ($element['depth'] === 0) {
                        self::element_zone($content, $element, $offset, $offset + strlen($token), $zones);
                        $element = null;
                    } else {
                        $element['depth']--;
                    }
                } else {
                    $element['depth']++;
                }
                continue;
            }
            if ($element === null) {
                $depth += $tag['closing'] ? -1 : 1;
                $depth = max(0, $depth);
            }
        }
        if ($run !== null) {
            self::cut_run($content, $run, $zones);
        }
        foreach ($zones as $index => &$zone) {
            $zone['id'] = 'c:' . $index;
        }
        return $zones;
    }

    // A run of text cut at its blank lines into paragraphs, each trimmed; a run of spaces is none.
    private static function cut_run($content, $run, &$zones) {
        $text = substr($content, $run['start'], $run['end'] - $run['start']);
        $pieces = preg_split('/(\n[ \t]*\n\s*)/', $text, -1, PREG_SPLIT_OFFSET_CAPTURE);
        foreach ($pieces as $piece) {
            list($chunk, $at) = $piece;
            $left = strlen($chunk) - strlen(ltrim($chunk));
            $trimmed = trim($chunk);
            if ($trimmed === '') {
                continue;
            }
            $start = $run['start'] + $at + $left;
            $end = $start + strlen($trimmed);
            $zones[] = self::zone('paragraph', null, $trimmed, $start, $end, $end, true);
        }
    }

    private static function element_zone($content, $element, $close_start, $close_end, &$zones) {
        $role = self::ELEMENTS[$element['name']];
        $level = $role === 'heading' ? intval(substr($element['name'], 1)) : null;
        $html = substr($content, $element['inner_start'], $close_start - $element['inner_start']);
        if (trim($html) === '') {
            return;
        }
        $zones[] = self::zone($role, $level, $html, $element['inner_start'], $close_start, $close_end, false);
    }

    private static function zone($role, $level, $html, $start, $end, $element_end, $bare) {
        return array('id' => '', 'role' => $role, 'level' => $level, 'html' => $html, 'hash' => Kaigen_Zones_Text::hash($html), 'reason' => Kaigen_Zones_Text::lock_reason($html, $role), 'start' => $start, 'end' => $end, 'element_end' => $element_end, 'bare' => $bare);
    }

    private static function tag($token) {
        if ($token[0] !== '<' || strpos($token, '<!--') === 0) {
            return $token[0] === '<' ? array('name' => '!--', 'closing' => false, 'self_closing' => true) : null;
        }
        if (!preg_match('/^<\s*(\/)?\s*([a-zA-Z][a-zA-Z0-9-]*)/', $token, $matches)) {
            return null;
        }
        return array('name' => strtolower($matches[2]), 'closing' => $matches[1] === '/', 'self_closing' => substr(rtrim($token, " \t\n\r>"), -1) === '/');
    }

    // A new element after an anchor: written with <p> when the page writes its paragraphs so, else as a
    // paragraph of plain text WordPress wraps on display.
    private static function serialize_new($block, $anchor_id, $with_p) {
        $role = isset($block['role']) ? strval($block['role']) : '';
        $html = isset($block['html']) ? strval($block['html']) : '';
        $label = 'new block after ' . $anchor_id;
        if ($role === 'heading') {
            $level = isset($block['level']) ? intval($block['level']) : 2;
            if ($level < 2 || $level > 6) {
                throw new Kaigen_Zones_Error('invalid_operation', sprintf('%s: a heading is of level 2 to 6', $label), 400);
            }
            Kaigen_Zones_Text::assert_html($html, 'heading', $label);
            return '<h' . $level . '>' . $html . '</h' . $level . '>';
        }
        if ($role === 'paragraph') {
            Kaigen_Zones_Text::assert_html($html, 'paragraph', $label);
            return $with_p ? '<p>' . $html . '</p>' : $html;
        }
        if ($role === 'list') {
            Kaigen_Zones_Text::assert_html($html, 'list', $label);
            return '<ul>' . $html . '</ul>';
        }
        throw new Kaigen_Zones_Error('invalid_operation', sprintf('%s: a heading, a paragraph or a list', $label), 400);
    }
}
