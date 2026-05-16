<?php

if (!defined('ABSPATH')) {
    exit;
}

final class Freego_WP_Issue_Store
{
    public const STATUS_OPEN = 'open';
    public const STATUS_REVIEWED = 'reviewed';
    public const STATUS_IGNORED = 'ignored';
    public const STATUS_FIXED = 'fixed';

    public static function activate(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::table_name();
        $charset = $wpdb->get_charset_collate();

        dbDelta(
            "CREATE TABLE {$table} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                issue_hash char(40) NOT NULL,
                rule_code varchar(40) NOT NULL,
                guideline varchar(20) NOT NULL DEFAULT '',
                automation varchar(40) NOT NULL DEFAULT '',
                severity varchar(20) NOT NULL DEFAULT 'review',
                status varchar(20) NOT NULL DEFAULT 'open',
                source_type varchar(30) NOT NULL DEFAULT '',
                source_id bigint(20) unsigned NOT NULL DEFAULT 0,
                source_url text NULL,
                message text NOT NULL,
                selector text NULL,
                snippet text NULL,
                context longtext NULL,
                first_seen datetime NOT NULL,
                last_seen datetime NOT NULL,
                resolved_at datetime NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY issue_hash (issue_hash),
                KEY rule_code (rule_code),
                KEY status (status),
                KEY source (source_type, source_id)
            ) {$charset};"
        );

        update_option('freego_wp_db_version', '1');
    }

    public static function table_name(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'freego_wp_issues';
    }

    /**
     * @param array<string,mixed> $issue
     */
    public function upsert(array $issue): int
    {
        global $wpdb;

        $now = current_time('mysql');
        $hash = $this->hash($issue);
        $existing_row = $wpdb->get_row($wpdb->prepare('SELECT id, status FROM ' . self::table_name() . ' WHERE issue_hash = %s', $hash), ARRAY_A);
        $existing = (int) ($existing_row['id'] ?? 0);

        $data = [
            'issue_hash' => $hash,
            'rule_code' => (string) ($issue['code'] ?? ''),
            'guideline' => (string) ($issue['guideline'] ?? ''),
            'automation' => (string) ($issue['automation'] ?? ''),
            'severity' => (string) ($issue['severity'] ?? 'review'),
            'source_type' => (string) ($issue['source_type'] ?? ''),
            'source_id' => (int) ($issue['source_id'] ?? 0),
            'source_url' => (string) ($issue['source_url'] ?? ''),
            'message' => (string) ($issue['message'] ?? ''),
            'selector' => (string) ($issue['selector'] ?? ''),
            'snippet' => (string) ($issue['snippet'] ?? ''),
            'context' => wp_json_encode($issue['context'] ?? [], JSON_UNESCAPED_UNICODE),
            'last_seen' => $now,
            'resolved_at' => null,
        ];

        if ($existing > 0) {
            if (($existing_row['status'] ?? '') === self::STATUS_FIXED) {
                $data['status'] = self::STATUS_OPEN;
            }
            $wpdb->update(self::table_name(), $data, ['id' => $existing]);
            return $existing;
        }

        $data['status'] = self::STATUS_OPEN;
        $data['first_seen'] = $now;
        $wpdb->insert(self::table_name(), $data);

        return (int) $wpdb->insert_id;
    }

    public function mark_stale_fixed(string $source_type, int $source_id, array $active_hashes): void
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, issue_hash FROM ' . self::table_name() . ' WHERE source_type = %s AND source_id = %d AND status = %s',
                $source_type,
                $source_id,
                self::STATUS_OPEN
            ),
            ARRAY_A
        );

        foreach ((array) $rows as $row) {
            if (!in_array($row['issue_hash'], $active_hashes, true)) {
                $this->update_status((int) $row['id'], self::STATUS_FIXED);
            }
        }
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    public function query(array $filters = []): array
    {
        global $wpdb;

        $where = [];
        $values = [];

        if (!empty($filters['status'])) {
            $where[] = 'status = %s';
            $values[] = (string) $filters['status'];
        }

        if (!empty($filters['source_type'])) {
            $where[] = 'source_type = %s';
            $values[] = (string) $filters['source_type'];
        }

        if (isset($filters['source_id'])) {
            $where[] = 'source_id = %d';
            $values[] = (int) $filters['source_id'];
        }

        $sql = 'SELECT * FROM ' . self::table_name();
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY FIELD(status, "open", "reviewed", "ignored", "fixed"), last_seen DESC LIMIT 300';

        if ($values) {
            $sql = $wpdb->prepare($sql, $values);
        }

        return (array) $wpdb->get_results($sql, ARRAY_A);
    }

    /**
     * @return array<string,int>
     */
    public function counts(): array
    {
        global $wpdb;

        $counts = [
            self::STATUS_OPEN => 0,
            self::STATUS_REVIEWED => 0,
            self::STATUS_IGNORED => 0,
            self::STATUS_FIXED => 0,
        ];

        $rows = $wpdb->get_results('SELECT status, COUNT(*) AS total FROM ' . self::table_name() . ' GROUP BY status', ARRAY_A);
        foreach ((array) $rows as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    public function update_status(int $id, string $status): bool
    {
        global $wpdb;

        $allowed = [self::STATUS_OPEN, self::STATUS_REVIEWED, self::STATUS_IGNORED, self::STATUS_FIXED];
        if (!in_array($status, $allowed, true)) {
            return false;
        }

        $data = ['status' => $status];
        if ($status === self::STATUS_FIXED) {
            $data['resolved_at'] = current_time('mysql');
        }

        return false !== $wpdb->update(self::table_name(), $data, ['id' => $id]);
    }

    /**
     * @param array<string,mixed> $issue
     */
    public function hash(array $issue): string
    {
        return sha1(implode('|', [
            (string) ($issue['code'] ?? ''),
            (string) ($issue['source_type'] ?? ''),
            (string) ($issue['source_id'] ?? ''),
            (string) ($issue['source_url'] ?? ''),
            (string) ($issue['selector'] ?? ''),
            substr((string) ($issue['snippet'] ?? ''), 0, 180),
        ]));
    }
}
