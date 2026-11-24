<?php
defined('ABSPATH') || exit;

class CSN_Platforms {
    // Campos que o postback entende. Cada rede preenche com as próprias macros.
    const FIELDS = [
        'order_id' => 'ID do pedido',
        'product'  => 'Produto',
        'amount'   => 'Comissão',
        'currency' => 'Moeda',
        'click_id' => 'Click ID (gclid)',
        'event'    => 'Evento (opcional)',
    ];

    static function all() {
        $empty = array_fill_keys(array_keys(self::FIELDS), '');
        return [
            'drcash' => ['name' => 'Dr.Cash', 'hint' => 'Envie o gclid no parâmetro sub2.',
                'macros' => array_merge($empty, ['order_id' => '{uuid}', 'product' => '{offer}', 'amount' => '{payment}', 'currency' => '{currency}', 'click_id' => '{sub2}'])],
            'clickbank' => ['name' => 'ClickBank (postback S2S)', 'hint' => 'Envie o gclid no extclid.',
                'macros' => array_merge($empty, ['order_id' => '{order_id}', 'amount' => '{affiliate_earnings}', 'currency' => '{order_currency}', 'click_id' => '{extclid}', 'event' => '{event_type}'])],
            'clickbank-ins' => ['name' => 'ClickBank (INS)', 'mode' => 'clickbank_ins',
                'hint' => 'Use esta URL no INS e preencha a chave secreta em Configurações. O click ID vem do primeiro tracking code.'],
            'buygoods' => ['name' => 'BuyGoods', 'hint' => 'Envie o gclid no SUBID2.',
                'macros' => array_merge($empty, ['order_id' => '{ORDERID}', 'product' => '{PRODUCT_CODENAME}', 'amount' => '{COMMISSION_AMOUNT}', 'currency' => 'USD', 'click_id' => '{SUBID2}'])],
            'webvork' => ['name' => 'Webvork', 'hint' => 'Envie o gclid no utm_campaign.',
                'macros' => array_merge($empty, ['order_id' => '{lead_guid}', 'amount' => '{money}', 'currency' => '{currency}', 'click_id' => '{utm_campaign}'])],
            'mediascalers' => ['name' => 'MediaScalers', 'hint' => 'Preencha com as macros do postback da rede.', 'macros' => $empty],
            'netvork' => ['name' => 'Netvork', 'hint' => 'Preencha com as macros do postback da rede.', 'macros' => $empty],
        ];
    }

    static function macros($slug) {
        $all = self::all();
        if (!isset($all[$slug]['macros'])) {
            return [];
        }
        $saved = get_option('csn_macros', []);
        $mine  = (is_array($saved) && isset($saved[$slug]) && is_array($saved[$slug])) ? $saved[$slug] : [];
        return array_merge($all[$slug]['macros'], $mine);
    }

    // As macros ficam sem url-encode de propósito: a rede troca {macro} pelo valor.
    static function url($slug) {
        $u   = rest_url('csn/v1/postback/' . $slug);
        $u  .= (strpos($u, '?') === false ? '?' : '&') . 'token=' . rawurlencode((string) get_option('csn_token'));
        foreach (self::macros($slug) as $field => $macro) {
            if ($macro !== '') {
                $u .= '&' . $field . '=' . $macro;
            }
        }
        return $u;
    }
}
