<?php
/**
 * The text zones of an Elementor page (plugin 1.7.0): the text fields of its widgets in `_elementor_data`,
 * the tree Elementor renders, never its layout nor its styles. A change sets the fields it names; adds a
 * text widget by duplicating one (`duplicate_after`); and adds a section by duplicating a whole section of
 * the page, its images, icons, cards and buttons included, placed among its siblings, every text of the copy
 * rewritten (`duplicate_section`): the copy keeps the site's look, Tamiro never invents a layout. The copy
 * keeps the source's images until Tamiro generates its own (lot 11, second step); it may drop elements it
 * does not need; its anchors are removed. A global widget is shared between pages and a dynamic field is
 * filled by the site: neither is ever written, and a section holding one, a form, an HTML or shortcode
 * widget, or any widget whose text Tamiro does not read is never duplicated.
 */

if (!defined('ABSPATH') && !defined('KAIGEN_ZONES_TESTS')) {
    exit;
}

class Kaigen_Zones_Elementor {

    // The text fields of the widgets Tamiro writes, each with its role, in the order of the widget.
    private const FIELDS = array(
        'heading' => array(array('title', 'heading')),
        'text-editor' => array(array('editor', 'text')),
        'button' => array(array('text', 'button')),
        'icon-box' => array(array('title_text', 'heading'), array('description_text', 'text')),
        'image-box' => array(array('title_text', 'heading'), array('description_text', 'text')),
        'call-to-action' => array(array('title', 'heading'), array('description', 'text'), array('button', 'button')),
    );
    // The repeaters whose items hold text: an accordion's questions and answers, a list's items.
    private const REPEATERS = array(
        'accordion' => array('tabs', array(array('tab_title', 'question'), array('tab_content', 'answer'))),
        'toggle' => array('tabs', array(array('tab_title', 'question'), array('tab_content', 'answer'))),
        'icon-list' => array('icon_list', array(array('text', 'list_item'))),
    );
    // The widgets a duplicated structure may hold besides text: the space between texts.
    private const DECORATIVE = array('spacer', 'divider');
    // What a duplicated section may hold besides text: images and their kin, kept by the copy until Tamiro
    // generates its own, and decoration. Any other widget may hold a text Tamiro does not read: a section
    // holding one is never duplicated, its copy would repeat that text.
    private const MEDIA = array('image' => 'image', 'video' => 'video', 'image-carousel' => 'carousel', 'image-gallery' => 'gallery');
    private const SECTION_DECORATIVE = array('spacer', 'divider', 'icon', 'social-icons');
    private const SECTION_TYPES = array('section', 'container');
    // The settings that hold a link, by widget.
    private const LINKS = array('button' => 'link', 'icon-box' => 'link', 'image-box' => 'link', 'heading' => 'link', 'icon' => 'link', 'image' => 'link', 'call-to-action' => 'link');

    public static function read($elements) {
        $zones = array();
        $structures = array();
        self::walk($elements, null, $zones, $structures);
        return array('zones' => array_map(array(__CLASS__, 'public_zone'), $zones), 'structures' => $structures, 'sections' => array_map(array(__CLASS__, 'public_section'), self::sections($elements)));
    }

    /**
     * The tree with the operations applied: `replace` (a zone, its hash as read, the new HTML),
     * `duplicate_after` (a structure, the new texts in the order of its zones) and `duplicate_section` (a
     * section, where its copy goes, the copy's texts by the source's zones, its links, the elements it drops).
     * All or nothing. `$report` receives what each copy holds: its id, the images it keeps from its source,
     * the anchors removed.
     */
    public static function apply($elements, $operations, $new_id = null, &$report = null) {
        $report = array();
        $read = array('zones' => array(), 'structures' => array());
        self::walk($elements, null, $read['zones'], $read['structures']);
        $zones = array();
        foreach ($read['zones'] as $zone) {
            $zones[$zone['id']] = $zone;
        }
        $structures = array();
        foreach ($read['structures'] as $structure) {
            $structures[$structure['id']] = $structure;
        }
        $taken = array();
        self::ids($elements, $taken);
        $new_id = $new_id ?: function () use (&$taken) {
            do {
                $id = substr(bin2hex(random_bytes(4)), 0, 7);
            } while (isset($taken[$id]));
            $taken[$id] = true;
            return $id;
        };
        $duplicates = array();
        $copies = array();
        $sections = null;
        foreach ($operations as $index => $operation) {
            $op = isset($operation['op']) ? strval($operation['op']) : '';
            if ($op === 'replace') {
                $id = isset($operation['zone']) ? strval($operation['zone']) : '';
                if (!isset($zones[$id])) {
                    throw new Kaigen_Zones_Error('unknown_zone', sprintf('Zone %s is not a zone of this page', $id ?: '?'), 422, array('zone' => $id));
                }
                $zone = $zones[$id];
                if ($zone['reason'] !== null) {
                    throw new Kaigen_Zones_Error('not_editable', sprintf('Zone %s is not rewritten: %s', $id, $zone['reason']), 422, array('zone' => $id, 'reason' => $zone['reason']));
                }
                if (!isset($operation['hash']) || strval($operation['hash']) !== $zone['hash']) {
                    throw new Kaigen_Zones_Error('zone_changed', sprintf('Zone %s changed since it was read', $id), 409, array('zone' => $id));
                }
                Kaigen_Zones_Text::assert_html($operation['html'] ?? '', $zone['role'], $id);
                self::set_field($elements, $zone, strval($operation['html']));
            } elseif ($op === 'duplicate_after') {
                $id = isset($operation['structure']) ? strval($operation['structure']) : '';
                if (!isset($structures[$id])) {
                    throw new Kaigen_Zones_Error('unknown_structure', sprintf('Structure %s is not a structure of this page that Tamiro duplicates', $id ?: '?'), 422, array('structure' => $id));
                }
                $texts = isset($operation['html']) && is_array($operation['html']) ? array_values($operation['html']) : array();
                $structure = $structures[$id];
                if (count($texts) !== count($structure['zones'])) {
                    throw new Kaigen_Zones_Error('invalid_operation', sprintf('Structure %s holds %d texts, %d given', $id, count($structure['zones']), count($texts)), 400);
                }
                foreach ($structure['zones'] as $position => $zone_id) {
                    Kaigen_Zones_Text::assert_html($texts[$position], $zones[$zone_id]['role'], $zone_id . ' (copy)');
                }
                $duplicates[] = array('structure' => $structure, 'texts' => $texts);
            } elseif ($op === 'duplicate_section') {
                if ($sections === null) {
                    $sections = array();
                    foreach (self::sections($elements) as $section) {
                        $sections[$section['id']] = $section;
                    }
                }
                $copies[] = self::section_copy($elements, $sections, $zones, $operation, $index);
            } else {
                throw new Kaigen_Zones_Error('invalid_operation', sprintf('Operation %d: %s is not an operation of an Elementor page', $index, $op ?: 'nothing'), 400);
            }
        }
        // The copies are made from the tree as rewritten, each placed right after its source, in order.
        foreach (array_reverse($duplicates) as $duplicate) {
            $element_id = $duplicate['structure']['element'];
            $source = self::find($elements, $element_id);
            $copy = self::renew($source, $new_id);
            $filled = $copy;
            $position = 0;
            self::fill($filled, $duplicate['texts'], $position);
            self::insert_after($elements, $element_id, $filled);
        }
        // The sections' copies, made from the tree as rewritten, each placed after its anchor, in order.
        foreach (array_reverse($copies) as $copy) {
            $report[] = self::place_copy($elements, $copy, $new_id);
        }
        $report = array_reverse($report);
        return $elements;
    }

    /**
     * The sections of the page, every section and container at any depth, each with what it holds: its
     * zones, its images, its links, the elements it holds directly, its shape, and whether Tamiro duplicates
     * it (and why not).
     */
    private static function sections($elements, $parent = null) {
        $sections = array();
        foreach ($elements as $element) {
            $type = $element['elType'] ?? '';
            if ($type === 'widget') {
                continue;
            }
            $id = strval($element['id'] ?? '');
            $children = isset($element['elements']) && is_array($element['elements']) ? $element['elements'] : array();
            if (in_array($type, self::SECTION_TYPES, true)) {
                $found = self::analyse($element);
                $reason = !empty($found['blockers']) ? $found['blockers'][0] : (empty($found['zones']) ? 'no_text' : null);
                $sections[] = array(
                    'id' => 'e:' . $id, 'element' => $id, 'parent' => $parent !== null ? 'e:' . $parent : null,
                    'zones' => $found['zones'], 'media' => $found['media'], 'links' => $found['links'],
                    'items' => array_values(array_map(function ($child) { return 'e:' . strval($child['id'] ?? ''); }, $children)),
                    'shape' => self::shape($found['shape']), 'duplicable' => $reason === null, 'reason' => $reason,
                );
            }
            foreach (self::sections($children, $id) as $inner) {
                $sections[] = $inner;
            }
        }
        return $sections;
    }

    // What an element holds, its descendants included: zone ids, image slots, link slots, the reasons it
    // cannot be duplicated, and the tokens of its shape in order.
    private static function analyse($element) {
        $found = array('zones' => array(), 'media' => array(), 'links' => array(), 'blockers' => array(), 'shape' => array());
        $id = strval($element['id'] ?? '');
        $settings = isset($element['settings']) && is_array($element['settings']) ? $element['settings'] : array();
        if (($element['elType'] ?? '') === 'widget') {
            $widget = strval($element['widgetType'] ?? '');
            if ($widget === 'global') {
                $found['blockers'][] = 'global';
                return $found;
            }
            $known = isset(self::FIELDS[$widget]) || isset(self::REPEATERS[$widget]) || isset(self::MEDIA[$widget]) || in_array($widget, self::SECTION_DECORATIVE, true);
            if (!$known) {
                $found['blockers'][] = 'widget:' . $widget;
                return $found;
            }
            foreach (self::widget_zones($element, $widget, null) as $zone) {
                if ($zone['reason'] !== null) {
                    $found['blockers'][] = $zone['reason'] . ':' . $zone['id'];
                }
                $found['zones'][] = $zone['id'];
                $found['shape'][] = $zone['role'] === 'heading' && $zone['level'] ? 'h' . $zone['level'] : $zone['role'];
            }
            if (isset(self::MEDIA[$widget])) {
                $key = $widget === 'image' ? 'image' : ($widget === 'video' ? 'video' : 'gallery');
                $found['media'][] = 'm:e:' . $id . ':' . $key;
                $found['shape'][] = self::MEDIA[$widget];
            } elseif ($widget === 'image-box' && !empty($settings['image']['url'])) {
                $found['media'][] = 'm:e:' . $id . ':image';
                $found['shape'][] = 'image';
            } elseif (in_array($widget, array('icon', 'icon-box'), true)) {
                $found['shape'][] = 'icon';
            }
            if (isset(self::LINKS[$widget]) && !empty($settings[self::LINKS[$widget]]['url'])) {
                $found['links'][] = 'l:e:' . $id . ':' . self::LINKS[$widget];
            }
            if ($widget === 'icon-list' && isset($settings['icon_list']) && is_array($settings['icon_list'])) {
                foreach ($settings['icon_list'] as $item) {
                    if (!empty($item['link']['url']) && isset($item['_id'])) {
                        $found['links'][] = 'l:e:' . $id . ':icon_list:' . strval($item['_id']) . ':link';
                    }
                }
            }
            return $found;
        }
        if (!empty($settings['background_image']['url'])) {
            $found['media'][] = 'm:e:' . $id . ':background_image';
            $found['shape'][] = 'background';
        }
        foreach (isset($element['elements']) && is_array($element['elements']) ? $element['elements'] : array() as $child) {
            $inner = self::analyse($child);
            foreach ($inner as $key => $values) {
                $found[$key] = array_merge($found[$key], $values);
            }
        }
        return $found;
    }

    // A shape as a reader would say it: « h2 · paragraph ×2 · image ».
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

    /**
     * A `duplicate_section` checked against the page: the section duplicable, the elements it drops inside
     * it, a new text for every zone it keeps and none other, each text rewritten (never the source's), the
     * links valid addresses, the anchor a sibling of the section.
     */
    private static function section_copy($elements, $sections, $zones, $operation, $index) {
        $id = isset($operation['section']) ? strval($operation['section']) : '';
        if (!isset($sections[$id])) {
            throw new Kaigen_Zones_Error('unknown_section', sprintf('Section %s is not a section of this page', $id ?: '?'), 422, array('section' => $id));
        }
        $section = $sections[$id];
        if (!$section['duplicable']) {
            throw new Kaigen_Zones_Error('not_duplicable', sprintf('Section %s is not duplicated: %s', $id, $section['reason']), 422, array('section' => $id, 'reason' => $section['reason']));
        }
        $source = self::find($elements, $section['element']);
        $inside = array();
        self::ids(isset($source['elements']) ? $source['elements'] : array(), $inside);
        $removed = array();
        foreach (isset($operation['remove']) && is_array($operation['remove']) ? $operation['remove'] : array() as $remove) {
            $element_id = preg_replace('/^e:/', '', strval($remove));
            if (!isset($inside[$element_id])) {
                throw new Kaigen_Zones_Error('invalid_operation', sprintf('Operation %d: %s is not inside section %s', $index, $remove, $id), 400);
            }
            $removed[$element_id] = true;
            $dropped = self::find(array($source), $element_id);
            self::ids(isset($dropped['elements']) ? $dropped['elements'] : array(), $removed);
        }
        $kept = array_values(array_filter($section['zones'], function ($zone_id) use ($zones, $removed) { return !isset($removed[$zones[$zone_id]['where']['element']]); }));
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
            $html = strval($texts[$zone_id]);
            Kaigen_Zones_Text::assert_html($html, $zones[$zone_id]['role'], $zone_id . ' (copy)');
            if (Kaigen_Zones_Text::plain($html) === Kaigen_Zones_Text::plain($zones[$zone_id]['html'])) {
                throw new Kaigen_Zones_Error('unchanged_text', sprintf('Operation %d: the copy of %s repeats its text', $index, $zone_id), 400, array('zone' => $zone_id));
            }
        }
        $links = isset($operation['links']) && is_array($operation['links']) ? $operation['links'] : array();
        foreach ($links as $slot => $url) {
            if (!in_array($slot, $section['links'], true) || isset($removed[explode(':', $slot)[2]])) {
                throw new Kaigen_Zones_Error('unknown_link', sprintf('Operation %d: %s is not a link the copy keeps', $index, $slot), 400);
            }
            if (!preg_match('#^(https?://[^\s"<>]+|/[^\s"<>]*|\#[\w-]+)$#i', strval($url))) {
                throw new Kaigen_Zones_Error('invalid_link', sprintf('Operation %d: %s is not an address', $index, strval($url)), 400);
            }
        }
        $after = isset($operation['after']) && $operation['after'] !== '' ? preg_replace('/^e:/', '', strval($operation['after'])) : $section['element'];
        if (self::parent_of($elements, $after) !== self::parent_of($elements, $section['element']) || self::find($elements, $after) === null) {
            throw new Kaigen_Zones_Error('invalid_placement', sprintf('Operation %d: the copy of %s goes among its siblings, %s is not one', $index, $id, $after), 400);
        }
        return array('section' => $section, 'removed' => $removed, 'texts' => array_intersect_key($texts, array_flip($kept)), 'zones' => array_intersect_key($zones, array_flip($kept)), 'links' => $links, 'after' => $after);
    }

    // The copy of a section built and placed: new identifiers, its dropped elements removed, its texts and
    // links set by the source's, its anchors removed; what it holds reported.
    private static function place_copy(&$elements, $copy, $new_id) {
        $section = $copy['section'];
        $map = array();
        $tree = self::renew(self::find($elements, $section['element']), $new_id, $map);
        $removed = array();
        foreach (array_keys($copy['removed']) as $old) {
            if (isset($map[$old])) {
                $removed[$map[$old]] = true;
            }
        }
        self::remove_elements($tree, $removed);
        $wrapped = array($tree);
        foreach ($copy['zones'] as $zone_id => $zone) {
            $where = $zone['where'];
            $where['element'] = $map[$where['element']];
            if (isset($where['item'])) {
                $where['item'] = $map['item:' . $where['item']];
            }
            self::set_field($wrapped, array('where' => $where), strval($copy['texts'][$zone_id]));
        }
        foreach ($copy['links'] as $slot => $url) {
            $parts = explode(':', $slot);
            $element_id = $map[$parts[2]];
            $item = count($parts) === 6 ? ($map['item:' . $parts[4]] ?? null) : null;
            self::set_link($wrapped, $element_id, $parts[count($parts) - 1], $item, count($parts) === 6 ? $parts[3] : null, strval($url));
        }
        $anchors = 0;
        self::clear_anchors($wrapped[0], $anchors);
        self::insert_after($elements, $copy['after'], $wrapped[0]);
        $media = array();
        foreach ($section['media'] as $slot) {
            $parts = explode(':', $slot);
            if (isset($map[$parts[2]]) && !isset($removed[$map[$parts[2]]])) {
                $media[] = 'm:e:' . $map[$parts[2]] . ':' . $parts[3];
            }
        }
        return array('section' => 'e:' . $wrapped[0]['id'], 'from' => $section['id'], 'media' => $media, 'anchors_removed' => $anchors);
    }

    private static function parent_of($elements, $id, $parent = null) {
        foreach ($elements as $element) {
            if (isset($element['id']) && strval($element['id']) === $id) {
                return $parent ?? '';
            }
            if (isset($element['elements']) && is_array($element['elements'])) {
                $found = self::parent_of($element['elements'], $id, strval($element['id'] ?? ''));
                if ($found !== null) {
                    return $found;
                }
            }
        }
        return null;
    }

    private static function remove_elements(&$element, $removed) {
        if (!isset($element['elements']) || !is_array($element['elements'])) {
            return;
        }
        $element['elements'] = array_values(array_filter($element['elements'], function ($child) use ($removed) { return !isset($removed[strval($child['id'] ?? '')]); }));
        foreach ($element['elements'] as &$child) {
            self::remove_elements($child, $removed);
        }
    }

    private static function set_link(&$elements, $element_id, $key, $item_id, $repeater, $url) {
        foreach ($elements as &$element) {
            if (isset($element['id']) && strval($element['id']) === $element_id) {
                if ($repeater !== null) {
                    foreach ($element['settings'][$repeater] as &$entry) {
                        if (isset($entry['_id']) && strval($entry['_id']) === $item_id) {
                            $entry[$key]['url'] = $url;
                        }
                    }
                    return;
                }
                $element['settings'][$key]['url'] = $url;
                return;
            }
            if (isset($element['elements']) && is_array($element['elements'])) {
                self::set_link($element['elements'], $element_id, $key, $item_id, $repeater, $url);
            }
        }
    }

    // An anchor (Elementor's CSS ID) names one element of the page: the copy's are removed, counted.
    private static function clear_anchors(&$element, &$count) {
        if (isset($element['settings']['_element_id']) && $element['settings']['_element_id'] !== '') {
            unset($element['settings']['_element_id']);
            $count++;
        }
        if (isset($element['elements']) && is_array($element['elements'])) {
            foreach ($element['elements'] as &$child) {
                self::clear_anchors($child, $count);
            }
        }
    }

    public static function public_section($section) {
        return $section;
    }

    private static function walk($elements, $parent, &$zones, &$structures) {
        foreach ($elements as $element) {
            $id = isset($element['id']) ? strval($element['id']) : '';
            $type = $element['elType'] ?? '';
            if ($type === 'widget') {
                $widget = strval($element['widgetType'] ?? '');
                $own = self::widget_zones($element, $widget, $parent);
                foreach ($own as $zone) {
                    $zones[] = $zone;
                }
                if (!empty($own) && isset(self::FIELDS[$widget]) && self::all_editable($own)) {
                    $structures[] = array('id' => 'e:' . $id, 'element' => $id, 'zones' => array_column($own, 'id'), 'roles' => array_column($own, 'role'));
                }
                continue;
            }
            $children = isset($element['elements']) && is_array($element['elements']) ? $element['elements'] : array();
            self::walk($children, $id, $zones, $structures);
            // A section, a column or a container is duplicated when all it holds is text and space.
            $inner = array();
            if ($children && self::text_only($children, $inner) && !empty($inner)) {
                $structures[] = array('id' => 'e:' . $id, 'element' => $id, 'zones' => array_column($inner, 'id'), 'roles' => array_column($inner, 'role'));
            }
        }
    }

    private static function widget_zones($element, $widget, $parent) {
        $id = strval($element['id'] ?? '');
        $settings = isset($element['settings']) && is_array($element['settings']) ? $element['settings'] : array();
        $dynamic = isset($settings['__dynamic__']) && is_array($settings['__dynamic__']) ? $settings['__dynamic__'] : array();
        $zones = array();
        if (isset(self::FIELDS[$widget])) {
            foreach (self::FIELDS[$widget] as $field) {
                list($key, $role) = $field;
                if (!isset($settings[$key]) || !is_string($settings[$key]) || trim($settings[$key]) === '') {
                    continue;
                }
                $level = $role === 'heading' ? self::level($widget, $settings) : null;
                // A heading widget set to render as a p, a div or a span is a paragraph to the reader (most of
                // the « titles » of faaaster.io's page 5212, 2026-10-16), never a heading of the outline.
                if ($widget === 'heading' && $level === null) {
                    $role = 'paragraph';
                }
                $zones[] = self::zone('e:' . $id . ':' . $key, $role, $level, $settings[$key], isset($dynamic[$key]) ? 'dynamic' : null, $parent, array('element' => $id, 'key' => $key));
            }
        }
        if (isset(self::REPEATERS[$widget])) {
            list($repeater, $fields) = self::REPEATERS[$widget];
            $items = isset($settings[$repeater]) && is_array($settings[$repeater]) ? $settings[$repeater] : array();
            foreach ($items as $item) {
                $item_id = isset($item['_id']) ? strval($item['_id']) : '';
                foreach ($fields as $field) {
                    list($key, $role) = $field;
                    if ($item_id === '' || !isset($item[$key]) || !is_string($item[$key]) || trim($item[$key]) === '') {
                        continue;
                    }
                    $item_dynamic = isset($item['__dynamic__'][$key]) || isset($dynamic[$repeater]);
                    $zones[] = self::zone('e:' . $id . ':' . $repeater . ':' . $item_id . ':' . $key, $role, null, $item[$key], $item_dynamic ? 'dynamic' : null, $parent, array('element' => $id, 'repeater' => $repeater, 'item' => $item_id, 'key' => $key));
                }
            }
        }
        return $zones;
    }

    // The tag a widget's title renders with, Elementor's default when unset: null for a p, a div or a span.
    private const TITLE_TAGS = array('heading' => array('header_size', 'h2'), 'icon-box' => array('title_size', 'h3'), 'image-box' => array('title_size', 'h3'), 'call-to-action' => array('title_tag', 'h2'));

    private static function level($widget, $settings) {
        if (!isset(self::TITLE_TAGS[$widget])) {
            return null;
        }
        list($key, $default) = self::TITLE_TAGS[$widget];
        $size = isset($settings[$key]) && is_string($settings[$key]) && $settings[$key] !== '' ? $settings[$key] : $default;
        return preg_match('/^h([1-6])$/', $size, $match) ? intval($match[1]) : null;
    }

    private static function zone($id, $role, $level, $html, $reason, $parent, $where) {
        $reason = $reason ?: Kaigen_Zones_Text::lock_reason($html, $role);
        return array('id' => $id, 'role' => $role, 'level' => $level, 'html' => $html, 'hash' => Kaigen_Zones_Text::hash($html), 'reason' => $reason, 'group' => $parent ? 'e:' . $parent : null, 'where' => $where);
    }

    private static function all_editable($zones) {
        foreach ($zones as $zone) {
            if ($zone['reason'] !== null) {
                return false;
            }
        }
        return true;
    }

    // Whether these elements hold only text widgets Tamiro writes and space, their zones collected.
    private static function text_only($elements, &$zones) {
        foreach ($elements as $element) {
            $type = $element['elType'] ?? '';
            if ($type === 'widget') {
                $widget = strval($element['widgetType'] ?? '');
                if (in_array($widget, self::DECORATIVE, true)) {
                    continue;
                }
                if (!isset(self::FIELDS[$widget])) {
                    return false;
                }
                $own = self::widget_zones($element, $widget, null);
                if (!self::all_editable($own)) {
                    return false;
                }
                foreach ($own as $zone) {
                    $zones[] = $zone;
                }
                continue;
            }
            $children = isset($element['elements']) && is_array($element['elements']) ? $element['elements'] : array();
            if (!self::text_only($children, $zones)) {
                return false;
            }
        }
        return true;
    }

    private static function set_field(&$elements, $zone, $html) {
        foreach ($elements as &$element) {
            if (isset($element['id']) && strval($element['id']) === $zone['where']['element']) {
                if (isset($zone['where']['repeater'])) {
                    foreach ($element['settings'][$zone['where']['repeater']] as &$item) {
                        if (isset($item['_id']) && strval($item['_id']) === $zone['where']['item']) {
                            $item[$zone['where']['key']] = $html;
                            return true;
                        }
                    }
                    return false;
                }
                $element['settings'][$zone['where']['key']] = $html;
                return true;
            }
            if (isset($element['elements']) && is_array($element['elements']) && self::set_field($element['elements'], $zone, $html)) {
                return true;
            }
        }
        return false;
    }

    private static function find($elements, $id) {
        foreach ($elements as $element) {
            if (isset($element['id']) && strval($element['id']) === $id) {
                return $element;
            }
            if (isset($element['elements']) && is_array($element['elements'])) {
                $found = self::find($element['elements'], $id);
                if ($found !== null) {
                    return $found;
                }
            }
        }
        return null;
    }

    private static function ids($elements, &$taken) {
        foreach ($elements as $element) {
            if (isset($element['id'])) {
                $taken[strval($element['id'])] = true;
            }
            if (isset($element['elements']) && is_array($element['elements'])) {
                self::ids($element['elements'], $taken);
            }
        }
    }

    // A copy of an element with new identifiers, its repeaters' items too; `$map` receives each old
    // identifier's new one (an item's under `item:<old>`).
    private static function renew($element, $new_id, &$map = array()) {
        $old = strval($element['id'] ?? '');
        $element['id'] = $new_id();
        $map[$old] = $element['id'];
        if (isset($element['settings']) && is_array($element['settings'])) {
            foreach ($element['settings'] as $key => $value) {
                if (is_array($value) && isset(self::REPEATERS[$element['widgetType'] ?? '']) && self::REPEATERS[$element['widgetType']][0] === $key) {
                    foreach ($element['settings'][$key] as $i => $item) {
                        $fresh = $new_id();
                        if (isset($item['_id'])) {
                            $map['item:' . strval($item['_id'])] = $fresh;
                        }
                        $element['settings'][$key][$i]['_id'] = $fresh;
                    }
                }
            }
        }
        if (isset($element['elements']) && is_array($element['elements'])) {
            foreach ($element['elements'] as $i => $child) {
                $element['elements'][$i] = self::renew($child, $new_id, $map);
            }
        }
        return $element;
    }

    // The copy's text fields set in the order its structure lists them.
    private static function fill(&$element, $texts, &$position) {
        if (($element['elType'] ?? '') === 'widget') {
            $widget = strval($element['widgetType'] ?? '');
            foreach (self::FIELDS[$widget] ?? array() as $field) {
                $key = $field[0];
                if (isset($element['settings'][$key]) && is_string($element['settings'][$key]) && trim($element['settings'][$key]) !== '') {
                    $element['settings'][$key] = $texts[$position++];
                }
            }
            return;
        }
        if (isset($element['elements']) && is_array($element['elements'])) {
            foreach ($element['elements'] as &$child) {
                self::fill($child, $texts, $position);
            }
        }
    }

    private static function insert_after(&$elements, $id, $copy) {
        foreach ($elements as $index => &$element) {
            if (isset($element['id']) && strval($element['id']) === $id) {
                array_splice($elements, $index + 1, 0, array($copy));
                return true;
            }
            if (isset($element['elements']) && is_array($element['elements']) && self::insert_after($element['elements'], $id, $copy)) {
                return true;
            }
        }
        return false;
    }

    /**
     * What a tree must keep for Tamiro to call two of them the same page: its elements in order and the
     * text of each zone. Elementor may add or normalize settings it owns on save; a text or an element
     * that changed is a difference.
     */
    public static function signature($elements) {
        $zones = array();
        $structures = array();
        self::walk($elements, null, $zones, $structures);
        $ids = array();
        self::ids($elements, $ids);
        return array('elements' => array_keys($ids), 'zones' => array_combine(array_column($zones, 'id'), array_column($zones, 'hash')) ?: array());
    }

    // The zones and elements of the expected tree that the saved one does not hold as they were.
    public static function differences($expected, $saved) {
        $a = self::signature($expected);
        $b = self::signature($saved);
        $differences = array();
        if ($a['elements'] !== $b['elements']) {
            $differences[] = 'elements';
        }
        foreach ($a['zones'] as $id => $hash) {
            if (!isset($b['zones'][$id]) || $b['zones'][$id] !== $hash) {
                $differences[] = $id;
            }
        }
        foreach (array_diff_key($b['zones'], $a['zones']) as $id => $hash) {
            $differences[] = $id;
        }
        return $differences;
    }

    public static function public_zone($zone) {
        return array('id' => $zone['id'], 'role' => $zone['role'], 'level' => $zone['level'], 'html' => $zone['html'], 'text' => Kaigen_Zones_Text::plain($zone['html']), 'hash' => $zone['hash'], 'editable' => $zone['reason'] === null, 'reason' => $zone['reason'], 'group' => $zone['group']);
    }
}
