<?php
defined('ABSPATH') || exit;

class CSN_Geo {
    // IP do visitante. O cabeçalho do Cloudflare só vale se o site está atrás dele.
    static function ip() {
        foreach (['HTTP_CF_CONNECTING_IP', 'REMOTE_ADDR'] as $k) {
            if (empty($_SERVER[$k])) {
                continue;
            }
            $v = trim(sanitize_text_field(wp_unslash($_SERVER[$k])));
            if (filter_var($v, FILTER_VALIDATE_IP)) {
                return $v;
            }
        }
        return '';
    }

    // País, região e provedor via ipinfo.io, com cache de 30 dias por IP.
    static function lookup($ip) {
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return [];
        }
        $key = 'csn_geo_' . md5($ip);
        $hit = get_transient($key);
        if (is_array($hit)) {
            return $hit;
        }
        $token = (string) get_option('csn_ipinfo_token', '');
        $url   = 'https://ipinfo.io/' . rawurlencode($ip) . '/json' . ($token !== '' ? '?token=' . rawurlencode($token) : '');
        $res   = wp_remote_get($url, ['timeout' => 3]);
        $j     = is_wp_error($res) ? null : json_decode(wp_remote_retrieve_body($res), true);
        if (!is_array($j) || empty($j['country'])) {
            set_transient($key, [], HOUR_IN_SECONDS);
            return [];
        }
        $geo = [
            'country' => substr(sanitize_text_field($j['country']), 0, 2),
            'region'  => mb_substr(sanitize_text_field($j['region'] ?? ''), 0, 100),
            'isp'     => mb_substr(sanitize_text_field($j['org'] ?? ''), 0, 150),
        ];
        set_transient($key, $geo, 30 * DAY_IN_SECONDS);
        return $geo;
    }
}
