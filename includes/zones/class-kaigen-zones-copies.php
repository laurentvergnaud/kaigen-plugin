<?php
/**
 * The copies of a page a write keeps (plugin 1.7.0, `_kaigen_zones_backup_<id>`): how long they live, and
 * which have outlived it (1.9.0, ADR 0152). Pure, so that it runs in the tests without WordPress; the
 * deletion is `Kaigen_Zones`'s.
 */

if (!defined('ABSPATH') && !defined('KAIGEN_ZONES_TESTS')) {
    exit;
}

class Kaigen_Zones_Copies {
    public const PREFIX = '_kaigen_zones_backup_';
    public const DAYS = 30;
    // The daily purge of the whole site's copies past their life (ADR 0152).
    public const PURGE_HOOK = 'kaigen_purge_page_copies';

    /**
     * Has this copy outlived its 30 days at `$now` (a Unix time)? A copy whose date cannot be read is
     * past it too: it could not be told apart from an old one.
     */
    public static function expired($copy, $now) {
        if (!is_array($copy) || !isset($copy['created'])) {
            return true;
        }
        $created = strtotime(strval($copy['created']));
        return $created === false || $created < $now - self::DAYS * 86400;
    }

    /**
     * The ids of the copies to drop among `$rows`, each `array('id' => …, 'copy' => …)` as stored.
     */
    public static function expired_ids($rows, $now) {
        $ids = array();
        foreach ($rows as $row) {
            if (self::expired($row['copy'], $now)) {
                $ids[] = $row['id'];
            }
        }
        return $ids;
    }
}
