(function () {
  try {
    var id = new URLSearchParams(location.search).get('gclid');
    if (!id || !window.CSN) return;
    var key = 'csn_sent_' + id;
    try { if (localStorage.getItem(key)) return; } catch (e) {}
    fetch(window.CSN.endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ click_id: id, page: location.pathname }),
      keepalive: true,
      credentials: 'omit'
    }).then(function (r) {
      if (r.ok) { try { localStorage.setItem(key, '1'); } catch (e) {} }
    });
  } catch (e) {}
})();
