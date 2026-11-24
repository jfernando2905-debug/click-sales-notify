<?php
defined('ABSPATH') || exit;

class CSN_DB {
    const VERSION = '1';

    static function clicks()      { global $wpdb; return $wpdb->prefix . 'csn_clicks'; }
    static function conversions() { global $wpdb; return $wpdb->prefix . 'csn_conversions'; }

    static function install() {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        global $wpdb;
        $c = $wpdb->get_charset_collate();

        dbDelta("CREATE TABLE " . self::clicks() . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            click_id VARCHAR(255) NOT NULL,
            ip VARCHAR(45) NULL,
            country VARCHAR(2) NULL,
            region VARCHAR(100) NULL,
            isp VARCHAR(150) NULL,
            page VARCHAR(255) NULL,
            clicked_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY click_id (click_id(191))
        ) $c;");

        dbDelta("CREATE TABLE " . self::conversions() . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            platform VARCHAR(30) NOT NULL,
            order_id VARCHAR(100) NOT NULL,
            event VARCHAR(20) NOT NULL,
            click_id VARCHAR(255) NOT NULL DEFAULT '',
            amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            currency VARCHAR(3) NOT NULL DEFAULT 'USD',
            product VARCHAR(150) NULL,
            raw TEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_conv (platform, order_id, event),
            KEY click_id (click_id(191)),
            KEY created_at (created_at)
        ) $c;");

        if (!get_option('csn_token')) {
            add_option('csn_token', wp_generate_password(32, false));
        }
        update_option('csn_db_version', self::VERSION);
    }

    static function maybe_upgrade() {
        if (get_option('csn_db_version') !== self::VERSION) {
            self::install();
        }
    }
}
