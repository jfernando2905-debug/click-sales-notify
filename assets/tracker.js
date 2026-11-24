/*! Click Sales Notify - tracker 1.2.0 (script próprio, sem dependências) */
(function () {
  'use strict';
  var cs = document.currentScript;
  var cfg = window.CSN || {};
  var attr = function (n) { return cs && cs.getAttribute ? cs.getAttribute(n) : null; };

  var endpoint = attr('data-endpoint') || cfg.endpoint || '';
  var fields = (attr('data-fields') || cfg.fields || 'sub2').split(',')
    .map(function (s) { return s.trim(); }).filter(Boolean);
  var inject = attr('data-inject') || cfg.inject || ''; // seletor dos formulários que recebem o campo oculto
  var KEY = 'csn_cid', SENT = 'csn_sent_', DAYS = 90;
  var RE = /^[A-Za-z0-9_\-]{10,255}$/;

  function store(k, v) { try { localStorage.setItem(k, v); } catch (e) {} }
  function load(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
  function setCookie(v) {
    try {
      document.cookie = KEY + '=' + encodeURIComponent(v) + ';max-age=' + DAYS * 86400 + ';path=/;SameSite=Lax';
    } catch (e) {}
  }
  function getCookie() {
    var m = document.cookie.match(new RegExp('(?:^|; )' + KEY + '=([^;]*)'));
    return m ? decodeURIComponent(m[1]) : null;
  }

  // 1) Guarda o gclid da URL (o clique mais recente vale) e o recupera nas visitas seguintes.
  var fromUrl = null;
  try { fromUrl = new URLSearchParams(location.search).get('gclid'); } catch (e) {}
  if (fromUrl && RE.test(fromUrl)) { store(KEY, fromUrl); setCookie(fromUrl); }
  function getId() {
    var id = fromUrl && RE.test(fromUrl) ? fromUrl : (load(KEY) || getCookie());
    return id && RE.test(id) ? id : '';
  }

  // 2) Preenche o campo do formulário. Dispara "input" para frameworks (React, Vue) enxergarem o valor.
  var nativeSet = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set;
  function setValue(el, v) {
    if (el.value === v) return;
    nativeSet.call(el, v);
    el.dispatchEvent(new Event('input', { bubbles: true }));
    el.dispatchEvent(new Event('change', { bubbles: true }));
  }
  function fillForm(form, id) {
    fields.forEach(function (name) {
      var el = form.elements ? form.elements[name] : null;
      if (el && el.length && !el.tagName) el = el[0]; // RadioNodeList
      if (!el && inject) {
        var ok = false;
        try { ok = form.matches(inject); } catch (e) {}
        if (ok) {
          el = document.createElement('input');
          el.type = 'hidden';
          el.name = name;
          form.appendChild(el);
        }
      }
      if (el && el.tagName === 'INPUT') setValue(el, id);
    });
  }
  function fillAll() {
    var id = getId();
    if (!id) return;
    var forms = document.forms;
    for (var i = 0; i < forms.length; i++) fillForm(forms[i], id);
  }

  // 3) Troca [sclid] nos links de afiliado. No parâmetro tid (ClickBank) o hífen vira _d_.
  function fixLinks() {
    var id = getId();
    if (!id) return;
    var links = document.querySelectorAll('a[href*="[sclid]"],a[href*="%5Bsclid%5D"]');
    for (var i = 0; i < links.length; i++) {
      var href = links[i].getAttribute('href');
      var out = href.replace(/([?&]tid=)(?:\[sclid\]|%5Bsclid%5D)/gi, function (m, p) {
        return p + id.replace(/-/g, '_d_');
      }).replace(/\[sclid\]|%5Bsclid%5D/gi, encodeURIComponent(id));
      if (out !== href) links[i].setAttribute('href', out);
    }
  }

  // 4) Registra o clique no servidor (uma vez por gclid). Formulário simples: não gera preflight de CORS.
  function register() {
    var id = getId();
    if (!id || !endpoint || !fromUrl) return;
    if (load(SENT + id)) return;
    var body = new URLSearchParams();
    body.set('click_id', id);
    var external = false;
    try { external = new URL(endpoint, location.href).host !== location.host; } catch (e) {}
    body.set('page', (external ? location.host : '') + location.pathname);
    fetch(endpoint, { method: 'POST', body: body, keepalive: true, credentials: 'omit' })
      .then(function (r) { if (r.ok) store(SENT + id, '1'); })
      .catch(function () {});
  }

  function run() { fillAll(); fixLinks(); }

  // Garante o valor mesmo se a página recriar o formulário ou o enviar por JavaScript.
  document.addEventListener('submit', function (e) {
    var id = getId();
    if (id && e.target && e.target.tagName === 'FORM') fillForm(e.target, id);
  }, true);

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run);
  else run();
  try {
    var t;
    new MutationObserver(function () { clearTimeout(t); t = setTimeout(run, 150); })
      .observe(document.documentElement, { childList: true, subtree: true });
  } catch (e) {}

  register();

  // Para páginas com JavaScript próprio: window.CSNTracker.id() devolve o gclid guardado.
  window.CSNTracker = { id: getId, fill: run };
})();
