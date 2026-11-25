<?php
defined('ABSPATH') || exit;

// Atualização pelo GitHub Releases: o WordPress mostra "Atualizar agora" em Plugins.
class CSN_Updater {
    const CACHE = 'csn_latest_release';
    const ASSET = 'click-sales-notify.zip';

    static function init() {
        add_filter('pre_set_site_transient_update_plugins', [__CLASS__, 'check']);
        add_filter('plugins_api', [__CLASS__, 'info'], 10, 3);
        add_action('admin_post_csn_check_update', [__CLASS__, 'force']);
    }

    static function repo() {
        $r = (string) get_option('csn_github_repo', '');
        return preg_match('#^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+$#', $r) ? $r : '';
    }

    // Última release publicada (cache de 12h; 1h se a consulta falhar). Guarda também o motivo da falha.
    static function latest() {
        $repo = self::repo();
        if ($repo === '') {
            return [];
        }
        $c = get_transient(self::CACHE);
        if (is_array($c) && ($c['repo'] ?? '') === $repo) {
            return $c['data'];
        }
        $res  = wp_remote_get("https://api.github.com/repos/$repo/releases/latest", [
            'timeout' => 8,
            'headers' => ['Accept' => 'application/vnd.github+json', 'User-Agent' => 'click-sales-notify'],
        ]);
        $data = [];
        if (is_wp_error($res)) {
            $diag = 'Não consegui acessar o GitHub: ' . $res->get_error_message();
        } else {
            $code = (int) wp_remote_retrieve_response_code($res);
            $j    = json_decode(wp_remote_retrieve_body($res), true);
            if ($code === 404) {
                $diag = "Nenhuma release publicada em $repo (rascunhos e pré-releases não contam). Publique uma em GitHub → Releases.";
            } elseif ($code === 403 || $code === 429) {
                $diag = 'O GitHub limitou as consultas deste servidor. Tente de novo mais tarde.';
            } elseif ($code !== 200 || !is_array($j) || empty($j['tag_name'])) {
                $diag = "O GitHub respondeu HTTP $code sem dados de release.";
            } else {
                $pkg = '';
                foreach ((array) ($j['assets'] ?? []) as $a) {
                    if (($a['name'] ?? '') === self::ASSET) {
                        $pkg = (string) ($a['browser_download_url'] ?? '');
                        break;
                    }
                }
                if (!preg_match('#^https://github\.com/#', $pkg)) {
                    $diag = 'A release ' . $j['tag_name'] . ' existe, mas não tem o anexo ' . self::ASSET . '. Confira se o workflow rodou (aba Actions) ou anexe o zip manualmente.';
                } else {
                    $diag = '';
                    $data = [
                        'version' => ltrim((string) $j['tag_name'], 'vV'),
                        'package' => $pkg,
                        'url'     => (string) ($j['html_url'] ?? ''),
                        'notes'   => (string) ($j['body'] ?? ''),
                    ];
                }
            }
        }
        set_transient(self::CACHE, ['repo' => $repo, 'data' => $data, 'diag' => $diag], $data ? 12 * HOUR_IN_SECONDS : HOUR_IN_SECONDS);
        return $data;
    }

    // Texto para o botão "Verificar atualizações agora": diz o resultado ou o motivo de não haver aviso.
    static function diagnose() {
        if (self::repo() === '') {
            return 'Preencha o campo "Atualizações: repositório GitHub" (usuário/repo) e salve.';
        }
        $l = self::latest();
        if (!$l) {
            $c = get_transient(self::CACHE);
            return (is_array($c) && !empty($c['diag'])) ? $c['diag'] : 'Sem informação da release.';
        }
        if (version_compare($l['version'], CSN_VERSION, '>')) {
            return 'Atualização disponível: ' . $l['version'] . ' (instalada: ' . CSN_VERSION . '). Veja em Plugins.';
        }
        return 'Sem atualização: a release mais recente é a ' . $l['version'] . ' e a instalada é a ' . CSN_VERSION . '.';
    }

    static function check($transient) {
        if (!is_object($transient)) {
            return $transient;
        }
        $l = self::latest();
        if (!$l) {
            return $transient;
        }
        $base = plugin_basename(CSN_FILE);
        $item = (object) [
            'id'          => $base,
            'slug'        => 'click-sales-notify',
            'plugin'      => $base,
            'new_version' => $l['version'],
            'url'         => $l['url'],
            'package'     => $l['package'],
        ];
        if (version_compare($l['version'], CSN_VERSION, '>')) {
            $transient->response[$base] = $item;
        } else {
            $transient->no_update[$base] = $item;
        }
        return $transient;
    }

    static function info($res, $action, $args) {
        if ($action !== 'plugin_information' || ($args->slug ?? '') !== 'click-sales-notify') {
            return $res;
        }
        $l = self::latest();
        if (!$l) {
            return $res;
        }
        return (object) [
            'name'          => 'Click Sales Notify',
            'slug'          => 'click-sales-notify',
            'version'       => $l['version'],
            'download_link' => $l['package'],
            'homepage'      => $l['url'],
            'sections'      => ['changelog' => nl2br(esc_html($l['notes']))],
        ];
    }

    static function force() {
        if (!current_user_can('manage_options')) {
            wp_die('Sem permissão.');
        }
        check_admin_referer('csn_check_update');
        delete_transient(self::CACHE);
        delete_site_transient('update_plugins');
        if (function_exists('wp_update_plugins')) {
            wp_update_plugins();
        }
        set_transient('csn_update_msg', self::diagnose(), 120);
        wp_safe_redirect(add_query_arg(['page' => 'csn', 'tab' => 'settings', 'checked' => 1], admin_url('admin.php')));
        exit;
    }
}
