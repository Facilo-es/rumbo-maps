// Demo only: answers the locator endpoint from fixture.json so the page works without Craft.
(function () {
  var real = window.fetch.bind(window);
  window.fetch = function (input, init) {
    var url = new URL(typeof input === 'string' ? input : input.url, location.href);
    if (url.pathname.indexOf('/actions/rumbo-maps/locator/search') === -1) return real(input, init);
    return real('fixture.json').then(function (r) { return r.json(); }).then(function (data) {
      var q = (url.searchParams.get('q') || '').toLowerCase();
      var chosen = url.searchParams.getAll('filters[type][]');
      if (q === 'nowhere') data = { ok: true, results: [], total: 0, unit: 'km', within: 25, origin: { label: 'Nowhere' }, nearest: null };
      else if (chosen.length) data.results = data.results.filter(function (r) { return chosen.every(function (c) { return (r.fields && r.fields.type || []).indexOf(c) !== -1; }); });
      return new Response(JSON.stringify(data), { headers: { 'Content-Type': 'application/json' } });
    });
  };
})();
