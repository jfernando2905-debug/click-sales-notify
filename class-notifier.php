<?php
defined('ABSPATH') || exit;

class CSN_Notifier {
    static function sale(array $c) {
        $status = (string) ($c['status'] ?? 'approved');
        if ($c['event'] === 'test') {
            $title = '🧪 Teste';
        } elseif ($c['event'] === 'refund') {
            $title = '↩️ Reembolso';
        } else {
            $titles = ['approved' => '💰 Venda aprovada', 'hold' => '⏳ Nova conversão (hold)', 'rejected' => '❌ Conversão rejeitada', 'trash' => '🗑️ Conversão em trash'];
            $title  = $titles[$status] ?? '📩 Conversão (' . $status . ')';
        }
        $text = sprintf(
            "%s — %s\nStatus: %s\nValor: %s %s\nProduto: %s\nPedido: %s\nClick ID: %s\nPaís: %s",
            $title,
            $c['platform_name'],
            $status,
            $c['currency'],
            number_format((float) $c['amount'], 2, '.', ''),
            $c['product'] !== '' ? $c['product'] : '-',
            $c['order_id'],
            $c['click_id'] !== '' ? $c['click_id'] : '(não recebido)',
            !empty($c['country']) ? $c['country'] : '-'
        );

        $tg_token = (string) get_option('csn_telegram_token', '');
        $tg_chat  = (string) get_option('csn_telegram_chat', '');
        if ($tg_token !== '' && $tg_chat !== '') {
            wp_remote_post('https://api.telegram.org/bot' . $tg_token . '/sendMessage', [
                'timeout' => 5,
                'body'    => ['chat_id' => $tg_chat, 'text' => $text],
            ]);
        }

        $discord = (string) get_option('csn_discord_webhook', '');
        if ($discord !== '') {
            wp_remote_post($discord, [
                'timeout' => 5,
                'headers' => ['Content-Type' => 'application/json'],
                'body'    => wp_json_encode(['content' => $text, 'username' => 'Click Sales Notify']),
            ]);
        }

        $mail = (string) get_option('csn_email_to', '');
        if ($mail !== '') {
            wp_mail($mail, $title . ' — ' . $c['platform_name'], $text);
        }

        self::whatsapp($text);
    }

    // WhatsApp: CallMeBot (pessoal, simples) ou API oficial da Meta (Cloud API).
    private static function whatsapp($text) {
        $provider = (string) get_option('csn_wa_provider', '');
        $to       = preg_replace('/\D/', '', (string) get_option('csn_wa_phone', ''));
        if ($provider === '' || $to === '') {
            return;
        }

        if ($provider === 'callmebot') {
            $key = (string) get_option('csn_wa_callmebot_key', '');
            if ($key === '') {
                return;
            }
            wp_remote_get('https://api.callmebot.com/whatsapp.php?' . http_build_query(['phone' => '+' . $to, 'text' => $text, 'apikey' => $key]), ['timeout' => 8]);
            return;
        }

        if ($provider === 'cloud') {
            $token = (string) get_option('csn_wa_cloud_token', '');
            $pid   = preg_replace('/\D/', '', (string) get_option('csn_wa_cloud_phone_id', ''));
            if ($token === '' || $pid === '') {
                return;
            }
            $tpl = (string) get_option('csn_wa_template', '');
            if ($tpl !== '') {
                // Parâmetro de template não aceita quebra de linha.
                $payload = ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'template', 'template' => [
                    'name'       => $tpl,
                    'language'   => ['code' => (string) get_option('csn_wa_lang', '') ?: 'pt_BR'],
                    'components' => [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => preg_replace('/\s*\n\s*/', ' | ', $text)]]]],
                ]];
            } else {
                // Texto livre só entrega dentro de 24h depois de você mandar mensagem ao número.
                $payload = ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'text', 'text' => ['body' => $text]];
            }
            $ver = apply_filters('csn_wa_graph_version', 'v23.0');
            $res = wp_remote_post("https://graph.facebook.com/$ver/$pid/messages", [
                'timeout' => 8,
                'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
                'body'    => wp_json_encode($payload),
            ]);
            if (is_wp_error($res) || wp_remote_retrieve_response_code($res) >= 300) {
                error_log('[csn] WhatsApp Cloud API falhou: ' . (is_wp_error($res) ? $res->get_error_message() : wp_remote_retrieve_body($res)));
            }
        }
    }
}
