/* Administration ASIJ – scripts communs (sans dépendance).
 * Expose window.ASIJ: csrf(), toast(), markDirty(), upload(), pickMedia(), slugify(), icons.
 */
(function () {
  'use strict';

  var ASIJ = window.ASIJ = window.ASIJ || {};

  // ------------------------------------------------------------ utilitaires
  ASIJ.csrf = function () {
    var m = document.querySelector('meta[name="csrf-token"]');
    return m ? m.getAttribute('content') : '';
  };

  ASIJ.el = function (tag, attrs, children) {
    var n = document.createElement(tag);
    if (attrs) {
      Object.keys(attrs).forEach(function (k) {
        var v = attrs[k];
        if (v === null || v === undefined || v === false) return;
        if (k === 'class') n.className = v;
        else if (k === 'text') n.textContent = v;
        else if (k === 'html') n.innerHTML = v;
        else if (k.slice(0, 2) === 'on' && typeof v === 'function') n.addEventListener(k.slice(2), v);
        else n.setAttribute(k, v === true ? '' : v);
      });
    }
    (children || []).forEach(function (c) {
      if (c === null || c === undefined || c === false) return;
      n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
    });
    return n;
  };
  var el = ASIJ.el;

  ASIJ.humanSize = function (b) {
    var u = ['o', 'Ko', 'Mo', 'Go'], i = 0;
    while (b >= 1024 && i < u.length - 1) { b /= 1024; i++; }
    return (i === 0 ? b : b.toFixed(1).replace('.', ',')) + ' ' + u[i];
  };

  ASIJ.slugify = function (s) {
    s = String(s || '').toLowerCase()
      .replace(/œ/g, 'oe').replace(/æ/g, 'ae').replace(/ß/g, 'ss').replace(/&/g, '-et-');
    if (s.normalize) s = s.normalize('NFD').replace(/[̀-ͯ]/g, '');
    s = s.replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
    return s.slice(0, 120).replace(/-+$/, '');
  };

  var toastTimer;
  ASIJ.toast = function (msg, isError) {
    var t = document.querySelector('.toast');
    if (!t) return;
    t.textContent = msg;
    t.classList.toggle('is-error', !!isError);
    t.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { t.hidden = true; }, isError ? 7000 : 3500);
  };

  ASIJ.icon = function (path, size) {
    size = size || 18;
    return '<svg aria-hidden="true" focusable="false" viewBox="0 0 20 20" width="' + size + '" height="' + size + '"><path d="' + path +
      '" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
  };
  ASIJ.icons = {
    up: 'M10 15V5M5.5 9.5L10 5l4.5 4.5',
    down: 'M10 5v10M5.5 10.5L10 15l4.5-4.5',
    left: 'M15 10H5M9.5 5.5L5 10l4.5 4.5',
    right: 'M5 10h10M10.5 5.5L15 10l-4.5 4.5',
    copy: 'M7 7h9v9H7zM4 13V4h9',
    trash: 'M4 6h12M8 6V4h4v2M6 6l1 11h6l1-11M9 9v5M11 9v5',
    close: 'M5 5l10 10M15 5L5 15',
    drag: 'M7 4.5h.01M13 4.5h.01M7 10h.01M13 10h.01M7 15.5h.01M13 15.5h.01',
    plus: 'M10 4v12M4 10h12'
  };

  // ------------------------------------------------------------ menu mobile
  function initNav() {
    var btn = document.querySelector('.adm-menu-toggle');
    var nav = document.getElementById('adm-nav');
    var back = document.querySelector('.adm-nav-backdrop');
    if (!btn || !nav) return;
    function set(open) {
      nav.classList.toggle('is-open', open);
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (back) back.hidden = !open;
      if (open) { var a = nav.querySelector('a'); if (a) a.focus(); }
    }
    btn.addEventListener('click', function () { set(!nav.classList.contains('is-open')); });
    if (back) back.addEventListener('click', function () { set(false); });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && nav.classList.contains('is-open')) { set(false); btn.focus(); }
    });
  }

  // ------------------------------------------------------------ messages, confirmations
  function initFlash() {
    document.addEventListener('click', function (e) {
      var b = e.target.closest('.flash-close');
      if (b) b.closest('.flash').remove();
    });
  }

  function initConfirm() {
    document.addEventListener('submit', function (e) {
      var f = e.target;
      var msg = f.getAttribute('data-confirm');
      if (msg && !window.confirm(msg)) {
        e.preventDefault();
        e.stopImmediatePropagation();
        return;
      }
      if (msg || f.hasAttribute('data-no-dirty')) allowLeave = true;
    }, true);
  }

  // ------------------------------------------------------------ modifications non enregistrées
  var allowLeave = false;
  ASIJ.markDirty = function (form, dirty) {
    form = form || document.querySelector('form[data-dirty-check]');
    if (!form) return;
    dirty = dirty !== false;
    form.dataset.dirty = dirty ? '1' : '';
    document.querySelectorAll('[data-save-state],[data-save-state-mobile]').forEach(function (s) {
      s.classList.toggle('is-dirty', dirty);
      s.textContent = dirty ? 'Modifications non enregistrées' : (s.hasAttribute('data-save-state') ? 'Aucune modification en cours.' : '');
    });
  };
  function initDirty() {
    var forms = document.querySelectorAll('form[data-dirty-check]');
    forms.forEach(function (f) {
      f.addEventListener('input', function (e) { if (!e.target.closest('[data-no-dirty]')) ASIJ.markDirty(f); });
      f.addEventListener('change', function (e) { if (!e.target.closest('[data-no-dirty]')) ASIJ.markDirty(f); });
      f.addEventListener('submit', function () { allowLeave = true; });
    });
    window.addEventListener('beforeunload', function (e) {
      if (allowLeave) return;
      var dirty = Array.prototype.some.call(forms, function (f) { return f.dataset.dirty === '1'; });
      if (dirty) { e.preventDefault(); e.returnValue = ''; return ''; }
    });
  }

  function initCounters() {
    document.querySelectorAll('[data-counter]').forEach(function (t) {
      var out = t.parentNode.querySelector('[data-counter-out]');
      var ideal = parseInt(t.getAttribute('data-counter'), 10);
      function upd() {
        if (!out) return;
        var n = t.value.length;
        out.textContent = n + ' caractère' + (n > 1 ? 's' : '') + (n > ideal ? ' – un peu long, Google n’en affichera qu’environ ' + ideal + '.' : '');
      }
      t.addEventListener('input', upd);
      upd();
    });
  }

  // ------------------------------------------------------------ téléversement
  function allowedExt(name, accept) {
    if (!accept) return true;
    var ext = '.' + String(name).split('.').pop().toLowerCase();
    return accept.split(',').indexOf(ext) !== -1;
  }

  /**
   * Téléverse des fichiers un par un vers /admin/medias.
   * opts: {list: <ul> pour afficher la progression, maxBytes, accept, onItem(item)}
   * Retourne une Promise résolue avec la liste des médias créés.
   */
  ASIJ.upload = function (files, opts) {
    opts = opts || {};
    files = Array.prototype.slice.call(files || []);
    var created = [];
    var maxBytes = opts.maxBytes || ASIJ.maxBytes || 0;
    var accept = opts.accept || ASIJ.accept || '';
    var chain = Promise.resolve();
    files.forEach(function (file) {
      var row = null, prog = null, state = null;
      if (opts.list) {
        prog = el('progress', { max: '100', value: '0' });
        state = el('span', { class: 'muted', text: 'En attente…' });
        row = el('li', null, [el('span', { class: 'break', text: file.name }), state, prog]);
        opts.list.appendChild(row);
      }
      function done(ok, msg) {
        if (state) { state.textContent = msg; state.className = ok ? 'is-ok' : 'is-error'; }
        if (prog) prog.remove();
      }
      if (maxBytes && file.size > maxBytes) { done(false, 'Trop volumineux (max. ' + ASIJ.humanSize(maxBytes) + ')'); return; }
      if (!allowedExt(file.name, accept)) { done(false, 'Type de fichier non accepté'); return; }
      chain = chain.then(function () {
        return new Promise(function (resolve) {
          var xhr = new XMLHttpRequest();
          var fd = new FormData();
          fd.append('files[]', file, file.name);
          xhr.open('POST', '/admin/medias');
          xhr.setRequestHeader('X-CSRF-Token', ASIJ.csrf());
          xhr.setRequestHeader('Accept', 'application/json');
          xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
          if (state) state.textContent = 'Envoi…';
          xhr.upload.onprogress = function (e) { if (prog && e.lengthComputable) prog.value = Math.round(e.loaded / e.total * 100); };
          xhr.onload = function () {
            var res = null;
            try { res = JSON.parse(xhr.responseText); } catch (err) { /* réponse non JSON */ }
            if (res && res.items && res.items.length) {
              res.items.forEach(function (it) { created.push(it); if (opts.onItem) opts.onItem(it); });
              done(true, 'Ajouté');
            } else if (xhr.status === 419) {
              done(false, 'Session expirée ou fichier trop volumineux pour le serveur');
            } else {
              done(false, (res && res.errors && res.errors[0]) ? res.errors[0].replace(/^.*? : /, '') : 'Échec de l’envoi');
            }
            resolve();
          };
          xhr.onerror = function () { done(false, 'Connexion interrompue'); resolve(); };
          xhr.send(fd);
        });
      });
    });
    return chain.then(function () { return created; });
  };

  function bindDrop(zone, onFiles) {
    ['dragenter', 'dragover'].forEach(function (ev) {
      zone.addEventListener(ev, function (e) {
        if (!e.dataTransfer || Array.prototype.indexOf.call(e.dataTransfer.types || [], 'Files') === -1) return;
        e.preventDefault();
        zone.classList.add('is-over');
      });
    });
    ['dragleave', 'drop'].forEach(function (ev) {
      zone.addEventListener(ev, function (e) {
        if (ev === 'dragleave' && zone.contains(e.relatedTarget)) return;
        zone.classList.remove('is-over');
      });
    });
    zone.addEventListener('drop', function (e) {
      if (!e.dataTransfer || !e.dataTransfer.files.length) return;
      e.preventDefault();
      onFiles(e.dataTransfer.files);
    });
  }
  ASIJ.bindDrop = bindDrop;

  function initMediaPage() {
    var zone = document.querySelector('[data-dropzone]');
    if (!zone) return;
    var input = zone.querySelector('[data-file-input]');
    var list = zone.querySelector('[data-upload-list]');
    var opts = { list: list, maxBytes: parseInt(zone.getAttribute('data-max-bytes'), 10) || 0, accept: input.getAttribute('accept') };
    function go(files) {
      ASIJ.upload(files, opts).then(function (items) {
        if (items.length) {
          ASIJ.toast(items.length === 1 ? 'Fichier ajouté.' : items.length + ' fichiers ajoutés.');
          setTimeout(function () { window.location.href = '/admin/medias'; }, 1200);
        }
      });
    }
    input.addEventListener('change', function () { go(input.files); input.value = ''; });
    bindDrop(zone, go);
  }

  // ------------------------------------------------------------ sélecteur de médias (modale)
  var picker = null;

  function buildPicker() {
    var d = el('dialog', { class: 'modal', 'aria-labelledby': 'mp-title' });
    d.innerHTML =
      '<div class="modal-inner">' +
      '<div class="modal-head"><h2 id="mp-title">Choisir un fichier</h2><button type="button" class="icon-btn" data-close aria-label="Fermer">' + ASIJ.icon(ASIJ.icons.close, 20) + '</button></div>' +
      '<div class="modal-body">' +
      '<div class="mp-drop" data-mp-drop><p><strong>Glissez des fichiers ici</strong> pour les ajouter, ou</p>' +
      '<label class="btn btn-small">Choisir sur mon ordinateur<input type="file" class="sr-only" multiple data-mp-file></label>' +
      '<ul class="upload-list" role="list" data-mp-uploads aria-live="polite"></ul></div>' +
      '<div class="mp-tools"><label class="sr-only" for="mp-q">Rechercher un fichier</label><input type="search" id="mp-q" placeholder="Rechercher par nom…" data-mp-q>' +
      '<div class="segmented" role="group" aria-label="Type de fichier" data-mp-kinds>' +
      '<button type="button" data-kind="" aria-pressed="true">Tout</button><button type="button" data-kind="image" aria-pressed="false">Images</button><button type="button" data-kind="file" aria-pressed="false">Documents</button>' +
      '</div></div>' +
      '<p class="muted small" data-mp-status aria-live="polite"></p>' +
      '<ul class="mp-grid" role="list" data-mp-grid></ul>' +
      '<div class="mp-more"><button type="button" class="btn btn-small" data-mp-more hidden>Afficher plus</button></div>' +
      '</div>' +
      '<div class="modal-foot"><span class="muted small" data-mp-count></span><button type="button" class="btn" data-close>Annuler</button><button type="button" class="btn btn-primary" data-mp-insert disabled>Insérer</button></div>' +
      '</div>';
    document.body.appendChild(d);
    var st = { dialog: d, page: 1, pages: 1, q: '', kind: '', type: '', selected: [], multiple: false, resolve: null, lockKind: false };
    var grid = d.querySelector('[data-mp-grid]');
    var status = d.querySelector('[data-mp-status]');
    var more = d.querySelector('[data-mp-more]');
    var insertBtn = d.querySelector('[data-mp-insert]');
    var count = d.querySelector('[data-mp-count]');
    var q = d.querySelector('[data-mp-q]');
    var fileInput = d.querySelector('[data-mp-file]');
    var uploads = d.querySelector('[data-mp-uploads]');
    var reqId = 0;

    function isSel(id) { return st.selected.some(function (s) { return s.id === id; }); }
    function updCount() {
      var n = st.selected.length;
      insertBtn.disabled = n === 0;
      insertBtn.textContent = n > 1 ? 'Insérer les ' + n + ' fichiers' : 'Insérer';
      count.textContent = n ? n + ' sélectionné' + (n > 1 ? 's' : '') : (st.multiple ? 'Vous pouvez en choisir plusieurs.' : '');
    }
    function toggle(item, btn) {
      if (isSel(item.id)) {
        st.selected = st.selected.filter(function (s) { return s.id !== item.id; });
      } else {
        if (!st.multiple) {
          st.selected = [];
          grid.querySelectorAll('[aria-pressed="true"]').forEach(function (b) { b.setAttribute('aria-pressed', 'false'); });
        }
        st.selected.push(item);
      }
      btn.setAttribute('aria-pressed', isSel(item.id) ? 'true' : 'false');
      updCount();
    }
    function card(item, prepend) {
      if (grid.querySelector('[data-mid="' + item.id + '"]')) return;
      var thumb = item.kind === 'image'
        ? el('img', { src: item.thumb || item.url, alt: '', loading: 'lazy' })
        : el('span', { class: 'file-icon file-' + item.ext, text: (item.ext || 'fichier').toUpperCase() });
      var btn = el('button', {
        type: 'button', 'aria-pressed': isSel(item.id) ? 'true' : 'false',
        title: item.original_name + ' (' + item.size_human + ')'
      }, [el('span', { class: 'mp-thumb' }, [thumb]), el('span', { class: 'mp-name', text: item.title || item.original_name })]);
      btn.setAttribute('aria-label', (item.title || item.original_name) + ', ' + item.size_human);
      btn.addEventListener('click', function () { toggle(item, btn); });
      btn.addEventListener('dblclick', function () { if (!isSel(item.id)) toggle(item, btn); finish(st.selected); });
      var li = el('li', { class: 'mp-item', 'data-mid': String(item.id) }, [btn]);
      if (prepend) grid.insertBefore(li, grid.firstChild); else grid.appendChild(li);
    }
    function load(reset) {
      if (reset) { st.page = 1; grid.innerHTML = ''; }
      var my = ++reqId;
      status.textContent = 'Chargement…';
      var params = new URLSearchParams({ q: st.q, kind: st.kind, page: String(st.page) });
      if (st.type) params.set('type', st.type);
      fetch('/admin/medias/json?' + params.toString(), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
        .then(function (r) { if (!r.ok) throw new Error(); return r.json(); })
        .then(function (data) {
          if (my !== reqId) return;
          ASIJ.maxBytes = data.max_bytes || ASIJ.maxBytes;
          st.pages = data.pages;
          data.items.forEach(function (it) { card(it); });
          status.textContent = data.total === 0 ? (st.q ? 'Aucun fichier ne correspond à « ' + st.q + ' ».' : 'Aucun fichier pour l’instant : ajoutez-en ci-dessus.') : data.total + ' fichier' + (data.total > 1 ? 's' : '');
          more.hidden = st.page >= st.pages;
        })
        .catch(function () { status.textContent = 'Impossible de charger les fichiers. Vérifiez votre connexion.'; });
    }
    function setKind(k) {
      st.kind = k;
      d.querySelectorAll('[data-mp-kinds] button').forEach(function (b) {
        var on = b.getAttribute('data-kind') === k;
        b.setAttribute('aria-pressed', on ? 'true' : 'false');
        b.classList.toggle('is-active', on);
      });
    }
    function finish(result) {
      var r = st.resolve;
      st.resolve = null;
      if (d.open) d.close();
      if (r) r(result && result.length ? result.slice() : null);
    }
    function doUpload(files) {
      var accept = st.kind === 'image' && st.lockKind ? '.jpg,.jpeg,.png,.gif,.webp' : (st.type === 'video' ? '.mp4' : ASIJ.accept);
      ASIJ.upload(files, {
        list: uploads, accept: accept,
        onItem: function (it) {
          if (st.lockKind && st.kind && it.kind !== st.kind) return;
          if (!st.multiple) st.selected = [];
          st.selected.push(it);
          if (!st.multiple) grid.querySelectorAll('[aria-pressed="true"]').forEach(function (b) { b.setAttribute('aria-pressed', 'false'); });
          card(it, true);
          updCount();
        }
      }).then(function (items) {
        if (items.length) status.textContent = items.length + ' fichier' + (items.length > 1 ? 's ajoutés et sélectionnés' : ' ajouté et sélectionné') + '. Cliquez sur « Insérer ».';
      });
    }

    d.querySelectorAll('[data-close]').forEach(function (b) { b.addEventListener('click', function () { finish(null); }); });
    d.addEventListener('cancel', function (e) { e.preventDefault(); finish(null); });
    d.addEventListener('click', function (e) { if (e.target === d) finish(null); });
    insertBtn.addEventListener('click', function () { finish(st.selected); });
    more.addEventListener('click', function () { st.page++; load(false); });
    var qt;
    q.addEventListener('input', function () { clearTimeout(qt); qt = setTimeout(function () { st.q = q.value.trim(); load(true); }, 250); });
    q.addEventListener('keydown', function (e) { if (e.key === 'Enter') e.preventDefault(); });
    d.querySelectorAll('[data-mp-kinds] button').forEach(function (b) {
      b.addEventListener('click', function () { setKind(b.getAttribute('data-kind')); load(true); });
    });
    fileInput.addEventListener('change', function () { doUpload(fileInput.files); fileInput.value = ''; });
    bindDrop(d.querySelector('[data-mp-drop]'), doUpload);

    st.open = function (opts) {
      opts = opts || {};
      st.multiple = !!opts.multiple;
      st.lockKind = !!opts.lockKind;
      st.type = opts.type || '';
      st.selected = [];
      st.q = '';
      q.value = '';
      uploads.innerHTML = '';
      setKind(opts.kind || '');
      d.querySelector('[data-mp-kinds]').hidden = st.lockKind;
      d.querySelector('#mp-title').textContent = opts.title || (st.multiple ? 'Choisir des fichiers' : 'Choisir un fichier');
      fileInput.setAttribute('accept', opts.kind === 'image' && st.lockKind ? '.jpg,.jpeg,.png,.gif,.webp' : (st.type === 'video' ? '.mp4' : (ASIJ.accept || '')));
      fileInput.multiple = st.multiple;
      insertBtn.textContent = 'Insérer';
      updCount();
      load(true);
      d.showModal();
      setTimeout(function () { q.focus(); }, 30);
      return new Promise(function (resolve) { st.resolve = resolve; });
    };
    return st;
  }

  /**
   * Ouvre le sélecteur de médias.
   * opts: {kind: 'image'|'file'|'', lockKind: bool, type: 'video', multiple: bool, title: string}
   * Promise -> tableau de médias {id, url, thumb, kind, original_name, title, alt, size, size_human, ext} ou null.
   */
  ASIJ.pickMedia = function (opts) {
    if (!picker) picker = buildPicker();
    return picker.open(opts);
  };

  // ------------------------------------------------------------ réglages: logo
  function initLogoPicker() {
    var box = document.querySelector('[data-logo-picker]');
    if (!box) return;
    var input = box.querySelector('input[name="logo_media_id"]');
    var prev = box.querySelector('[data-logo-preview]');
    var clear = box.querySelector('[data-logo-clear]');
    box.querySelector('[data-logo-choose]').addEventListener('click', function () {
      ASIJ.pickMedia({ kind: 'image', lockKind: true, title: 'Choisir le logo' }).then(function (items) {
        if (!items) return;
        input.value = items[0].id;
        prev.innerHTML = '';
        prev.appendChild(el('img', { src: items[0].url, alt: 'Logo choisi' }));
        clear.hidden = false;
        ASIJ.markDirty(box.closest('form'));
      });
    });
    clear.addEventListener('click', function () {
      input.value = '';
      prev.innerHTML = '<span class="muted">Aucun logo choisi (le logo par défaut est utilisé).</span>';
      clear.hidden = true;
      ASIJ.markDirty(box.closest('form'));
    });
  }

  // ------------------------------------------------------------ formulaire de page: adresse
  function initSlug() {
    var form = document.getElementById('page-form');
    if (!form) return;
    var title = form.querySelector('#f-title');
    var slug = form.querySelector('[data-slug]');
    var parent = form.querySelector('#f-parent');
    var prefix = form.querySelector('[data-slug-prefix]');
    var home = form.querySelector('[data-is-home]');
    var slugField = form.querySelector('[data-slug-field]');
    if (!slug) return;
    title.addEventListener('input', function () {
      if (!slug.hasAttribute('data-touched')) slug.value = ASIJ.slugify(title.value);
    });
    slug.addEventListener('input', function () { slug.setAttribute('data-touched', '1'); });
    slug.addEventListener('blur', function () { if (slug.value) slug.value = ASIJ.slugify(slug.value); });
    function upd() {
      var opt = parent.options[parent.selectedIndex];
      prefix.textContent = (opt ? opt.getAttribute('data-path') : '') + '/';
      if (home) {
        var root = parent.value === '';
        home.closest('label').hidden = !root;
        if (!root) home.checked = false;
        slugField.hidden = root && home.checked;
      }
    }
    parent.addEventListener('change', upd);
    if (home) home.addEventListener('change', upd);
    upd();
  }

  // ------------------------------------------------------------ arbre des pages
  function initTree() {
    var tree = document.getElementById('page-tree');
    if (!tree) return;
    var live = document.getElementById('tree-status');
    var saving = Promise.resolve();

    function siblingsOf(li) { return Array.prototype.filter.call(li.parentNode.children, function (c) { return c.classList.contains('pt-item'); }); }
    function childList(li) { return li.querySelector(':scope > .pt-children'); }
    function parentLi(li) { var p = li.parentNode.closest('.pt-item'); return p && tree.contains(p) ? p : null; }

    function refreshButtons() {
      tree.querySelectorAll('.pt-item').forEach(function (li) {
        var sib = siblingsOf(li), i = sib.indexOf(li);
        li.querySelector('[data-move="up"]').disabled = i === 0;
        li.querySelector('[data-move="down"]').disabled = i === sib.length - 1;
        li.querySelector('[data-move="in"]').disabled = i === 0;
        li.querySelector('[data-move="out"]').disabled = !parentLi(li);
      });
    }

    function serialize() {
      var items = [];
      tree.querySelectorAll('.pt-item').forEach(function (li) {
        var p = parentLi(li);
        items.push({ id: parseInt(li.dataset.id, 10), parent_id: p ? parseInt(p.dataset.id, 10) : null, sort_order: siblingsOf(li).indexOf(li) + 1 });
      });
      return items;
    }

    function save(li, msg) {
      refreshButtons();
      if (li) { li.classList.remove('is-moved'); void li.offsetWidth; li.classList.add('is-moved'); }
      var body = JSON.stringify({ items: serialize() });
      saving = saving.then(function () {
        return fetch(tree.getAttribute('data-reorder-url'), {
          method: 'POST', credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': ASIJ.csrf() },
          body: body
        }).then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
          .then(function (res) {
            if (!res.ok) throw new Error(res.error || 'Erreur');
            Object.keys(res.paths || {}).forEach(function (id) {
              var row = tree.querySelector('.pt-item[data-id="' + id + '"] > .pt-row [data-path]');
              if (row) row.textContent = res.paths[id];
            });
            ASIJ.toast(msg || 'Ordre enregistré.');
            if (live) live.textContent = msg || 'Ordre enregistré.';
          })
          .catch(function (err) {
            ASIJ.toast('L’ordre n’a pas pu être enregistré : ' + err.message + ' La page va être rechargée.', true);
            setTimeout(function () { window.location.reload(); }, 2500);
          });
      });
    }

    tree.addEventListener('click', function (e) {
      var b = e.target.closest('[data-move]');
      if (!b || b.disabled) return;
      var li = b.closest('.pt-item');
      var sib = siblingsOf(li), i = sib.indexOf(li);
      var how = b.getAttribute('data-move');
      var title = li.dataset.title, msg;
      if (how === 'up' && i > 0) { li.parentNode.insertBefore(li, sib[i - 1]); msg = '« ' + title + ' » montée.'; }
      else if (how === 'down' && i < sib.length - 1) { li.parentNode.insertBefore(li, sib[i + 1].nextSibling); msg = '« ' + title + ' » descendue.'; }
      else if (how === 'in' && i > 0) { childList(sib[i - 1]).appendChild(li); msg = '« ' + title + ' » est maintenant une sous-page de « ' + sib[i - 1].dataset.title + ' ».'; }
      else if (how === 'out') {
        var p = parentLi(li);
        if (!p) return;
        p.parentNode.insertBefore(li, p.nextSibling);
        msg = '« ' + title + ' » remontée d’un niveau.';
      } else return;
      save(li, msg);
      var again = li.querySelector('[data-move="' + how + '"]');
      (again && !again.disabled ? again : li.querySelector('.pt-title')).focus();
    });

    // glisser-déposer
    var dragged = null;
    tree.addEventListener('dragstart', function (e) {
      var h = e.target.closest && e.target.closest('.pt-handle');
      if (!h) return;
      dragged = h.closest('.pt-item');
      dragged.classList.add('is-dragging');
      e.dataTransfer.effectAllowed = 'move';
      try { e.dataTransfer.setData('text/plain', dragged.dataset.id); } catch (err) { /* IE */ }
      try { e.dataTransfer.setDragImage(dragged.querySelector('.pt-row'), 20, 20); } catch (err) { /* ignoré */ }
    });
    function clearMarks() { tree.querySelectorAll('.drop-before,.drop-after,.drop-inside').forEach(function (r) { r.classList.remove('drop-before', 'drop-after', 'drop-inside'); }); }
    function zone(e, row) {
      var r = row.getBoundingClientRect(), y = (e.clientY - r.top) / r.height;
      return y < 0.3 ? 'before' : (y > 0.7 ? 'after' : 'inside');
    }
    tree.addEventListener('dragover', function (e) {
      if (!dragged) return;
      var row = e.target.closest('.pt-row');
      if (!row) return;
      var li = row.closest('.pt-item');
      if (li === dragged || dragged.contains(li)) { clearMarks(); return; }
      e.preventDefault();
      clearMarks();
      row.classList.add('drop-' + zone(e, row));
    });
    tree.addEventListener('drop', function (e) {
      if (!dragged) return;
      var row = e.target.closest('.pt-row');
      if (!row) return;
      var li = row.closest('.pt-item');
      e.preventDefault();
      clearMarks();
      if (li === dragged || dragged.contains(li)) return;
      var z = zone(e, row), msg;
      if (z === 'before') { li.parentNode.insertBefore(dragged, li); msg = '« ' + dragged.dataset.title + ' » déplacée.'; }
      else if (z === 'after') { li.parentNode.insertBefore(dragged, li.nextSibling); msg = '« ' + dragged.dataset.title + ' » déplacée.'; }
      else { childList(li).appendChild(dragged); msg = '« ' + dragged.dataset.title + ' » est maintenant une sous-page de « ' + li.dataset.title + ' ».'; }
      save(dragged, msg);
    });
    tree.addEventListener('dragend', function () {
      if (dragged) dragged.classList.remove('is-dragging');
      dragged = null;
      clearMarks();
    });
    refreshButtons();
  }

  document.addEventListener('DOMContentLoaded', function () {
    initNav();
    initFlash();
    initConfirm();
    initDirty();
    initCounters();
    initMediaPage();
    initLogoPicker();
    initSlug();
    initTree();
  });
})();
