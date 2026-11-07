<?php
/**
 * Fragment edits of a post's content (ADR 0064, plugin 1.4.0): a link placed on words that already
 * exist, an H1 of the content demoted to H2 or removed; since 1.11.0 (ADR 0189) the address of a link
 * that leads to a redirect replaced by the address it ends on. The content is never rebuilt: it is cut into
 * its tags and texts, the one fragment is changed, and everything else is written back byte for byte,
 * Gutenberg's block comments included.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Kaigen_Content_Edits {

    // Where a link may never be placed: inside another link, a heading, code, a script, a button.
    private const FORBIDDEN = array('a', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'code', 'pre', 'script', 'style', 'button', 'figcaption', 'select', 'textarea');
    // The blocks whose text may carry a link: a paragraph, a list item.
    private const CONTAINERS = array('p', 'li');
    private const VOID = array('br', 'img', 'hr', 'input', 'meta', 'link', 'source', 'wbr', 'col', 'embed', 'param', 'track', 'area');
    // The inline formatting a link may wrap: the words may cross these tags when each one is opened
    // and closed within them; any other tag, or one half of a pair, refuses the placement.
    private const INLINE = array('span', 'strong', 'em', 'b', 'i', 'u', 's', 'sup', 'sub', 'mark', 'small', 'abbr', 'del', 'ins', 'time', 'bdi', 'bdo');
    // The void tags the words may cross: they render no text and need no closing.
    private const INLINE_VOID = array('br', 'wbr');

    /**
     * Places each link on its words, in the paragraph or list item whose text holds its sentence.
     * All or nothing: a link that cannot be placed refuses the whole edit, naming it.
     */
    public static function apply_links($content, $links) {
        foreach ($links as $index => $link) {
            $text = isset($link['text']) ? strval($link['text']) : '';
            $sentence = isset($link['sentence']) ? strval($link['sentence']) : '';
            $href = isset($link['href']) ? esc_url_raw(strval($link['href'])) : '';
            if ($text === '' || $sentence === '' || $href === '' || strpos(self::comparable($sentence), self::comparable($text)) === false) {
                return new WP_Error('invalid_link', sprintf(__('Link %d: text, sentence and href are required, the text inside the sentence', 'kaigen-connector'), $index), array('status' => 400));
            }
            $placed = self::place_link($content, $text, $sentence, $href);
            if ($placed === null) {
                return new WP_Error('not_in_content', sprintf(__('Link %d: its sentence or its words are not in a paragraph or a list of the content', 'kaigen-connector'), $index), array('status' => 422));
            }
            $content = $placed;
        }
        return $content;
    }

    /**
     * Replaces the address of the links that lead to a redirect (1.11.0, ADR 0189): each `from` is an href
     * as the content holds it once its entities are decoded, `to` the address it ends on. Only the value of
     * the `href` attribute of an `a` tag changes; the tag's other attributes, its text and the rest of the
     * content are written back byte for byte. All or nothing: an address the content does not hold refuses
     * the whole edit, naming it.
     */
    public static function apply_repoints($content, $repoints) {
        $tokens = self::tokens($content);
        $done = array_fill(0, count($repoints), 0);
        foreach ($repoints as $index => $repoint) {
            $from = isset($repoint['from']) ? strval($repoint['from']) : '';
            $to = isset($repoint['to']) ? esc_url_raw(strval($repoint['to'])) : '';
            if ($from === '' || $to === '' || !preg_match('#^https?://#i', $to)) {
                return new WP_Error('invalid_repoint', sprintf(__('Repoint %d: from and an http(s) to are required', 'kaigen-connector'), $index), array('status' => 400));
            }
        }
        foreach ($tokens as $position => $token) {
            if (!self::is_tag($token)) {
                continue;
            }
            $info = self::tag_info($token);
            if ($info === null || $info['closing'] || $info['name'] !== 'a') {
                continue;
            }
            if (!preg_match('/(\shref\s*=\s*)(?:"([^"]*)"|\'([^\']*)\'|([^\s>"\']+))/i', $token, $match, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            $quoted = isset($match[2]) && $match[2][1] >= 0 ? 2 : (isset($match[3]) && $match[3][1] >= 0 ? 3 : 4);
            $value = $match[$quoted][0];
            $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            foreach ($repoints as $index => $repoint) {
                if ($decoded !== strval($repoint['from'])) {
                    continue;
                }
                $replacement = htmlspecialchars(esc_url_raw(strval($repoint['to'])), ENT_QUOTES, 'UTF-8');
                $tokens[$position] = substr($token, 0, $match[$quoted][1]) . $replacement . substr($token, $match[$quoted][1] + strlen($value));
                $done[$index] += 1;
                break;
            }
        }
        foreach ($done as $index => $count) {
            if ($count === 0) {
                return new WP_Error('not_in_content', sprintf(__('Repoint %d: no link of the content has this address', 'kaigen-connector'), $index), array('status' => 422));
            }
        }
        return implode('', $tokens);
    }

    /**
     * Demotes or removes each H1 of the content named by its text, one H1 per fix, in order.
     */
    public static function apply_headings($content, $headings) {
        foreach ($headings as $index => $heading) {
            $text = isset($heading['text']) ? strval($heading['text']) : '';
            $action = isset($heading['action']) ? strval($heading['action']) : '';
            if ($text === '' || !in_array($action, array('demote', 'remove'), true)) {
                return new WP_Error('invalid_heading', sprintf(__('Heading %d: a text and an action, demote or remove, are required', 'kaigen-connector'), $index), array('status' => 400));
            }
            $fixed = self::fix_heading($content, $text, $action);
            if ($fixed === null) {
                return new WP_Error('not_in_content', sprintf(__('Heading %d: no H1 of the content has this text', 'kaigen-connector'), $index), array('status' => 422));
            }
            $content = $fixed;
        }
        return $content;
    }

    /**
     * Text as a reader compares it: entities decoded, the typography WordPress adds on display folded
     * (curly quotes, dashes, ellipsis, non-breaking spaces), spaces collapsed.
     */
    public static function comparable($value) {
        $value = html_entity_decode(strval($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = str_replace(array("\u{2019}", "\u{2018}", "\u{201B}", '`', "\u{00B4}"), "'", $value);
        $value = str_replace(array("\u{201C}", "\u{201D}", "\u{00AB}", "\u{00BB}"), '"', $value);
        $value = str_replace(array("\u{2013}", "\u{2014}"), '-', $value);
        $value = str_replace("\u{2026}", '...', $value);
        $value = preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', $value);
        return trim($value);
    }

    private static function tokens($content) {
        return preg_split('/(<!--.*?-->|<[^>]*>)/s', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
    }

    private static function is_tag($token) {
        return strlen($token) > 0 && $token[0] === '<';
    }

    // The tag's name and whether it closes, or null for a comment or a doctype.
    private static function tag_info($token) {
        if (!preg_match('/^<\s*(\/)?\s*([a-zA-Z][a-zA-Z0-9-]*)/', $token, $matches)) {
            return null;
        }
        $self_closing = substr(rtrim($token, " \t\n\r>"), -1) === '/' || substr($token, -2) === '/>';
        return array('name' => strtolower($matches[2]), 'closing' => $matches[1] === '/', 'self_closing' => $self_closing);
    }

    // A pattern that finds the words in raw HTML text, whatever entity or typography wrote them.
    private static function words_pattern($text) {
        $parts = array();
        foreach (preg_split('//u', self::comparable($text), -1, PREG_SPLIT_NO_EMPTY) as $char) {
            if ($char === ' ') {
                $parts[] = '(?:\s|&nbsp;|&#160;|&#xa0;|\x{00A0}|\x{202F})+';
            } elseif ($char === "'") {
                $parts[] = "(?:'|\x{2019}|\x{2018}|&\#0?39;|&\#8217;|&\#8216;|&rsquo;|&lsquo;|&apos;)";
            } elseif ($char === '"') {
                $parts[] = '(?:"|\x{201C}|\x{201D}|\x{00AB}|\x{00BB}|&quot;|&\#8220;|&\#8221;|&ldquo;|&rdquo;|&laquo;|&raquo;)';
            } elseif ($char === '-') {
                $parts[] = '(?:-|\x{2013}|\x{2014}|&ndash;|&mdash;|&\#8211;|&\#8212;)';
            } elseif ($char === '&') {
                $parts[] = '(?:&amp;|&\#038;|&)';
            } else {
                $parts[] = preg_quote($char, '/');
            }
        }
        return '/' . implode('', $parts) . '/u';
    }

    private static function place_link($content, $text, $sentence, $href) {
        $tokens = self::tokens($content);
        $pattern = self::words_pattern($text);
        $wanted = self::comparable($sentence);
        $container_depth = 0;
        $block_start = null;
        $count = count($tokens);
        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];
            if (!self::is_tag($token)) {
                continue;
            }
            $info = self::tag_info($token);
            if (!$info || in_array($info['name'], self::VOID, true) || $info['self_closing']) {
                continue;
            }
            if (!in_array($info['name'], self::CONTAINERS, true)) {
                continue;
            }
            if (!$info['closing']) {
                if ($container_depth === 0) {
                    $block_start = $index;
                }
                $container_depth++;
                continue;
            }
            $container_depth = max(0, $container_depth - 1);
            if ($container_depth !== 0 || $block_start === null) {
                continue;
            }
            // A whole paragraph or list item: does its text hold the sentence?
            $block_text = '';
            for ($cursor = $block_start; $cursor <= $index; $cursor++) {
                if (!self::is_tag($tokens[$cursor])) {
                    $block_text .= $tokens[$cursor];
                }
            }
            if (strpos(self::comparable($block_text), $wanted) !== false) {
                $placed = self::link_in_block($tokens, $block_start, $index, $pattern, $href);
                if ($placed !== null) {
                    return $placed;
                }
            }
            $block_start = null;
        }
        return null;
    }

    // The first words of the block that can take the link get it: the match runs on the block's text
    // read as one stream, so the words may cross inline formatting (a bold span, a footnote sup, the
    // non-breaking spaces an editor wraps in spans); the whole range is wrapped, formatting kept.
    private static function link_in_block($tokens, $start, $end, $pattern, $href) {
        // The block's texts as one stream, each mapped to its token and its forbidden depth.
        $pieces = array();
        $stream = '';
        $forbidden = 0;
        for ($cursor = $start; $cursor <= $end; $cursor++) {
            $token = $tokens[$cursor];
            if (self::is_tag($token)) {
                $info = self::tag_info($token);
                if ($info && !$info['self_closing'] && in_array($info['name'], self::FORBIDDEN, true)) {
                    $forbidden = max(0, $forbidden + ($info['closing'] ? -1 : 1));
                }
                continue;
            }
            if ($token === '') {
                continue;
            }
            $pieces[] = array('token' => $cursor, 'from' => strlen($stream), 'length' => strlen($token), 'forbidden' => $forbidden);
            $stream .= $token;
        }
        $from = 0;
        while ($from <= strlen($stream) && preg_match($pattern, $stream, $match, PREG_OFFSET_CAPTURE, $from)) {
            $placed = self::wrap_match($tokens, $pieces, $match[0][1], strlen($match[0][0]), $href);
            if ($placed !== null) {
                return $placed;
            }
            // The next try starts on the next character, never inside a UTF-8 sequence.
            $from = $match[0][1] + 1;
            while ($from < strlen($stream) && (ord($stream[$from]) & 0xC0) === 0x80) {
                $from++;
            }
        }
        return null;
    }

    // Wraps the matched range of the stream in the link, when every text it covers is outside the
    // forbidden elements and every tag it crosses is inline formatting that nests: an element wholly
    // inside the range is kept inside the link; one the words are flush with (bold words at the
    // range's edge) moves the link's bound over its own tags; words cutting an element's text in
    // half refuse the placement, the nesting could not stay valid without rebuilding the content.
    private static function wrap_match($tokens, $pieces, $offset, $length, $href) {
        $begin = null;
        $finish = null;
        foreach ($pieces as $piece) {
            if ($piece['from'] + $piece['length'] <= $offset || $piece['from'] >= $offset + $length) {
                continue;
            }
            if ($piece['forbidden'] > 0) {
                return null;
            }
            if ($begin === null) {
                $begin = array('token' => $piece['token'], 'at' => $offset - $piece['from']);
            }
            $finish = array('token' => $piece['token'], 'at' => min($piece['length'], $offset + $length - $piece['from']), 'length' => $piece['length']);
        }
        if ($begin === null || $finish === null) {
            return null;
        }
        // The tags between the two texts: `$before` closes elements opened before the range,
        // `$after` is left by elements still open at its end.
        $after = array();
        $before = array();
        for ($cursor = $begin['token'] + 1; $cursor < $finish['token']; $cursor++) {
            $token = $tokens[$cursor];
            if (!self::is_tag($token)) {
                continue;
            }
            $info = self::tag_info($token);
            if (!$info) {
                // A comment inside the words: left alone.
                return null;
            }
            if (in_array($info['name'], self::VOID, true) || $info['self_closing']) {
                if (!in_array($info['name'], self::INLINE_VOID, true)) {
                    return null;
                }
                continue;
            }
            if (!in_array($info['name'], self::INLINE, true)) {
                return null;
            }
            if ($info['closing']) {
                if (count($after) > 0) {
                    if (array_pop($after) !== $info['name']) {
                        return null;
                    }
                } else {
                    $before[] = $info['name'];
                }
            } else {
                $after[] = $info['name'];
            }
        }
        // The link opens where the words start, or before the opening tags of the elements they
        // close; it closes where they end, or after the closing tags of the elements they open.
        $open_at = array('token' => $begin['token'], 'at' => $begin['at']);
        if (count($before) > 0) {
            if ($begin['at'] !== 0) {
                return null;
            }
            $cursor = $begin['token'] - 1;
            foreach ($before as $name) {
                while ($cursor >= 0 && $tokens[$cursor] === '') {
                    $cursor--;
                }
                $info = $cursor >= 0 && self::is_tag($tokens[$cursor]) ? self::tag_info($tokens[$cursor]) : null;
                if (!$info || $info['closing'] || $info['self_closing'] || $info['name'] !== $name) {
                    return null;
                }
                $cursor--;
            }
            $open_at = array('token' => $cursor + 1, 'at' => 0);
        }
        $close_at = array('token' => $finish['token'], 'at' => $finish['at']);
        if (count($after) > 0) {
            if ($finish['at'] !== $finish['length']) {
                return null;
            }
            $cursor = $finish['token'] + 1;
            $count = count($tokens);
            foreach (array_reverse($after) as $name) {
                while ($cursor < $count && $tokens[$cursor] === '') {
                    $cursor++;
                }
                $info = $cursor < $count && self::is_tag($tokens[$cursor]) ? self::tag_info($tokens[$cursor]) : null;
                if (!$info || !$info['closing'] || $info['name'] !== $name) {
                    return null;
                }
                $cursor++;
            }
            $close_at = array('token' => $cursor - 1, 'at' => strlen($tokens[$cursor - 1]));
        }
        $opening = '<a href="' . esc_url($href) . '">';
        if ($open_at['token'] === $close_at['token']) {
            $token = $tokens[$open_at['token']];
            $tokens[$open_at['token']] = substr($token, 0, $open_at['at']) . $opening . substr($token, $open_at['at'], $close_at['at'] - $open_at['at']) . '</a>' . substr($token, $close_at['at']);
        } else {
            $last = $tokens[$close_at['token']];
            $tokens[$close_at['token']] = substr($last, 0, $close_at['at']) . '</a>' . substr($last, $close_at['at']);
            $first = $tokens[$open_at['token']];
            $tokens[$open_at['token']] = substr($first, 0, $open_at['at']) . $opening . substr($first, $open_at['at']);
        }
        return implode('', $tokens);
    }

    private static function fix_heading($content, $text, $action) {
        $tokens = self::tokens($content);
        $wanted = strtolower(self::comparable($text));
        $count = count($tokens);
        for ($index = 0; $index < $count; $index++) {
            $info = self::is_tag($tokens[$index]) ? self::tag_info($tokens[$index]) : null;
            if (!$info || $info['name'] !== 'h1' || $info['closing']) {
                continue;
            }
            $close = null;
            $inner = '';
            for ($cursor = $index + 1; $cursor < $count; $cursor++) {
                $inner_info = self::is_tag($tokens[$cursor]) ? self::tag_info($tokens[$cursor]) : null;
                if ($inner_info && $inner_info['name'] === 'h1' && $inner_info['closing']) {
                    $close = $cursor;
                    break;
                }
                if (!self::is_tag($tokens[$cursor])) {
                    $inner .= $tokens[$cursor];
                }
            }
            if ($close === null || strtolower(self::comparable($inner)) !== $wanted) {
                continue;
            }
            // The Gutenberg block around it, when there is one: its comments, through blank texts.
            $opening_comment = self::neighbour_comment($tokens, $index, -1, '/^<!--\s*wp:heading\b/');
            $closing_comment = self::neighbour_comment($tokens, $close, 1, '/^<!--\s*\/wp:heading\s*-->$/');
            if ($action === 'remove') {
                $from = $opening_comment !== null && $closing_comment !== null ? $opening_comment : $index;
                $to = $opening_comment !== null && $closing_comment !== null ? $closing_comment : $close;
                for ($cursor = $from; $cursor <= $to; $cursor++) {
                    $tokens[$cursor] = '';
                }
                return implode('', $tokens);
            }
            $tokens[$index] = preg_replace('/^<\s*h1/i', '<h2', $tokens[$index]);
            $tokens[$close] = preg_replace('/^<\s*\/\s*h1/i', '</h2', $tokens[$close]);
            if ($opening_comment !== null) {
                $tokens[$opening_comment] = preg_replace('/"level"\s*:\s*1\b/', '"level":2', $tokens[$opening_comment]);
            }
            return implode('', $tokens);
        }
        return null;
    }

    private static function neighbour_comment($tokens, $from, $step, $pattern) {
        for ($cursor = $from + $step; $cursor >= 0 && $cursor < count($tokens); $cursor += $step) {
            if ($tokens[$cursor] === '' || trim($tokens[$cursor]) === '') {
                continue;
            }
            return preg_match($pattern, $tokens[$cursor]) ? $cursor : null;
        }
        return null;
    }
}
