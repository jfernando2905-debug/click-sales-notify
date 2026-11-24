<?php
defined('ABSPATH') || exit;

class CSN_Admin {
    static function init() {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_post_csn_save', [__CLASS__, 'save']);
        add_action('admin_post_csn_regen', [__CLASS__, 'regen']);
        add_action('admin_post_csn_delete', [__CLASS__, 'delete']);
        add_action('admin_post_csn_export', [__CLASS__, 'export']);
    }

    static function menu() {
        add_menu_page('Click Sales Notify', 'Click Sales', 'manage_options', 'csn', [__CLASS__, 'render'], 'dashicons-chart-line', 58);
    }

    static function tabs() {
        return ['dashboard' => 'Dashboard', 'report' => 'Conversões', 'platforms' => 'Plataformas', 'settings' => 'Configurações', 'export' => 'Exportar Google Ads'];
    }

    private static function opt($k) {
        return esc_attr((string) get_option($k, ''));
    }

    static function render() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'dashboard';
        if (!isset(self::tabs()[$tab])) {
            $tab = 'dashboard';
        }
        echo '<div class="wrap"><h1>Click Sales Notify</h1><nav class="nav-tab-wrapper">';
        foreach (self::tabs() as $k => $label) {
            printf('<a class="nav-tab %s" href="%s">%s</a>', $tab === $k ? 'nav-tab-active' : '', esc_url(admin_url('admin.php?page=csn&tab=' . $k)), esc_html($label));
        }
        echo '</nav>';
        if (isset($_GET['saved'])) {
            echo '<div class="notice notice-success is-dismissible"><p>Salvo.</p></div>';
        }
        if (isset($_GET['checked'])) {
            echo '<div class="notice notice-info is-dismissible"><p>Verificação feita. Se houver versão nova, ela aparece em Plugins.</p></div>';
        }
        if (isset($_GET['deleted'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . absint($_GET['deleted']) . ' conversão(ões) apagada(s).</p></div>';
        }
        call_user_func([__CLASS__, 'tab_' . $tab]);
        echo '</div>';
    }

    static function tab_dashboard() {
        global $wpdb;
        $c = CSN_DB::clicks();
        $v = CSN_DB::conversions();

        $range  = isset($_GET['range']) ? sanitize_key($_GET['range']) : '7';
        $ranges = ['today' => ['Hoje', 1], '7' => ['7 dias', 7], '30' => ['30 dias', 30]];
        if (!isset($ranges[$range])) {
            $range = '7';
        }
        $days        = $ranges[$range][1];
        $start_local = (new DateTimeImmutable('today', wp_timezone()))->modify('-' . ($days - 1) . ' days');
        $start_gmt   = $start_local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        $clicks = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $c WHERE clicked_at >= %s", $start_gmt));
        $rows   = $wpdb->get_results($wpdb->prepare("SELECT platform, event, amount, currency, created_at FROM $v WHERE event IN ('sale','refund') AND created_at >= %s", $start_gmt));

        $sales = 0; $refunds = 0; $rev = []; $byday = []; $byplat = [];
        foreach ($rows as $x) {
            $cur = $x->currency;
            $amt = (float) $x->amount;
            if ($x->event === 'sale') { $sales++; } else { $refunds++; }
            $rev[$cur] = ($rev[$cur] ?? 0) + $amt;
            $day = get_date_from_gmt($x->created_at, 'Y-m-d');
            $byday[$cur][$day] = ($byday[$cur][$day] ?? 0) + $amt;
            if (!isset($byplat[$x->platform])) {
                $byplat[$x->platform] = ['sales' => 0, 'rev' => []];
            }
            if ($x->event === 'sale') { $byplat[$x->platform]['sales']++; }
            $byplat[$x->platform]['rev'][$cur] = ($byplat[$x->platform]['rev'][$cur] ?? 0) + $amt;
        }

        // Moeda principal do gráfico: a de maior receita no período.
        $main = ''; $best = -1;
        foreach ($rev as $cur => $t) {
            if (abs($t) > $best) { $best = abs($t); $main = $cur; }
        }
        $fmt = function ($cur, $n) { return $cur . ' ' . number_format((float) $n, 2, '.', ''); };

        echo '<style>.csn-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin:16px 0}.csn-card{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:14px 16px}.csn-card small{display:block;color:#646970;margin-bottom:4px}.csn-card strong{font-size:24px;line-height:1.2;display:block}.csn-card span{display:block;font-size:12px;color:#646970;margin-top:2px}.csn-box{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:14px 16px;margin:0 0 16px}.csn-box h3{margin:0 0 10px;font-size:14px}</style>';

        echo '<p style="margin-top:16px">';
        $links = [];
        foreach ($ranges as $k => $r) {
            $url = esc_url(admin_url('admin.php?page=csn&tab=dashboard&range=' . $k));
            $links[] = $k === $range ? '<strong>' . esc_html($r[0]) . '</strong>' : '<a href="' . $url . '">' . esc_html($r[0]) . '</a>';
        }
        echo implode(' &nbsp;|&nbsp; ', $links) . '</p>';

        $rate = $clicks > 0 ? number_format_i18n($sales / $clicks * 100, 1) . '%' : '-';
        echo '<div class="csn-cards">';
        printf('<div class="csn-card"><small>Cliques</small><strong>%s</strong></div>', esc_html(number_format_i18n($clicks)));
        printf('<div class="csn-card"><small>Vendas</small><strong>%s</strong><span>%s reembolso(s)</span></div>', esc_html(number_format_i18n($sales)), esc_html(number_format_i18n($refunds)));
        printf('<div class="csn-card"><small>Conversão</small><strong>%s</strong></div>', esc_html($rate));
        echo '<div class="csn-card"><small>Receita líquida</small>';
        if (!$rev) {
            echo '<strong>0</strong>';
        }
        foreach ($rev as $cur => $t) {
            printf('<strong style="font-size:%s">%s</strong>', count($rev) > 1 ? '16px' : '24px', esc_html($fmt($cur, $t)));
        }
        echo '</div></div>';

        if ($days > 1 && $main !== '') {
            $series = [];
            for ($i = 0; $i < $days; $i++) {
                $d = $start_local->modify('+' . $i . ' days')->format('Y-m-d');
                $series[$d] = $byday[$main][$d] ?? 0;
            }
            $w = 600; $h = 110; $n = count($series);
            $max = max(max($series), 0.01);
            $bw  = $w / $n;
            echo '<div class="csn-box"><h3>Receita por dia (' . esc_html($main) . ')</h3>';
            echo '<svg viewBox="0 0 ' . $w . ' ' . ($h + 18) . '" width="100%" role="img" aria-label="Receita por dia">';
            $i = 0;
            foreach ($series as $d => $val) {
                $bh = max($val, 0) / $max * ($h - 8);
                printf('<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" rx="2" fill="#2271b1"><title>%s: %s</title></rect>', $i * $bw + 2, $h - $bh, max($bw - 4, 1), max($bh, 1), esc_html(date_i18n('d/m', strtotime($d))), esc_html($fmt($main, $val)));
                $i++;
            }
            $keys = array_keys($series);
            printf('<text x="0" y="%d" font-size="11" fill="#646970">%s</text>', $h + 14, esc_html(date_i18n('d/m', strtotime($keys[0]))));
            printf('<text x="%d" y="%d" font-size="11" fill="#646970" text-anchor="end">%s</text>', $w, $h + 14, esc_html(date_i18n('d/m', strtotime($keys[$n - 1]))));
            echo '</svg></div>';
        }

        echo '<div class="csn-box"><h3>Por rede</h3>';
        if (!$byplat) {
            echo '<p>Sem conversões no período.</p>';
        } else {
            uasort($byplat, function ($a, $b) { return $b['sales'] <=> $a['sales']; });
            $all = CSN_Platforms::all();
            echo '<table class="widefat striped"><thead><tr><th>Rede</th><th>Vendas</th><th>Receita</th></tr></thead><tbody>';
            foreach ($byplat as $slug => $d) {
                $parts = [];
                foreach ($d['rev'] as $cur => $t) {
                    $parts[] = $fmt($cur, $t);
                }
                printf('<tr><td>%s</td><td>%s</td><td>%s</td></tr>', esc_html($all[$slug]['name'] ?? $slug), esc_html($d['sales']), esc_html(implode(' | ', $parts)));
            }
            echo '</tbody></table>';
        }
        echo '</div>';
    }

    static function tab_report() {
        global $wpdb;
        $c    = CSN_DB::clicks();
        $v    = CSN_DB::conversions();
        $rows = $wpdb->get_results("SELECT v.*, c.country FROM $v v LEFT JOIN $c c ON c.click_id = v.click_id ORDER BY v.id DESC LIMIT 100");
        $all  = CSN_Platforms::all();

        echo '<p>Últimas 100 conversões.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'Apagar as conversões selecionadas? Isso não pode ser desfeito.\')">';
        wp_nonce_field('csn_delete');
        echo '<input type="hidden" name="action" value="csn_delete"><p><button class="button">Apagar selecionadas</button></p>';
        echo '<table class="widefat striped"><thead><tr><td class="check-column"><input type="checkbox" onclick="var s=this.checked;document.querySelectorAll(\'.csn-row\').forEach(function(c){c.checked=s})"></td><th>Data</th><th>Rede</th><th>Evento</th><th>Pedido</th><th>Produto</th><th>Valor</th><th>Click ID</th><th>País</th></tr></thead><tbody>';
        if (!$rows) {
            echo '<tr><td colspan="9">Nenhuma conversão ainda.</td></tr>';
        }
        foreach ($rows as $x) {
            printf(
                '<tr><th scope="row" class="check-column"><input type="checkbox" class="csn-row" name="ids[]" value="%d"></th><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s %s</td><td>%s</td><td>%s</td></tr>',
                (int) $x->id,
                esc_html(get_date_from_gmt($x->created_at, 'd/m/Y H:i')),
                esc_html($all[$x->platform]['name'] ?? $x->platform),
                esc_html($x->event), esc_html($x->order_id), esc_html($x->product),
                esc_html($x->currency), esc_html(number_format((float) $x->amount, 2, '.', '')),
                esc_html($x->click_id), esc_html((string) $x->country)
            );
        }
        echo '</tbody></table></form>';
    }

    static function tab_platforms() {
        echo '<p>Cole a URL de cada rede no campo de postback. As macros já vêm preenchidas para as redes conhecidas e podem ser editadas. Salve as alterações antes de copiar.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('csn_save');
        echo '<input type="hidden" name="action" value="csn_save"><input type="hidden" name="tab" value="platforms">';
        foreach (CSN_Platforms::all() as $slug => $p) {
            echo '<hr><h2>' . esc_html($p['name']) . '</h2><p class="description">' . esc_html($p['hint']) . '</p>';
            if (isset($p['macros'])) {
                echo '<table class="form-table"><tbody>';
                $m = CSN_Platforms::macros($slug);
                foreach (CSN_Platforms::FIELDS as $f => $label) {
                    printf('<tr><th>%s</th><td><input type="text" class="regular-text" name="macros[%s][%s]" value="%s"></td></tr>', esc_html($label), esc_attr($slug), esc_attr($f), esc_attr($m[$f] ?? ''));
                }
                echo '</tbody></table>';
            }
            echo '<p style="display:flex;gap:8px;align-items:center"><input type="text" id="csn-url-' . esc_attr($slug) . '" class="large-text code" readonly onfocus="this.select()" value="' . esc_attr(CSN_Platforms::url($slug)) . '"><button type="button" class="button csn-copy" data-target="csn-url-' . esc_attr($slug) . '">Copiar</button></p>';
        }
        echo '<p><button class="button button-primary">Salvar macros</button></p></form>';
        echo '<hr><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'Os postbacks atuais deixam de funcionar. Continuar?\')">';
        wp_nonce_field('csn_regen');
        echo '<input type="hidden" name="action" value="csn_regen"><button class="button">Gerar novo token</button></form>';
        echo <<<'JS'
<script>
document.querySelectorAll('.csn-copy').forEach(function (b) {
  b.addEventListener('click', function () {
    var i = document.getElementById(b.dataset.target);
    i.select();
    var done = function () {
      var o = b.textContent;
      b.textContent = 'Copiado!';
      setTimeout(function () { b.textContent = o; }, 1500);
    };
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(i.value).then(done);
    } else {
      document.execCommand('copy');
      done();
    }
  });
});
</script>
JS;
    }

    static function tab_settings() {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('csn_save');
        echo '<input type="hidden" name="action" value="csn_save"><input type="hidden" name="tab" value="settings"><table class="form-table"><tbody>';
        $fields = [
            'csn_telegram_token'   => 'Telegram: token do bot',
            'csn_telegram_chat'    => 'Telegram: chat ID',
            'csn_discord_webhook'  => 'Discord: URL do webhook',
            'csn_email_to'         => 'E-mail para avisos',
            'csn_wa_phone'         => 'WhatsApp: número que recebe (com DDI, só dígitos)',
            'csn_wa_callmebot_key' => 'WhatsApp / CallMeBot: apikey',
            'csn_wa_cloud_token'   => 'WhatsApp / API oficial: token de acesso',
            'csn_wa_cloud_phone_id' => 'WhatsApp / API oficial: Phone Number ID',
            'csn_wa_template'      => 'WhatsApp / API oficial: nome do template (opcional)',
            'csn_wa_lang'          => 'WhatsApp / API oficial: idioma do template (ex.: pt_BR)',
            'csn_cb_secret'        => 'ClickBank: chave secreta do INS',
            'csn_github_repo'      => 'Atualizações: repositório GitHub (usuário/repo)',
            'csn_form_fields'      => 'Formulário: nome do campo que recebe o gclid (ex.: sub2)',
            'csn_form_selector'    => 'Formulário: criar o campo oculto nos formulários com este seletor CSS (opcional, ex.: #pedido)',
            'csn_ipinfo_token'     => 'ipinfo.io: token (opcional)',
            'csn_gads_name'        => 'Google Ads: nome da conversão',
            'csn_gads_tz'          => 'Google Ads: fuso da conta (ex.: America/Sao_Paulo)',
        ];
        $prov = (string) get_option('csn_wa_provider', '');
        echo '<tr><th>WhatsApp: provedor</th><td><select name="csn_wa_provider">';
        foreach (['' => 'Desligado', 'callmebot' => 'CallMeBot (pessoal)', 'cloud' => 'API oficial da Meta (Cloud API)'] as $val => $lab) {
            printf('<option value="%s"%s>%s</option>', esc_attr($val), selected($prov, $val, false), esc_html($lab));
        }
        echo '</select></td></tr>';
        foreach ($fields as $k => $label) {
            printf('<tr><th>%s</th><td><input type="text" class="regular-text" name="%s" value="%s" autocomplete="off"></td></tr>', esc_html($label), esc_attr($k), self::opt($k));
        }
        echo '</tbody></table><p><button class="button button-primary">Salvar</button></p></form>';

        echo '<hr><h2>Página fora do WordPress</h2><p>Cole este código antes do <code>&lt;/body&gt;</code> da página. Ele guarda o gclid, preenche o campo do formulário e registra o clique neste site.</p>';
        echo '<p><textarea readonly rows="3" class="large-text code" onclick="this.select()">' . esc_textarea(CSN_Tracker::snippet()) . '</textarea></p>';

        echo '<hr><p>Versão instalada: <strong>' . esc_html(CSN_VERSION) . '</strong></p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('csn_check_update');
        echo '<input type="hidden" name="action" value="csn_check_update"><button class="button">Verificar atualizações agora</button></form>';
    }

    static function tab_export() {
        echo '<p>Gera o CSV de conversões offline (apenas vendas com gclid e valor maior que zero).</p>';
        echo '<form method="get" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('csn_export');
        echo '<input type="hidden" name="action" value="csn_export"><table class="form-table"><tbody>';
        echo '<tr><th>De</th><td><input type="date" name="from"></td></tr><tr><th>Até</th><td><input type="date" name="to"></td></tr>';
        echo '<tr><th>Rede</th><td><select name="platform"><option value="">Todas</option>';
        foreach (CSN_Platforms::all() as $slug => $p) {
            printf('<option value="%s">%s</option>', esc_attr($slug), esc_html($p['name']));
        }
        echo '</select></td></tr></tbody></table><p><button class="button button-primary">Baixar CSV</button></p></form>';
    }

    static function save() {
        if (!current_user_can('manage_options')) {
            wp_die('Sem permissão.');
        }
        check_admin_referer('csn_save');
        $tab = isset($_POST['tab']) ? sanitize_key($_POST['tab']) : 'settings';

        if ($tab === 'platforms') {
            $m   = [];
            $in  = isset($_POST['macros']) && is_array($_POST['macros']) ? wp_unslash($_POST['macros']) : [];
            foreach (CSN_Platforms::all() as $slug => $p) {
                if (!isset($p['macros'])) {
                    continue;
                }
                foreach (array_keys(CSN_Platforms::FIELDS) as $f) {
                    $m[$slug][$f] = sanitize_text_field($in[$slug][$f] ?? '');
                }
            }
            update_option('csn_macros', $m);
        } else {
            foreach (['csn_telegram_token', 'csn_telegram_chat', 'csn_cb_secret', 'csn_ipinfo_token', 'csn_gads_name', 'csn_gads_tz', 'csn_wa_callmebot_key', 'csn_wa_cloud_token', 'csn_wa_template', 'csn_wa_lang'] as $k) {
                update_option($k, sanitize_text_field(wp_unslash($_POST[$k] ?? '')));
            }
            $ff = preg_replace('/[^A-Za-z0-9_,\-\[\]]/', '', sanitize_text_field(wp_unslash($_POST['csn_form_fields'] ?? '')));
            update_option('csn_form_fields', $ff !== '' ? $ff : 'sub2');
            update_option('csn_form_selector', sanitize_text_field(wp_unslash($_POST['csn_form_selector'] ?? '')));
            $prov = sanitize_key($_POST['csn_wa_provider'] ?? '');
            update_option('csn_wa_provider', in_array($prov, ['callmebot', 'cloud'], true) ? $prov : '');
            update_option('csn_wa_phone', preg_replace('/\D/', '', sanitize_text_field(wp_unslash($_POST['csn_wa_phone'] ?? ''))));
            update_option('csn_wa_cloud_phone_id', preg_replace('/\D/', '', sanitize_text_field(wp_unslash($_POST['csn_wa_cloud_phone_id'] ?? ''))));
            $repo = sanitize_text_field(wp_unslash($_POST['csn_github_repo'] ?? ''));
            update_option('csn_github_repo', preg_match('#^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+$#', $repo) ? $repo : '');
            delete_transient(CSN_Updater::CACHE);
            update_option('csn_email_to', sanitize_email(wp_unslash($_POST['csn_email_to'] ?? '')));
            $hook = esc_url_raw(wp_unslash($_POST['csn_discord_webhook'] ?? ''), ['https']);
            update_option('csn_discord_webhook', preg_match('#^https://(discord|discordapp)\.com/api/webhooks/#', $hook) ? $hook : '');
        }
        wp_safe_redirect(add_query_arg(['page' => 'csn', 'tab' => $tab, 'saved' => 1], admin_url('admin.php')));
        exit;
    }

    static function delete() {
        if (!current_user_can('manage_options')) {
            wp_die('Sem permissão.');
        }
        check_admin_referer('csn_delete');
        $ids = array_filter(array_map('absint', (array) ($_POST['ids'] ?? [])));
        $n   = 0;
        if ($ids) {
            global $wpdb;
            $ph = implode(',', array_fill(0, count($ids), '%d'));
            $n  = (int) $wpdb->query($wpdb->prepare('DELETE FROM ' . CSN_DB::conversions() . " WHERE id IN ($ph)", $ids));
        }
        wp_safe_redirect(add_query_arg(['page' => 'csn', 'tab' => 'report', 'deleted' => $n], admin_url('admin.php')));
        exit;
    }

    static function regen() {
        if (!current_user_can('manage_options')) {
            wp_die('Sem permissão.');
        }
        check_admin_referer('csn_regen');
        update_option('csn_token', wp_generate_password(32, false));
        wp_safe_redirect(add_query_arg(['page' => 'csn', 'tab' => 'platforms', 'saved' => 1], admin_url('admin.php')));
        exit;
    }

    static function export() {
        if (!current_user_can('manage_options')) {
            wp_die('Sem permissão.');
        }
        check_admin_referer('csn_export');
        global $wpdb;
        $v = CSN_DB::conversions();

        $from = sanitize_text_field(wp_unslash($_GET['from'] ?? ''));
        $to   = sanitize_text_field(wp_unslash($_GET['to'] ?? ''));
        $plat = sanitize_key($_GET['platform'] ?? '');
        $where = ["event = 'sale'", "click_id <> ''", 'amount > 0'];
        $args  = [];
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $where[] = 'created_at >= %s'; $args[] = get_gmt_from_date($from . ' 00:00:00'); }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   { $where[] = 'created_at <= %s'; $args[] = get_gmt_from_date($to . ' 23:59:59'); }
        if ($plat !== '') { $where[] = 'platform = %s'; $args[] = $plat; }
        $sql  = "SELECT click_id, created_at, amount, currency FROM $v WHERE " . implode(' AND ', $where) . ' ORDER BY created_at ASC';
        $rows = $args ? $wpdb->get_results($wpdb->prepare($sql, $args)) : $wpdb->get_results($sql);

        try {
            $tz = new DateTimeZone((string) get_option('csn_gads_tz') ?: wp_timezone_string());
        } catch (Exception $e) {
            $tz = wp_timezone();
        }
        $sec  = $tz->getOffset(new DateTime('now', new DateTimeZone('UTC')));
        $abs  = abs($sec);
        $off  = ($sec < 0 ? '-' : '+') . sprintf('%02d:%02d', intdiv($abs, 3600), intdiv($abs % 3600, 60));
        $fixed = new DateTimeZone($off);
        $name  = (string) get_option('csn_gads_name') ?: 'Venda Afiliado';

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="conversoes-google-ads-' . gmdate('Ymd-His') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Parameters:TimeZone=' . str_replace(':', '', $off)]);
        fputcsv($out, ['Google Click ID', 'Conversion Name', 'Conversion Time', 'Conversion Value', 'Conversion Currency']);
        foreach ($rows as $r) {
            $d = new DateTime($r->created_at, new DateTimeZone('UTC'));
            $d->setTimezone($fixed);
            fputcsv($out, [$r->click_id, $name, $d->format('Y-m-d H:i:s'), number_format((float) $r->amount, 2, '.', ''), $r->currency]);
        }
        fclose($out);
        exit;
    }
}
