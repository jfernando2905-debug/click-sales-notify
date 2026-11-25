<?php
/**
 * Plugin Name: Click Sales Notify
 * Description: Registra o gclid, recebe postbacks de redes de afiliado, avisa cada venda (Telegram/Discord/e-mail) e exporta conversões offline para o Google Ads.
 * Version: 1.2.2
 * Requires at least: 6.3
 * Requires PHP: 7.4
 * Text Domain: csn
 */
defined('ABSPATH') || exit;

define('CSN_VERSION', '1.2.2');
define('CSN_FILE', __FILE__);
define('CSN_DIR', plugin_dir_path(__FILE__));
define('CSN_URL', plugin_dir_url(__FILE__));

foreach (['db', 'platforms', 'geo', 'notifier', 'tracker', 'postback', 'updater', 'admin'] as $csn_f) {
    require_once CSN_DIR . "includes/class-$csn_f.php";
}

register_activation_hook(__FILE__, ['CSN_DB', 'install']);

add_action('plugins_loaded', function () {
    CSN_DB::maybe_upgrade();
    CSN_Tracker::init();
    CSN_Postback::init();
    CSN_Updater::init();
    if (is_admin()) {
        CSN_Admin::init();
    }
});
