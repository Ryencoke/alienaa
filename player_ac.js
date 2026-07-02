/* player_ac.js — shared "type a handle/ID, get live suggestions" autocomplete.
   Used everywhere a player picks another player: trade, messages, bank transfer,
   admin grant/lookup, updates credit, PvP target, apartment rentals. Suggestions
   come from players_search.php?q=, which returns [{id,username}]. Selecting a
   suggestion fills the input with the username (existing server-side "handle or
   ID" lookups keep working unchanged) and, if a confirm element is given, shows
   a small confirmed line with name + role + ID + level via players_search.php?lookup=. */
window.PlayerAC = {
  _instances: [],   // {input, list} pairs for every active autocomplete
  _docBound: false, // ensures the document click handler is bound only once
  attach: function (input, list, opts) {
    if (!input || !list) return;
    opts = opts || {};

    // Bind the outside-click-to-close handler on document ONCE. Previously this
    // was added on every attach() call and never removed, so under AJAX nav the
    // listeners accumulated. Now a single handler iterates all live instances.
    if (!PlayerAC._docBound) {
      PlayerAC._docBound = true;
      document.addEventListener('click', function (e) {
        PlayerAC._instances.forEach(function (inst) {
          if (!inst.input.contains(e.target) && !inst.list.contains(e.target)) inst.list.style.display = 'none';
        });
      });
    }
    PlayerAC._instances.push({ input: input, list: list });
    var confirmEl = opts.confirm || null;
    var cur = -1, items = [];

    function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }

    function setConfirm(val) {
      if (!confirmEl || !val) { if (confirmEl) { confirmEl.style.display = 'none'; confirmEl.innerHTML = ''; } return; }
      fetch('players_search.php?lookup=' + encodeURIComponent(val), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (!d) { confirmEl.innerHTML = '<span style="color:var(--neon2)">&#9888; Player not found.</span>'; confirmEl.style.display = 'block'; return; }
          var roles = { admin: '<span style="color:#e8d44d;font-weight:700">[Admin]</span>', manager: '<span style="color:#e23b3b;font-weight:700">[Manager]</span>', moderator: '<span style="color:#4d9be8;font-weight:700">[Mod]</span>', chatmod: '<span style="color:#3bcf63;font-weight:700">[Chat Mod]</span>' };
          var rb = roles[d.role] || '';
          confirmEl.innerHTML = '&#10003; <b style="color:var(--accent)">' + esc(d.username) + '</b> ' + rb + ' &middot; ID #' + d.id + (d.level != null ? ' &middot; Level ' + d.level : '');
          confirmEl.style.display = 'block';
        }).catch(function () { confirmEl.style.display = 'none'; });
    }

    function render(results) {
      items = results || []; cur = -1;
      if (!items.length) { list.style.display = 'none'; return; }
      list.innerHTML = '';
      items.forEach(function (it) {
        var row = document.createElement('div');
        row.className = 'ac-item';
        row.style.cssText = 'display:flex;justify-content:space-between;gap:10px';
        var nm = document.createElement('span'); nm.textContent = it.username;
        var id = document.createElement('span'); id.className = 'muted'; id.style.fontSize = '11px'; id.textContent = '#' + it.id;
        row.appendChild(nm); row.appendChild(id);
        row.addEventListener('mousedown', function (e) {
          e.preventDefault(); input.value = it.username; list.style.display = 'none'; setConfirm(it.username);
          if (opts.onSelect) opts.onSelect(it);
        });
        list.appendChild(row);
      });
      list.style.display = 'block';
    }

    input.addEventListener('input', function () {
      var q = input.value.trim();
      if (confirmEl) { confirmEl.style.display = 'none'; }
      if (q.length < 1) { list.style.display = 'none'; return; }
      fetch('players_search.php?q=' + encodeURIComponent(q), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(render)
        .catch(function () {});
    });
    input.addEventListener('keydown', function (e) {
      if (!items.length) return;
      var rows = list.querySelectorAll('.ac-item');
      if (e.key === 'ArrowDown') { e.preventDefault(); cur = Math.min(cur + 1, rows.length - 1); rows.forEach(function (r, i) { r.classList.toggle('focused', i === cur); }); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); cur = Math.max(cur - 1, -1); rows.forEach(function (r, i) { r.classList.toggle('focused', i === cur); }); }
      else if (e.key === 'Enter' && cur >= 0) { e.preventDefault(); var it = items[cur]; input.value = it.username; list.style.display = 'none'; setConfirm(it.username); if (opts.onSelect) opts.onSelect(it); }
      else if (e.key === 'Escape') { list.style.display = 'none'; }
    });
    input.addEventListener('blur', function () {
      // Delay so a mousedown selection on the list registers first.
      setTimeout(function () {
        var q = input.value.trim();
        if (q.length > 0) setConfirm(q); else if (confirmEl) { confirmEl.style.display = 'none'; }
      }, 120);
    });
  }
};
