<?php
/**
 * Cache layer for `isOnWhatsApp` lookups.
 *
 * Uses osTicket's DB connection (via the global db_* helpers) to persist
 * results so we don't hammer Evolution API for every notification.
 *
 * Hits (number exists) get a long TTL because that rarely changes.
 * Misses (number does not exist) get a short TTL to allow recovery if the
 * customer signs up for WhatsApp later.
 *
 * Schema (auto-created on first use):
 *   CREATE TABLE %TABLE_PREFIX%evolution_wa_cache (
 *     phone   VARCHAR(32) NOT NULL PRIMARY KEY,
 *     exists_ TINYINT(1)  NOT NULL,
 *     jid     VARCHAR(128) DEFAULT NULL,
 *     created INT UNSIGNED NOT NULL,
 *     ttl     INT UNSIGNED NOT NULL
 *   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
 *
 * Note: `exists` is a SQL reserved word — column is named `exists_`.
 *
 * @license GPL-2.0-or-later
 */
class WhatsAppNumberCache {

    /** @var string Fully-qualified table name with osTicket prefix. */
    private $table;
    /** @var int seconds */
    private $ttlHit;
    /** @var int seconds */
    private $ttlMiss;
    /** @var bool */
    private $tableReady = false;

    public function __construct($ttlHit = 604800, $ttlMiss = 86400) {
        $this->table   = TABLE_PREFIX . 'evolution_wa_cache';
        $this->ttlHit  = (int) $ttlHit;
        $this->ttlMiss = (int) $ttlMiss;
    }

    /**
     * Look up a number. Returns:
     *  - true  → known to be on WhatsApp
     *  - false → known NOT on WhatsApp
     *  - null  → no cached entry (or expired)
     */
    public function get($phone) {
        $this->ensureTable();
        $phone = $this->escape($phone);
        $sql = 'SELECT exists_, created, ttl FROM ' . $this->table
             . ' WHERE phone="' . $phone . '" LIMIT 1';
        $res = db_query($sql);
        if (!$res) {
            return null;
        }
        $row = db_fetch_array($res);
        if (!$row) {
            return null;
        }
        $age = time() - (int) $row['created'];
        if ($age > (int) $row['ttl']) {
            return null;
        }
        return ((int) $row['exists_']) === 1;
    }

    /**
     * Persist a lookup result.
     */
    public function put($phone, $exists, $jid = null) {
        $this->ensureTable();
        $phone   = $this->escape($phone);
        $existsI = $exists ? 1 : 0;
        $ttl     = $exists ? $this->ttlHit : $this->ttlMiss;
        $jidEsc  = $jid === null ? 'NULL' : ('"' . $this->escape($jid) . '"');
        $now     = time();

        $sql = 'REPLACE INTO ' . $this->table
             . ' (phone, exists_, jid, created, ttl) VALUES ('
             . '"' . $phone . '", ' . $existsI . ', ' . $jidEsc . ', '
             . $now . ', ' . $ttl . ')';
        db_query($sql);
    }

    /**
     * Invalidate a single entry (e.g. when admin manually retries).
     */
    public function forget($phone) {
        if (!$this->tableReady) {
            return;
        }
        $phone = $this->escape($phone);
        db_query('DELETE FROM ' . $this->table . ' WHERE phone="' . $phone . '"');
    }

    /**
     * Drop expired rows. Safe to call periodically.
     */
    public function pruneExpired() {
        if (!$this->tableReady) {
            return;
        }
        $now = time();
        db_query('DELETE FROM ' . $this->table . ' WHERE (created + ttl) < ' . $now);
    }

    private function ensureTable() {
        if ($this->tableReady) {
            return;
        }
        $sql = 'CREATE TABLE IF NOT EXISTS ' . $this->table . ' ('
             . ' phone VARCHAR(32) NOT NULL PRIMARY KEY,'
             . ' exists_ TINYINT(1) NOT NULL,'
             . ' jid VARCHAR(128) DEFAULT NULL,'
             . ' created INT UNSIGNED NOT NULL,'
             . ' ttl INT UNSIGNED NOT NULL'
             . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
        db_query($sql);
        $this->tableReady = true;
    }

    private function escape($s) {
        if (function_exists('db_real_escape')) {
            return db_real_escape((string) $s, false);
        }
        return addslashes((string) $s);
    }
}
