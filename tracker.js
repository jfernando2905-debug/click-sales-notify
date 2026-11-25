/*
 * Click Sales Notify — rastreador de gclid.
 * 1) Guarda o gclid da URL (90 dias) e registra o clique no servidor.
 * 2) Preenche campos de formulário com o gclid (ex.: input name="sub2").
 * 3) Troca o marcador [sclid] nos links pelo gclid.
 * Em página fora do WordPress, use data-endpoint e data-fields no <script>.
 */
(function () {
  'use strict';
  var script = document.currentScript;
  var cfg = window.CSN || {};
  var endpoint = (script && script.getAttribute('data-endpoint')) || cfg.endpoint || '';
  var attr = script && script.getAttribute('data-fields');
  var fields = (attr ? attr.split(',') : (cfg.fields || []))
    .map(function (f) { return String(f).trim(); })
    .filter(function (f) { return /^[A-Za-z0-9_\-]+$/.test(f); });

  var KEY = 'csn_gclid', TTL = 90 * 864e5, mem = '';

  function read() {
    try {
      var j = JSON.parse(localStorage.getItem(KEY) || 'null');
      if (j && j.id && Date.now() - j.t < TTL) return j.id;
    } catch (e) {}
    return mem;
  }
  function write(id) {
    mem = id;
    try { localStorage.setItem(KEY, JSON.stringify({ id: id, t: Date.now() })); } catch (e) {}
  }

  var fromUrl = '';
  try { fromUrl = new URLSearchParams(location.search).get('gclid') || ''; } catch (e) {}
  if (!/^[A-Za-z0-9_\-]{10,255}$/.test(fromUrl)) fromUrl = '';
  if (fromUrl) write(fromUrl);

  function current() { return fromUrl || read(); }
  window.csnClickId = current; // para formulários enviados por JS próprio

  function fill(root) {
    var v = current();
    if (!v) return;
    var sel = ['[data-csn-click]'];
    fields.forEach(function (n) {
      sel.push('input[name="' + n + '"]');
      sel.push('[data-csn-field="' + n + '"]');
    });
    (root || document).querySelectorAll(sel.join(',')).forEach(function (el) {
      if ('value' in el) el.value = v;
    });
  }

  function links() {
    var v = current();
    if (!v) return;
    document.querySelectorAll('a[href*="sclid"]').forEach(function (a) {
      var h = a.getAttribute('href');
      var val = /[?&]tid=/.test(h) ? v.replace(/-/g, '_d_') : v; // tid do ClickBank não aceita hífen
      a.setAttribute('href', h.replace(/\[sclid\]|%5Bsclid%5D/gi, val));
    });
  }

  function register() {
    if (!fromUrl || !endpoint) return;
    var flag = 'csn_sent_' + fromUrl;
    try { if (localStorage.getItem(flag)) return; } catch (e) {}
    fetch(endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ click_id: fromUrl, page: location.pathname }),
      keepalive: true,
      credentials: 'omit'
    }).then(function (r) {
      if (r.ok) { try { localStorage.setItem(flag, '1'); } catch (e) {} }
    }).catch(function () {});
  }

  function init() { fill(); links(); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
  document.addEventListener('submit', function (e) { fill(e.target); }, true);
  register();
})();
