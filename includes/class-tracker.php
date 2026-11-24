<?php
defined('ABSPATH') || exit;

class CSN_Tracker {
    static function init() {
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue']);
        add_action('rest_api_init', [__CLASS__, 'register']);
    }

    static function enqueue() {
        wp_enqueue_script('csn-tracker', CSN_URL . 'assets/tracker.js', [], CSN_VERSION, ['in_footer' => true, 'strategy' => 'defer']);
        wp_localize_script('csn-tracker', 'CSN', [
            'endpoint' => esc_url_raw(rest_url('csn/v1/click')),
            'fields'   => self::fields(),
            'inject'   => self::inject(),
        ]);
    }

    // Nomes dos campos do formulário que recebem o gclid (padrão: sub2).
    static function fields() {
        $f = preg_replace('/[^A-Za-z0-9_,\-\[\]]/', '', (string) get_option('csn_form_fields', 'sub2'));
        return $f !== '' ? $f : 'sub2';
    }

    // Seletor CSS dos formulários que ganham o campo oculto se ainda não o tiverem (vazio = não cria).
    static function inject() {
        return (string) get_option('csn_form_selector', '');
    }

    // Trecho para colar em páginas fora do WordPress.
    static function snippet() {
        $attrs = ' data-endpoint="' . esc_url(rest_url('csn/v1/click')) . '" data-fields="' . esc_attr(self::fields()) . '"';
        if (self::inject() !== '') {
            $attrs .= ' data-inject="' . esc_attr(self::inject()) . '"';
        }
        return '<script src="' . esc_url(CSN_URL . 'assets/tracker.js') . '?v=' . CSN_VERSION . '"' . $attrs . ' defer></script>';
    }

    static function register() {
        register_rest_route('csn/v1', '/click', [
            'methods'             => 'POST',
            'permission_callback' => '__return_true',
            'callback'            => [__CLASS__, 'handle'],
        ]);
    }

    static function handle(WP_REST_Request $r) {
        $id = (string) $r->get_param('click_id');
        if (!preg_match('/^[A-Za-z0-9_\-]{10,255}$/', $id)) {
            return new WP_REST_Response(['ok' => false], 400);
        }

        $ip = CSN_Geo::ip();
        $rl = 'csn_rl_' . md5($ip);
        $n  = (int) get_transient($rl);
        if ($n >= 30) {
            return new WP_REST_Response(['ok' => false], 429);
        }
        set_transient($rl, $n + 1, MINUTE_IN_SECONDS);

        global $wpdb;
        $t = CSN_DB::clicks();
        if ($wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE click_id = %s", $id))) {
            return ['ok' => true, 'duplicate' => true];
        }

        $geo  = CSN_Geo::lookup($ip);
        $page = mb_substr(sanitize_text_field((string) $r->get_param('page')), 0, 255);
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO $t (click_id, ip, country, region, isp, page, clicked_at) VALUES (%s, %s, %s, %s, %s, %s, %s)",
            $id, $ip, $geo['country'] ?? '', $geo['region'] ?? '', $geo['isp'] ?? '', $page, current_time('mysql', true)
        ));
        return ['ok' => true];
    }
}
