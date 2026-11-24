<?php
defined('ABSPATH') || exit;

class CSN_Postback {
    static function init() {
        add_action('rest_api_init', [__CLASS__, 'register']);
    }

    static function register() {
        register_rest_route('csn/v1', '/postback/(?P<slug>[a-z0-9-]+)', [
            'methods'             => ['GET', 'POST'],
            'permission_callback' => '__return_true',
            'callback'            => [__CLASS__, 'handle'],
        ]);
    }

    static function handle(WP_REST_Request $r) {
        $slug = (string) $r['slug'];
        $all  = CSN_Platforms::all();
        if (!isset($all[$slug])) {
            return new WP_REST_Response(['ok' => false, 'error' => 'platform'], 404);
        }
        if (!hash_equals((string) get_option('csn_token'), (string) $r->get_param('token'))) {
            return new WP_REST_Response(['ok' => false], 403);
        }

        $d = (($all[$slug]['mode'] ?? '') === 'clickbank_ins') ? self::parse_clickbank($r) : self::parse_generic($r);
        if ($d === null) {
            return new WP_REST_Response(['ok' => false, 'error' => 'payload'], 400);
        }
        if (!empty($d['ignore'])) {
            return ['ok' => true, 'ignored' => true];
        }

        $amount   = (float) $d['amount'];
        $event    = self::event($d['event_raw'], $amount);
        $amount   = $event === 'refund' ? -abs($amount) : abs($amount);
        $click_id = preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $d['click_id']);
        $order_id = mb_substr((string) $d['order_id'], 0, 100);
        if ($order_id === '') {
            // Sem ID de pedido: chave derivada, estável por 10 minutos.
            $order_id = md5($click_id . $amount . $d['product'] . floor(time() / 600));
        }
        $currency = strtoupper(substr((string) $d['currency'], 0, 3));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            $currency = 'USD';
        }

        $params = $r->get_params();
        unset($params['token']);
        $row = [
            'platform'   => $slug,
            'order_id'   => $order_id,
            'event'      => $event,
            'click_id'   => $click_id,
            'amount'     => $amount,
            'currency'   => $currency,
            'product'    => mb_substr((string) $d['product'], 0, 150),
            'raw'        => mb_substr(wp_json_encode($params), 0, 2000),
            'created_at' => current_time('mysql', true),
        ];

        global $wpdb;
        $ok = $wpdb->insert(CSN_DB::conversions(), $row, ['%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s']);
        if ($ok === false) {
            if (stripos((string) $wpdb->last_error, 'duplicate') !== false) {
                return ['ok' => true, 'duplicate' => true];
            }
            return new WP_REST_Response(['ok' => false, 'error' => 'db'], 500);
        }

        $country = $click_id !== ''
            ? (string) $wpdb->get_var($wpdb->prepare('SELECT country FROM ' . CSN_DB::clicks() . ' WHERE click_id = %s', $click_id))
            : '';
        CSN_Notifier::sale($row + ['platform_name' => $all[$slug]['name'], 'country' => $country]);

        return ['ok' => true];
    }

    // Postback comum (GET/POST) com os campos padrão definidos em CSN_Platforms::FIELDS.
    private static function parse_generic(WP_REST_Request $r) {
        $g = function ($k) use ($r) {
            $v = sanitize_text_field((string) $r->get_param($k));
            // Macro que a rede não substituiu ({sub2}, #s1#, %7B...) vira vazio.
            return preg_match('/^(\{.*\}|#.*#|%7B.*)$/i', $v) ? '' : $v;
        };
        return [
            'order_id'  => $g('order_id'),
            'product'   => $g('product'),
            'amount'    => (float) str_replace(',', '.', $g('amount')),
            'currency'  => $g('currency'),
            'click_id'  => $g('click_id'),
            'event_raw' => $g('event'),
        ];
    }

    // ClickBank INS: JSON {notification, iv} criptografado em AES-256-CBC com a chave secreta.
    private static function parse_clickbank(WP_REST_Request $r) {
        $secret = (string) get_option('csn_cb_secret', '');
        $body   = json_decode($r->get_body(), true);
        if ($secret === '' || empty($body['notification']) || empty($body['iv'])) {
            return null;
        }
        $plain = openssl_decrypt(
            base64_decode($body['notification']), 'AES-256-CBC',
            substr(sha1($secret), 0, 32), OPENSSL_RAW_DATA, base64_decode($body['iv'])
        );
        $n = json_decode(trim((string) $plain), true);
        if (!is_array($n)) {
            return null;
        }
        $type = (string) ($n['transactionType'] ?? '');
        if (!preg_match('/^(TEST_)?(SALE|BILL)$|^(RFND|CGBK)$/', $type)) {
            return ['ignore' => true];
        }
        $order = (string) ($n['receipt'] ?? '');
        if (strpos($type, 'BILL') !== false) {
            $order .= '-' . substr((string) ($n['transactionTime'] ?? ''), 0, 10);
        }
        $codes = array_values(array_filter((array) ($n['trackingCodes'] ?? [])));
        $item  = $n['lineItems'][0] ?? [];
        return [
            'order_id'  => sanitize_text_field($order),
            'product'   => sanitize_text_field($item['productTitle'] ?? ''),
            'amount'    => (float) ($n['totalAccountAmount'] ?? 0),
            'currency'  => sanitize_text_field($n['currency'] ?? 'USD'),
            'click_id'  => sanitize_text_field($codes[0] ?? ''),
            'event_raw' => $type,
        ];
    }

    private static function event($raw, $amount) {
        if (preg_match('/^test/i', $raw)) {
            return 'test';
        }
        if ($amount < 0 || preg_match('/refund|rfnd|chargeback|cgbk|reversal/i', $raw)) {
            return 'refund';
        }
        return 'sale';
    }
}
