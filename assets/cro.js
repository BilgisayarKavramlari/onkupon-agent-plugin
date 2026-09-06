(function () {
  function report(panel) {
    var endpoint = panel.getAttribute('data-impression-url');
    var token = panel.getAttribute('data-impression-token');
    var postId = panel.getAttribute('data-post-id');
    var productIds = panel.getAttribute('data-product-ids');
    if (!endpoint || !token || !postId || !productIds) return;

    var storageKey = 'onkupon_cro_seen_' + postId + '_' + productIds;
    try {
      if (window.sessionStorage && sessionStorage.getItem(storageKey)) return;
      if (window.sessionStorage) sessionStorage.setItem(storageKey, '1');
    } catch (e) {}

    window.fetch(endpoint, {
      method: 'POST',
      credentials: 'same-origin',
      keepalive: true,
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({
        post_id: parseInt(postId, 10),
        product_ids: productIds.split(',').map(function (id) { return parseInt(id, 10); }),
        placement: 'in_article',
        token: token
      })
    }).catch(function () {});
  }

  function start() {
    document.querySelectorAll('.onkupon-cro-recommendations[data-impression-url]').forEach(report);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
}());
