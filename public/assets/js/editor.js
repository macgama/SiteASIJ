/* Éditeur de blocs de l'administration ASIJ (JavaScript natif, sans dépendance).
 * Lit #editor-data (JSON: blocks, media, links, maxBytes, accept) et écrit la liste
 * des blocs en JSON dans le champ caché #f-blocks du formulaire #page-form.
 * Dépend de admin.js (window.ASIJ: el, icon, icons, pickMedia, markDirty, toast).
 */
(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    var ASIJ = window.ASIJ;
    var root = document.getElementById('block-editor');
    var form = document.getElementById('page-form');
    var hidden = document.getElementById('f-blocks');
    var dataEl = document.getElementById('editor-data');
    if (!root || !form || !hidden || !dataEl || !ASIJ) return;

    var cfg;
    try { cfg = JSON.parse(dataEl.textContent); } catch (e) { cfg = { blocks: [] }; }
    ASIJ.maxBytes = cfg.maxBytes || 0;
    ASIJ.accept = cfg.accept || '';
    var media = cfg.media || {};
    var el = ASIJ.el, icon = ASIJ.icon, IC = ASIJ.icons;
    var seq = 0;
    function uid(p) { seq++; return (p || 'f') + '-' + seq; }
    function newId() { return 'b_' + Math.random().toString(36).slice(2, 8) + Date.now().toString(36).slice(-4); }
    function clone(o) { return JSON.parse(JSON.stringify(o)); }
    function remember(item) { media[item.id] = item; }
    function mediaOf(id) { return id ? media[id] : null; }

    // liste des pages internes pour les liens
    var dl = el('datalist', { id: 'internal-pages' });
    (cfg.links || []).forEach(function (l) { dl.appendChild(el('option', { value: l.path, label: l.title })); });
    document.body.appendChild(dl);

    // ------------------------------------------------------------ icônes des types
    var TI = {
      heading: 'M5 4v12M15 4v12M5 10h10',
      text: 'M3 5h14M3 9h14M3 13h10M3 17h7',
      image: 'M3 4.5h14v11H3zM3 13l4-4 3 3 2-2 5 5M13 7.5h.01',
      gallery: 'M2.5 3.5h7v6h-7zM10.5 3.5h7v6h-7zM2.5 10.5h7v6h-7zM10.5 10.5h7v6h-7z',
      files: 'M5 2.5h7l3 3v12H5zM12 2.5v3h3M8 10h4M8 13h4',
      video: 'M2.5 5h11v10h-11zM13.5 8.5l4-2.5v8l-4-2.5',
      callout: 'M10 3l8 14H2zM10 8v4M10 14.5h.01',
      button: 'M2.5 7h15v6h-15zM7 10h6',
      contact: 'M2.5 5h15v10h-15zM2.5 5.5l7.5 6 7.5-6',
      columns: 'M2.5 4h6.5v12H2.5zM11 4h6.5v12H11z',
      people: 'M7 9a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5zM13.5 9a2 2 0 1 0 0-4M2.5 16.5c.5-2.8 2.3-4.5 4.5-4.5s4 1.7 4.5 4.5M13 12c2.2 0 3.8 1.5 4.3 4',
      separator: 'M2.5 10h15',
      table: 'M2.5 4h15v12h-15zM2.5 8h15M2.5 12h15M8 4v12M13 4v12'
    };

    // ------------------------------------------------------------ champs simples
    function field(label, control, help, cls) {
      var id = control.id || (control.id = uid('f'));
      var kids = [el('label', { for: id, text: label })];
      if (help) {
        var hid = uid('h');
        kids.push(el('p', { class: 'help', id: hid, text: help }));
        control.setAttribute('aria-describedby', hid);
      }
      kids.push(control);
      return el('div', { class: 'field' + (cls ? ' ' + cls : '') }, kids);
    }
    function textInput(data, key, attrs) {
      var i = el('input', Object.assign({ type: 'text', value: data[key] || '' }, attrs || {}));
      i.value = data[key] || '';
      i.addEventListener('input', function () { data[key] = i.value; changed(); });
      return i;
    }
    function textArea(data, key, attrs) {
      var t = el('textarea', Object.assign({ rows: '3' }, attrs || {}));
      t.value = data[key] || '';
      t.addEventListener('input', function () { data[key] = t.value; changed(); });
      return t;
    }
    function select(data, key, options, cast) {
      var s = el('select');
      options.forEach(function (o) {
        var opt = el('option', { value: String(o[0]), text: o[1] });
        if (String(data[key]) === String(o[0])) opt.selected = true;
        s.appendChild(opt);
      });
      s.addEventListener('change', function () { data[key] = cast ? cast(s.value) : s.value; changed(); });
      return s;
    }
    function toolBtn(path, label, fn, cls) {
      var b = el('button', { type: 'button', class: 'icon-btn' + (cls ? ' ' + cls : ''), 'aria-label': label, title: label, html: icon(path) });
      b.addEventListener('click', fn);
      return b;
    }

    // ------------------------------------------------------------ nettoyage HTML (collage et sérialisation)
    var KEEP = { P: 'p', BR: 'br', STRONG: 'strong', B: 'strong', EM: 'em', I: 'em', H1: 'h2', H2: 'h2', H3: 'h3', H4: 'h3', H5: 'h3', H6: 'h3',
      UL: 'ul', OL: 'ol', LI: 'li', A: 'a', DIV: 'p', BLOCKQUOTE: 'blockquote', SUB: 'sub', SUP: 'sup',
      TABLE: 'table', THEAD: 'thead', TBODY: 'tbody', TR: 'tr', TD: 'td', TH: 'th' };
    var DROP = { SCRIPT: 1, STYLE: 1, META: 1, LINK: 1, TITLE: 1, XML: 1, TEMPLATE: 1, IFRAME: 1, OBJECT: 1, svg: 1, SVG: 1, IMG: 1, VIDEO: 1, AUDIO: 1, FORM: 1, INPUT: 1, BUTTON: 1, SELECT: 1, TEXTAREA: 1, HEAD: 1 };
    function safeHref(h) {
      h = (h || '').trim();
      if (!h) return '';
      if (/^(https?:|mailto:|tel:)/i.test(h)) return h;
      if ((h[0] === '/' && h[1] !== '/') || h[0] === '#') return h;
      return '';
    }
    function cleanNode(src, doc) {
      var frag = doc.createDocumentFragment();
      Array.prototype.forEach.call(src.childNodes, function (n) {
        if (n.nodeType === 3) { frag.appendChild(doc.createTextNode(n.nodeValue.replace(/ /g, ' '))); return; }
        if (n.nodeType !== 1) return;
        var tag = n.tagName.toUpperCase();
        var style = (n.getAttribute('style') || '').toLowerCase();
        if (DROP[tag] || tag.indexOf(':') !== -1 || /mso-list\s*:\s*ignore/.test(style) || /display\s*:\s*none/.test(style)) return;
        var map = KEEP[tag];
        if (tag === 'B' && /font-weight\s*:\s*(normal|400)/.test(style)) map = null; // Google Docs
        if (!map && tag === 'SPAN') {
          var inner = cleanNode(n, doc), wrap = null;
          if (/font-weight\s*:\s*(bold|[6-9]00)/.test(style)) { wrap = doc.createElement('strong'); wrap.appendChild(inner); inner = wrap; }
          if (/font-style\s*:\s*italic/.test(style)) { wrap = doc.createElement('em'); wrap.appendChild(inner); inner = wrap; }
          frag.appendChild(inner);
          return;
        }
        if (!map) { frag.appendChild(cleanNode(n, doc)); return; }
        var out = doc.createElement(map);
        if (map === 'a') {
          var href = safeHref(n.getAttribute('href'));
          if (!href) { frag.appendChild(cleanNode(n, doc)); return; }
          out.setAttribute('href', href);
          if (n.getAttribute('target') === '_blank') out.setAttribute('target', '_blank');
        }
        if ((map === 'td' || map === 'th')) {
          ['colspan', 'rowspan'].forEach(function (a) { if (n.getAttribute(a)) out.setAttribute(a, n.getAttribute(a)); });
        }
        if (map === 'p' && /^MsoListParagraph/i.test(n.className || '')) out.setAttribute('data-li', /^\s*\d+[.)]/.test(n.textContent) ? 'ol' : 'ul');
        out.appendChild(cleanNode(n, doc));
        frag.appendChild(out);
      });
      return frag;
    }
    function cleanHtml(html) {
      var doc = new DOMParser().parseFromString('<!doctype html><body>' + html, 'text/html');
      var body = doc.body;
      var clean = doc.createElement('div');
      clean.appendChild(cleanNode(body, doc));
      // listes Word: paragraphes MsoListParagraph consécutifs -> ul/ol
      var lis = clean.querySelectorAll('p[data-li]');
      Array.prototype.forEach.call(lis, function (p) {
        var kind = p.getAttribute('data-li');
        var prev = p.previousElementSibling;
        var list = prev && prev.tagName.toLowerCase() === kind && prev.hasAttribute('data-word') ? prev : null;
        if (!list) { list = doc.createElement(kind); list.setAttribute('data-word', '1'); p.parentNode.insertBefore(list, p); }
        var li = doc.createElement('li');
        while (p.firstChild) li.appendChild(p.firstChild);
        li.innerHTML = li.innerHTML.replace(/^(\s|&nbsp;|·|•|o|§|-|\d+[.)])+/, '');
        list.appendChild(li);
        p.remove();
      });
      clean.querySelectorAll('[data-word]').forEach(function (n) { n.removeAttribute('data-word'); });
      // texte et éléments en ligne au premier niveau -> paragraphes
      var BLOCK = /^(P|H2|H3|UL|OL|BLOCKQUOTE|TABLE)$/;
      var run = null;
      Array.prototype.slice.call(clean.childNodes).forEach(function (n) {
        if (n.nodeType === 1 && BLOCK.test(n.tagName)) { run = null; return; }
        if (n.nodeType === 1 && n.tagName === 'BR' && run) { run.appendChild(n); run = null; return; }
        if (n.nodeType === 3 && !run && !n.nodeValue.trim()) return;
        if (!run) { run = doc.createElement('p'); clean.insertBefore(run, n); }
        run.appendChild(n);
      });
      clean.querySelectorAll('p > br:last-child').forEach(function (br) { if (br.parentNode.childNodes.length > 1) br.remove(); });
      // paragraphes dans les éléments de liste et paragraphes imbriqués
      clean.querySelectorAll('li > p, p > p').forEach(function (p) {
        while (p.firstChild) p.parentNode.insertBefore(p.firstChild, p);
        if (p.parentNode.tagName === 'LI' && p.nextSibling) p.parentNode.insertBefore(doc.createElement('br'), p);
        p.remove();
      });
      // paragraphes et mise en forme vides
      clean.querySelectorAll('p, h2, h3, strong, em, li').forEach(function (n) {
        if (!n.textContent.replace(/[\s ]/g, '') && !n.querySelector('br,a')) n.remove();
      });
      var out = clean.innerHTML.replace(/(<br>\s*){3,}/g, '<br><br>').trim();
      return out === '<br>' ? '' : out;
    }
    function escapeHtml(s) { return s.replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
    function textToHtml(t) {
      return t.replace(/\r\n?/g, '\n').split(/\n{2,}/).map(function (p) {
        p = p.trim();
        return p ? '<p>' + escapeHtml(p).replace(/\n/g, '<br>') + '</p>' : '';
      }).join('');
    }

    // ------------------------------------------------------------ éditeur de texte riche
    try { document.execCommand('defaultParagraphSeparator', false, 'p'); } catch (e) { /* ancien navigateur */ }
    var linkDlg = null;
    function linkDialog() {
      if (linkDlg) return linkDlg;
      var d = el('dialog', { class: 'modal modal-small', 'aria-labelledby': 'ld-title' });
      d.innerHTML = '<form method="dialog" class="modal-inner">' +
        '<div class="modal-head"><h2 id="ld-title">Insérer un lien</h2></div>' +
        '<div class="modal-body">' +
        '<div class="field"><label for="ld-url">Adresse du lien</label>' +
        '<p class="help" id="ld-help">Une page du site (commencez à taper « / » pour la liste), un site (https://…) ou une adresse e-mail.</p>' +
        '<input id="ld-url" type="text" list="internal-pages" aria-describedby="ld-help" autocomplete="off" spellcheck="false"></div>' +
        '<div class="field" data-ld-text-field><label for="ld-text">Texte affiché</label><input id="ld-text" type="text"></div>' +
        '<label class="check"><input type="checkbox" id="ld-blank"><span>Ouvrir dans un nouvel onglet</span></label>' +
        '<p class="error" data-ld-error hidden></p>' +
        '</div>' +
        '<div class="modal-foot"><button type="button" class="btn btn-danger-link" data-ld-remove>Retirer le lien</button>' +
        '<button type="button" class="btn" data-ld-cancel>Annuler</button><button type="submit" class="btn btn-primary">Valider</button></div></form>';
      document.body.appendChild(d);
      linkDlg = d;
      return d;
    }
    function normalizeUrl(u) {
      u = (u || '').trim();
      if (!u) return '';
      if (/^[^\s@\/]+@[^\s@\/]+\.[a-z]{2,}$/i.test(u)) return 'mailto:' + u;
      if (/^\+?[0-9 ]{8,}$/.test(u)) return 'tel:' + u.replace(/\s/g, '');
      if (/^www\./i.test(u) || /^[a-z0-9-]+(\.[a-z0-9-]+)+(\/.*)?$/i.test(u)) return 'https://' + u;
      return safeHref(u);
    }
    function openLink(area, onDone) {
      var sel = window.getSelection();
      var range = sel.rangeCount && area.contains(sel.anchorNode) ? sel.getRangeAt(0).cloneRange() : null;
      var anchor = sel.anchorNode ? (sel.anchorNode.nodeType === 1 ? sel.anchorNode : sel.anchorNode.parentNode).closest('a') : null;
      if (anchor && !area.contains(anchor)) anchor = null;
      var d = linkDialog();
      var url = d.querySelector('#ld-url'), text = d.querySelector('#ld-text'), blank = d.querySelector('#ld-blank');
      var err = d.querySelector('[data-ld-error]'), textField = d.querySelector('[data-ld-text-field]');
      var remove = d.querySelector('[data-ld-remove]');
      url.value = anchor ? anchor.getAttribute('href') || '' : '';
      blank.checked = anchor ? anchor.getAttribute('target') === '_blank' : false;
      var needText = !anchor && (!range || range.collapsed);
      textField.hidden = !needText;
      text.value = '';
      err.hidden = true;
      remove.hidden = !anchor;
      d.querySelector('#ld-title').textContent = anchor ? 'Modifier le lien' : 'Insérer un lien';
      function cleanup() { d.removeEventListener('submit', onSubmit); d.removeEventListener('close', onClose); }
      function restore() {
        area.focus();
        if (range) { sel.removeAllRanges(); sel.addRange(range); }
      }
      function onSubmit(e) {
        var href = normalizeUrl(url.value);
        if (!href) {
          e.preventDefault();
          err.textContent = 'Cette adresse n’est pas valide. Exemples : /contact, https://www.vd.ch, info@asij.ch';
          err.hidden = false;
          url.focus();
          return;
        }
        cleanup();
        d.close();
        restore();
        if (anchor) {
          anchor.setAttribute('href', href);
        } else if (needText) {
          var label = text.value.trim() || url.value.trim();
          document.execCommand('insertHTML', false, '<a href="' + escapeHtml(href) + '">' + escapeHtml(label) + '</a>&nbsp;');
        } else {
          document.execCommand('createLink', false, href);
        }
        area.querySelectorAll('a').forEach(function (a) {
          if (a.getAttribute('href') === href) {
            if (blank.checked) a.setAttribute('target', '_blank'); else a.removeAttribute('target');
          }
        });
        onDone();
      }
      function onClose() { cleanup(); }
      d.querySelector('[data-ld-cancel]').onclick = function () { cleanup(); d.close(); restore(); };
      remove.onclick = function () {
        cleanup(); d.close(); restore();
        if (anchor) {
          while (anchor.firstChild) anchor.parentNode.insertBefore(anchor.firstChild, anchor);
          anchor.remove();
          onDone();
        }
      };
      d.addEventListener('submit', onSubmit);
      d.addEventListener('close', onClose);
      d.showModal();
      url.focus();
    }

    var RTE_BUTTONS = [
      ['bold', '<b>G</b>', 'Gras (Ctrl+B)'],
      ['italic', '<i>I</i>', 'Italique (Ctrl+I)'],
      ['|'],
      ['h2', 'Titre', 'Titre de section'],
      ['h3', 'Sous-titre', 'Sous-titre'],
      ['p', '¶', 'Paragraphe normal'],
      ['|'],
      ['insertUnorderedList', '• Liste', 'Liste à puces'],
      ['insertOrderedList', '1. Liste', 'Liste numérotée'],
      ['|'],
      ['link', 'Lien', 'Insérer ou modifier un lien (Ctrl+K)'],
      ['clear', 'Effacer', 'Retirer la mise en forme du texte sélectionné']
    ];
    function richText(data, key, label, placeholder) {
      var area = el('div', {
        class: 'rte-area', contenteditable: 'true', role: 'textbox', 'aria-multiline': 'true',
        'aria-label': label, 'data-placeholder': placeholder || 'Écrivez votre texte ici…'
      });
      area.innerHTML = data[key] || '';
      var bar = el('div', { class: 'rte-bar', role: 'toolbar', 'aria-label': 'Mise en forme – ' + label });
      var stateBtns = [];
      function save() { data[key] = cleanHtml(area.innerHTML); changed(); }
      function exec(cmd) {
        area.focus();
        if (cmd === 'h2' || cmd === 'h3' || cmd === 'p') {
          var cur = String(document.queryCommandValue('formatBlock') || '').toLowerCase();
          document.execCommand('formatBlock', false, cur === cmd ? 'p' : cmd);
        } else if (cmd === 'link') {
          openLink(area, save);
          return;
        } else if (cmd === 'clear') {
          document.execCommand('removeFormat');
          document.execCommand('unlink');
          document.execCommand('formatBlock', false, 'p');
        } else {
          document.execCommand(cmd);
        }
        save();
        updState();
      }
      RTE_BUTTONS.forEach(function (b) {
        if (b[0] === '|') { bar.appendChild(el('span', { class: 'rte-sep', 'aria-hidden': 'true' })); return; }
        var btn = el('button', { type: 'button', title: b[2], 'aria-label': b[2], html: b[1] });
        btn.addEventListener('mousedown', function (e) { e.preventDefault(); });
        btn.addEventListener('click', function () { exec(b[0]); });
        if (['bold', 'italic', 'h2', 'h3', 'insertUnorderedList', 'insertOrderedList'].indexOf(b[0]) !== -1) {
          btn.setAttribute('aria-pressed', 'false');
          stateBtns.push([b[0], btn]);
        }
        bar.appendChild(btn);
      });
      function updState() {
        var s = window.getSelection();
        if (!s.rangeCount || !area.contains(s.anchorNode)) return;
        var block = String(document.queryCommandValue('formatBlock') || '').toLowerCase();
        stateBtns.forEach(function (x) {
          var on;
          if (x[0] === 'h2' || x[0] === 'h3') on = block === x[0];
          else { try { on = document.queryCommandState(x[0]); } catch (e) { on = false; } }
          x[1].setAttribute('aria-pressed', on ? 'true' : 'false');
        });
      }
      area.addEventListener('input', function () {
        if (area.innerHTML === '<br>' || area.innerHTML === '<p><br></p>') area.innerHTML = '';
        save();
      });
      area.addEventListener('keyup', updState);
      area.addEventListener('mouseup', updState);
      area.addEventListener('focus', function () {
        if (!area.textContent.trim() && !area.querySelector('p,h2,h3,ul,ol')) {
          try { document.execCommand('formatBlock', false, 'p'); } catch (e) { /* ignoré */ }
        }
        updState();
      });
      area.addEventListener('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); exec('link'); }
      });
      area.addEventListener('paste', function (e) {
        var cd = e.clipboardData;
        if (!cd) return;
        e.preventDefault();
        var html = cd.getData('text/html');
        var out = html ? cleanHtml(html) : textToHtml(cd.getData('text/plain') || '');
        if (out) document.execCommand('insertHTML', false, out);
        save();
      });
      area.addEventListener('drop', function (e) { if (e.dataTransfer && e.dataTransfer.files.length) e.preventDefault(); });
      area.addEventListener('click', function (e) { if (e.target.closest('a')) e.preventDefault(); });
      var wrapId = uid('rte');
      area.id = wrapId;
      return el('div', { class: 'rte' }, [bar, area]);
    }

    function rteField(data, key, label, help, placeholder) {
      var lid = uid('l');
      var rte = richText(data, key, label, placeholder);
      var kids = [el('span', { class: 'label', id: lid, text: label })];
      if (help) kids.push(el('p', { class: 'help', text: help }));
      kids.push(rte);
      return el('div', { class: 'field' }, kids);
    }

    // ------------------------------------------------------------ listes réordonnables (galerie, documents, personnes)
    var dragItem = null;
    function sortable(list, itemSel, axis, onChange) {
      list.addEventListener('dragstart', function (e) {
        var it = e.target.closest && e.target.closest(itemSel);
        if (!it || !list.contains(it) || !e.target.closest('[data-drag]')) return;
        e.stopPropagation();
        dragItem = it;
        it.classList.add('is-dragging');
        e.dataTransfer.effectAllowed = 'move';
        try { e.dataTransfer.setData('text/plain', 'item'); } catch (err) { /* ignoré */ }
      });
      list.addEventListener('dragover', function (e) {
        if (!dragItem || !list.contains(dragItem)) return;
        var it = e.target.closest(itemSel);
        e.preventDefault();
        e.stopPropagation();
        list.querySelectorAll('.drop-before,.drop-after').forEach(function (n) { n.classList.remove('drop-before', 'drop-after'); });
        if (!it || it === dragItem) return;
        var r = it.getBoundingClientRect();
        var before = axis === 'x' ? e.clientX < r.left + r.width / 2 : e.clientY < r.top + r.height / 2;
        it.classList.add(before ? 'drop-before' : 'drop-after');
      });
      list.addEventListener('drop', function (e) {
        if (!dragItem || !list.contains(dragItem)) return;
        e.preventDefault();
        e.stopPropagation();
        var it = list.querySelector('.drop-before,.drop-after');
        if (it) {
          list.insertBefore(dragItem, it.classList.contains('drop-before') ? it : it.nextSibling);
          onChange();
        }
      });
      list.addEventListener('dragend', function (e) {
        e.stopPropagation();
        if (dragItem) dragItem.classList.remove('is-dragging');
        dragItem = null;
        list.querySelectorAll('.drop-before,.drop-after').forEach(function (n) { n.classList.remove('drop-before', 'drop-after'); });
      });
    }
    /** Liste d'éléments data.items[], chaque <li> garde une référence à son objet. */
    function itemList(data, cls, itemSel, axis, renderItem, emptyText) {
      if (!Array.isArray(data.items)) data.items = [];
      var list = el('ul', { class: cls, role: 'list' });
      var empty = el('p', { class: 'muted small', text: emptyText });
      function syncItems() {
        data.items = Array.prototype.map.call(list.children, function (li) { return li._item; });
        empty.hidden = data.items.length > 0;
        Array.prototype.forEach.call(list.children, function (li, i) {
          var up = li.querySelector('[data-up]'), down = li.querySelector('[data-down]');
          if (up) up.disabled = i === 0;
          if (down) down.disabled = i === list.children.length - 1;
        });
        changed();
      }
      function add(item, focus) {
        var li = renderItem(item, {
          up: function () { if (li.previousElementSibling) { list.insertBefore(li, li.previousElementSibling); syncItems(); refocus(li, '[data-up]'); } },
          down: function () { if (li.nextElementSibling) { list.insertBefore(li.nextElementSibling, li); syncItems(); refocus(li, '[data-down]'); } },
          remove: function () {
            var next = li.nextElementSibling || li.previousElementSibling;
            li.remove();
            syncItems();
            if (next) { var f = next.querySelector('input,button'); if (f) f.focus(); }
          }
        });
        li._item = item;
        list.appendChild(li);
        if (focus) { var f = li.querySelector('input'); if (f) f.focus(); }
      }
      function refocus(li, sel) { var b = li.querySelector(sel); if (b && !b.disabled) b.focus(); else { var i = li.querySelector('input'); if (i) i.focus(); } }
      data.items.forEach(function (it) { add(it); });
      sortable(list, itemSel, axis, syncItems);
      setTimeout(function () { empty.hidden = data.items.length > 0; Array.prototype.forEach.call(list.children, function (li, i) {
        var up = li.querySelector('[data-up]'), down = li.querySelector('[data-down]');
        if (up) up.disabled = i === 0;
        if (down) down.disabled = i === list.children.length - 1;
      }); }, 0);
      return { list: list, empty: empty, add: function (it, focus) { add(it, focus); syncItems(); } };
    }
    function moveTools(axis, h, label) {
      var prev = axis === 'x' ? IC.left : IC.up, next = axis === 'x' ? IC.right : IC.down;
      var a = toolBtn(prev, (axis === 'x' ? 'Déplacer avant : ' : 'Monter : ') + label, h.up); a.setAttribute('data-up', '');
      var b = toolBtn(next, (axis === 'x' ? 'Déplacer après : ' : 'Descendre : ') + label, h.down); b.setAttribute('data-down', '');
      var c = toolBtn(IC.trash, 'Retirer : ' + label, h.remove, 'danger');
      return [a, b, c];
    }
    function fileIcon(m) {
      var ext = (m && m.ext) || '?';
      return el('span', { class: 'file-icon file-' + ext, 'aria-hidden': 'true', text: ext.toUpperCase() });
    }
    function stripExt(n) { return String(n || '').replace(/\.[a-z0-9]{1,5}$/i, ''); }

    // ------------------------------------------------------------ définition des types de blocs
    var TYPES = {
      heading: {
        label: 'Titre', desc: 'Un titre pour découper la page en sections.',
        defaults: function () { return { text: '', level: 2 }; },
        render: function (d) {
          var i = textInput(d, 'text', { class: 'blk-heading-input', placeholder: 'Titre de la section', maxlength: '300' });
          var s = select(d, 'level', [[2, 'Titre de section'], [3, 'Sous-titre (plus petit)']], function (v) { return parseInt(v, 10); });
          return [el('div', { class: 'blk-row blk-row-2' }, [field('Texte du titre', i), field('Taille', s)])];
        }
      },
      text: {
        label: 'Texte', desc: 'Paragraphes avec gras, italique, listes et liens.',
        defaults: function () { return { html: '' }; },
        render: function (d) { return [rteField(d, 'html', 'Texte', null)]; }
      },
      image: {
        label: 'Image', desc: 'Une photo ou une illustration, avec légende.',
        defaults: function () { return { media_id: null, alt: '', caption: '', width: 'full' }; },
        render: function (d) {
          var prev = el('div', { class: 'pick-preview' });
          var choose = el('button', { type: 'button', class: 'btn' });
          var remove = el('button', { type: 'button', class: 'btn btn-ghost', text: 'Retirer l’image' });
          var alt = textInput(d, 'alt', { maxlength: '300' });
          function show() {
            var m = mediaOf(d.media_id);
            prev.innerHTML = '';
            prev.classList.toggle('has-img', !!m);
            if (m) prev.appendChild(el('img', { src: m.thumb || m.url, alt: '' }));
            else prev.appendChild(el('span', { text: d.media_id ? 'Image introuvable (supprimée ?)' : 'Aucune image choisie' }));
            choose.textContent = m ? 'Changer d’image' : 'Choisir une image';
            remove.hidden = !d.media_id;
          }
          choose.addEventListener('click', function () {
            ASIJ.pickMedia({ kind: 'image', lockKind: true, title: 'Choisir une image' }).then(function (items) {
              if (!items) return;
              remember(items[0]);
              d.media_id = items[0].id;
              if (!d.alt && items[0].alt) { d.alt = items[0].alt; alt.value = d.alt; }
              show(); changed();
            });
          });
          remove.addEventListener('click', function () { d.media_id = null; show(); changed(); });
          show();
          return [el('div', { class: 'pick-image' }, [
            el('div', null, [prev, el('div', { class: 'btn-row', style: 'margin-top:.5rem' }, [choose, remove])]),
            el('div', null, [
              field('Description de l’image', alt, 'Pour les personnes malvoyantes : décrivez en quelques mots ce que montre l’image.'),
              field('Légende (facultatif)', textInput(d, 'caption', { maxlength: '500' })),
              field('Largeur', select(d, 'width', [['full', 'Pleine largeur'], ['half', 'Demi-largeur']]))
            ])
          ])];
        }
      },
      gallery: {
        label: 'Galerie de photos', desc: 'Plusieurs photos en grille, agrandissables d’un clic.',
        defaults: function () { return { title: '', items: [] }; },
        render: function (d) {
          var count = el('span', { class: 'muted small' });
          var L = itemList(d, 'gal-grid', '.gal-item', 'x', function (item, h) {
            var m = mediaOf(item.media_id);
            var name = m ? (m.title || m.original_name) : 'photo';
            var cap = el('input', { type: 'text', placeholder: 'Légende (facultatif)', 'aria-label': 'Légende de la photo ' + name, maxlength: '500' });
            cap.value = item.caption || '';
            cap.addEventListener('input', function () { item.caption = cap.value; changed(); });
            return el('li', { class: 'gal-item' }, [
              el('div', { class: 'gal-thumb', draggable: 'true', 'data-drag': '', title: 'Glisser pour changer l’ordre' }, [
                m ? el('img', { src: m.thumb || m.url, alt: '' }) : el('span', { class: 'muted small', text: 'Photo introuvable' })
              ]),
              cap,
              el('div', { class: 'gal-tools' }, moveTools('x', h, name))
            ]);
          }, 'Aucune photo dans la galerie.');
          function upd() { var n = d.items.length; count.textContent = n ? n + ' photo' + (n > 1 ? 's' : '') : ''; }
          L.list.addEventListener('click', function () { setTimeout(upd, 0); });
          var add = el('button', { type: 'button', class: 'btn', html: icon(IC.plus) + ' Ajouter des photos' });
          add.addEventListener('click', function () {
            ASIJ.pickMedia({ kind: 'image', lockKind: true, multiple: true, title: 'Ajouter des photos à la galerie' }).then(function (items) {
              if (!items) return;
              items.forEach(function (m) { remember(m); L.add({ media_id: m.id, caption: '' }); });
              upd();
              ASIJ.toast(items.length + ' photo' + (items.length > 1 ? 's ajoutées' : ' ajoutée') + ' à la galerie.');
            });
          });
          setTimeout(upd, 0);
          return [
            field('Titre de la galerie (facultatif)', textInput(d, 'title', { maxlength: '300' })),
            L.list, L.empty,
            el('div', { class: 'btn-row' }, [add, count]),
            el('p', { class: 'help', text: 'Astuce : glissez les photos pour changer leur ordre.' })
          ];
        }
      },
      files: {
        label: 'Documents à télécharger', desc: 'Liste de fichiers PDF, Word, Excel… à télécharger.',
        defaults: function () { return { title: '', items: [], sort: 'manual' }; },
        render: function (d) {
          var L = itemList(d, 'rows', '.row-item', 'y', function (item, h) {
            var m = mediaOf(item.media_id);
            var lab = el('input', { type: 'text', maxlength: '300', placeholder: m ? stripExt(m.original_name) : '', 'aria-label': 'Libellé du document ' + (m ? m.original_name : '') });
            lab.value = item.label || '';
            lab.addEventListener('input', function () { item.label = lab.value; changed(); });
            return el('li', { class: 'row-item' }, [
              el('span', { class: 'blk-handle', draggable: 'true', 'data-drag': '', title: 'Glisser pour changer l’ordre', html: icon(IC.drag) }),
              fileIcon(m),
              el('div', { class: 'row-main' }, [lab, el('p', { class: 'muted small', text: m ? m.original_name + ' · ' + m.size_human : 'Fichier introuvable (supprimé ?)' })]),
              el('div', { class: 'row-tools' }, moveTools('y', h, item.label || (m ? m.original_name : 'document')))
            ]);
          }, 'Aucun document dans la liste.');
          var add = el('button', { type: 'button', class: 'btn', html: icon(IC.plus) + ' Ajouter des documents' });
          add.addEventListener('click', function () {
            ASIJ.pickMedia({ kind: 'file', multiple: true, title: 'Ajouter des documents' }).then(function (items) {
              if (!items) return;
              items.forEach(function (m) { remember(m); L.add({ media_id: m.id, label: m.title || stripExt(m.original_name) }); });
              ASIJ.toast(items.length + ' document' + (items.length > 1 ? 's ajoutés' : ' ajouté') + '.');
            });
          });
          return [
            el('div', { class: 'blk-row blk-row-2' }, [
              field('Titre de la liste (facultatif)', textInput(d, 'title', { maxlength: '300', placeholder: 'Ex. Procès-verbaux 2026' })),
              field('Ordre d’affichage', select(d, 'sort', [['manual', 'Dans l’ordre ci-dessous'], ['name_desc', 'Par libellé, ordre décroissant (le plus récent en premier pour les noms datés)']]))
            ]),
            (function () {
              var c = el('input', { type: 'checkbox' });
              c.checked = !!d.collapsed;
              c.addEventListener('change', function () { d.collapsed = c.checked; changed(); });
              return el('label', { class: 'check' }, [c, el('span', { text: 'Liste repliée : on clique sur le titre pour voir les documents (pratique pour les archives par année)' })]);
            })(),
            L.list, L.empty,
            el('div', { class: 'btn-row' }, [add])
          ];
        }
      },
      video: {
        label: 'Vidéo', desc: 'Une vidéo YouTube ou Vimeo, ou un fichier vidéo MP4.',
        defaults: function () { return { url: '' }; },
        render: function (d) {
          var name = uid('vm');
          var modeUrl = el('input', { type: 'radio', name: name, value: 'url' });
          var modeFile = el('input', { type: 'radio', name: name, value: 'file' });
          modeUrl.setAttribute('data-no-dirty', '');
          var isFile = !!d.media_id;
          modeUrl.checked = !isFile; modeFile.checked = isFile;
          var url = el('input', { type: 'url', placeholder: 'https://www.youtube.com/watch?v=…', inputmode: 'url' });
          url.value = d.url || '';
          var err = el('p', { class: 'error', hidden: true, text: 'Ce lien ne semble pas être une vidéo YouTube ou Vimeo.' });
          url.addEventListener('input', function () { d.url = url.value.trim(); delete d.media_id; changed(); });
          url.addEventListener('blur', function () {
            err.hidden = !d.url || /^https:\/\/(www\.|m\.)?(youtube\.com|youtu\.be|youtube-nocookie\.com|vimeo\.com|player\.vimeo\.com)\//i.test(d.url);
          });
          var urlBox = el('div', null, [field('Lien de la vidéo', url, 'Copiez l’adresse de la vidéo depuis YouTube ou Vimeo.'), err]);
          var fileInfo = el('p', { class: 'small' });
          var pick = el('button', { type: 'button', class: 'btn' });
          function showFile() {
            var m = mediaOf(d.media_id);
            fileInfo.textContent = m ? 'Fichier : ' + m.original_name + ' (' + m.size_human + ')' : 'Aucun fichier choisi.';
            pick.textContent = m ? 'Changer de fichier' : 'Choisir un fichier vidéo';
          }
          pick.addEventListener('click', function () {
            ASIJ.pickMedia({ kind: 'file', lockKind: true, type: 'video', title: 'Choisir une vidéo (MP4)' }).then(function (items) {
              if (!items) return;
              remember(items[0]); d.media_id = items[0].id; delete d.url; showFile(); changed();
            });
          });
          var fileBox = el('div', null, [fileInfo, pick, el('p', { class: 'help', text: 'Les fichiers vidéo sont lourds : préférez YouTube ou Vimeo quand c’est possible.' })]);
          function mode() {
            urlBox.hidden = modeFile.checked; fileBox.hidden = !modeFile.checked;
            if (modeFile.checked) { if (!d.media_id) delete d.url; } else { delete d.media_id; d.url = url.value.trim(); }
          }
          [modeUrl, modeFile].forEach(function (r) { r.addEventListener('change', function () { mode(); changed(); }); });
          showFile(); mode();
          return [
            el('fieldset', { class: 'radio-cards', style: 'margin-bottom:1rem' }, [
              el('legend', { class: 'label', text: 'Source de la vidéo' }),
              el('label', { class: 'check' }, [modeUrl, el('span', { text: 'Lien YouTube ou Vimeo' })]),
              el('label', { class: 'check' }, [modeFile, el('span', { text: 'Fichier vidéo des médias (MP4)' })])
            ]),
            urlBox, fileBox
          ];
        }
      },
      callout: {
        label: 'Encadré', desc: 'Un message mis en évidence (information ou avertissement).',
        defaults: function () { return { style: 'info', title: '', html: '' }; },
        render: function (d) {
          var wrap = el('div', { class: 'callout-' + (d.style === 'warning' ? 'warning' : 'info') });
          var s = select(d, 'style', [['info', 'Information (bleu)'], ['warning', 'Avertissement (orange)']]);
          s.addEventListener('change', function () { wrap.className = 'callout-' + s.value; });
          wrap.appendChild(el('div', { class: 'blk-row blk-row-2' }, [field('Titre de l’encadré', textInput(d, 'title', { maxlength: '300' })), field('Couleur', s)]));
          wrap.appendChild(rteField(d, 'html', 'Texte de l’encadré'));
          return [wrap];
        }
      },
      button: {
        label: 'Bouton', desc: 'Un bouton qui mène vers une page, un site ou une adresse e-mail.',
        defaults: function () { return { label: '', url: '' }; },
        render: function (d) {
          var u = el('input', { type: 'text', list: 'internal-pages', placeholder: '/contact ou https://…', spellcheck: 'false', autocomplete: 'off' });
          u.value = d.url || '';
          var err = el('p', { class: 'error', hidden: true, text: 'Adresse non valide. Exemples : /contact, https://www.vd.ch, info@asij.ch' });
          u.addEventListener('input', function () { d.url = u.value.trim(); changed(); });
          u.addEventListener('blur', function () {
            if (!u.value.trim()) { err.hidden = true; return; }
            var n = normalizeUrl(u.value);
            err.hidden = !!n;
            if (n) { u.value = n; d.url = n; changed(); }
          });
          return [el('div', { class: 'blk-row blk-row-2' }, [
            field('Texte du bouton', textInput(d, 'label', { maxlength: '200', placeholder: 'Ex. S’inscrire aux devoirs surveillés' })),
            el('div', null, [field('Lien', u, 'Une page du site (tapez « / » pour la liste) ou une adresse internet.'), err])
          ])];
        }
      },
      contact: {
        label: 'Formulaire de contact', desc: 'Permet aux visiteurs d’envoyer un message.',
        defaults: function () { return { intro: '' }; },
        render: function (d) {
          return [
            field('Texte d’introduction (facultatif)', textArea(d, 'intro', { maxlength: '2000', placeholder: 'Ex. Pour toute question, utilisez le formulaire ci-dessous.' })),
            el('p', { class: 'help', text: 'Le formulaire (nom, e-mail, message) s’affiche automatiquement. Les messages arrivent dans « Messages ».' })
          ];
        }
      },
      columns: {
        label: 'Deux colonnes', desc: 'Deux textes côte à côte (l’un sous l’autre sur mobile).',
        defaults: function () { return { left: '', right: '' }; },
        render: function (d) {
          return [el('div', { class: 'cols-2' }, [rteField(d, 'left', 'Colonne de gauche'), rteField(d, 'right', 'Colonne de droite')])];
        }
      },
      people: {
        label: 'Liste de personnes', desc: 'Membres d’un comité : nom, fonction, commune ou organisme (même présentation partout).',
        defaults: function () { return { items: [] }; },
        render: function (d) {
          var L = itemList(d, 'rows', '.row-item', 'y', function (item, h) {
            function inp(key, label, ph) {
              var i = el('input', { type: 'text', maxlength: '200', placeholder: ph, 'aria-label': label });
              i.value = item[key] || '';
              i.addEventListener('input', function () { item[key] = i.value; changed(); });
              return i;
            }
            return el('li', { class: 'row-item' }, [
              el('span', { class: 'blk-handle', draggable: 'true', 'data-drag': '', title: 'Glisser pour changer l’ordre', html: icon(IC.drag) }),
              el('div', { class: 'row-fields' }, [inp('name', 'Nom et prénom', 'Nom et prénom'), inp('role', 'Fonction', 'Fonction (ex. Président)'), inp('commune', 'Commune ou organisme', 'Commune ou organisme')]),
              el('div', { class: 'row-tools' }, moveTools('y', h, item.name || 'personne'))
            ]);
          }, 'Aucune personne dans la liste.');
          var add = el('button', { type: 'button', class: 'btn', html: icon(IC.plus) + ' Ajouter une personne' });
          add.addEventListener('click', function () { L.add({ name: '', role: '', commune: '' }, true); });
          return [L.list, L.empty, el('div', { class: 'btn-row' }, [add])];
        }
      },
      table: {
        label: 'Tableau', desc: 'Lignes et colonnes (membres, horaires, tarifs…), avec ligne d’en-tête.',
        defaults: function () { return { head: true, rows: [['', ''], ['', '']] }; },
        render: function (d) {
          if (!Array.isArray(d.rows) || !d.rows.length) d.rows = [['', '']];
          var wrap = el('div', { class: 'tbl-edit' });
          var headChk = el('input', { type: 'checkbox' });
          headChk.checked = d.head !== false;
          headChk.addEventListener('change', function () { d.head = headChk.checked; draw(); changed(); });
          function cols() { return d.rows.reduce(function (n, r) { return Math.max(n, r.length); }, 1); }
          function norm() { var c = cols(); d.rows.forEach(function (r) { while (r.length < c) r.push(''); }); }
          function focusCell(r, c) { setTimeout(function () { var t = wrap.querySelector('[data-cell="' + r + '-' + c + '"]'); if (t) t.focus(); }, 0); }
          function grow(t) { t.style.height = 'auto'; t.style.height = (t.scrollHeight + 2) + 'px'; }
          function draw() {
            norm();
            var c = cols();
            var table = el('table', { class: 'tbl-grid' + (d.head !== false ? ' has-head' : '') });
            var ctrl = el('tr', { class: 'tbl-colctrl' });
            for (var j = 0; j < c; j++) {
              (function (j) {
                ctrl.appendChild(el('td', {}, [
                  toolBtn(IC.left, 'Déplacer la colonne ' + (j + 1) + ' à gauche', function () { if (j > 0) { d.rows.forEach(function (r) { var x = r[j]; r[j] = r[j - 1]; r[j - 1] = x; }); draw(); changed(); focusCell(0, j - 1); } }),
                  toolBtn(IC.right, 'Déplacer la colonne ' + (j + 1) + ' à droite', function () { if (j < c - 1) { d.rows.forEach(function (r) { var x = r[j]; r[j] = r[j + 1]; r[j + 1] = x; }); draw(); changed(); focusCell(0, j + 1); } }),
                  toolBtn(IC.trash, 'Supprimer la colonne ' + (j + 1), function () { if (c > 1 && confirm('Supprimer la colonne ' + (j + 1) + ' ?')) { d.rows.forEach(function (r) { r.splice(j, 1); }); draw(); changed(); } }, 'danger')
                ]));
              })(j);
            }
            ctrl.appendChild(el('td'));
            table.appendChild(ctrl);
            d.rows.forEach(function (row, i) {
              var tr = el('tr', { class: i === 0 && d.head !== false ? 'is-head' : '' });
              row.forEach(function (val, j) {
                var t = el('textarea', { rows: '1', 'data-cell': i + '-' + j, 'aria-label': (i === 0 && d.head !== false ? 'En-tête' : 'Ligne ' + (i + 1)) + ', colonne ' + (j + 1) });
                t.value = val || '';
                t.addEventListener('input', function () { row[j] = t.value; grow(t); changed(); });
                t.addEventListener('keydown', function (e) {
                  if (e.key === 'Tab' && !e.shiftKey && i === d.rows.length - 1 && j === c - 1) { e.preventDefault(); d.rows.push(new Array(c).fill('')); draw(); changed(); focusCell(i + 1, 0); }
                });
                tr.appendChild(el('td', {}, [t]));
                setTimeout(function () { grow(t); }, 0);
              });
              tr.appendChild(el('td', { class: 'tbl-rowctrl' }, [
                toolBtn(IC.up, 'Monter la ligne ' + (i + 1), function () { if (i > 0) { var x = d.rows[i]; d.rows[i] = d.rows[i - 1]; d.rows[i - 1] = x; draw(); changed(); focusCell(i - 1, 0); } }),
                toolBtn(IC.down, 'Descendre la ligne ' + (i + 1), function () { if (i < d.rows.length - 1) { var x = d.rows[i]; d.rows[i] = d.rows[i + 1]; d.rows[i + 1] = x; draw(); changed(); focusCell(i + 1, 0); } }),
                toolBtn(IC.trash, 'Supprimer la ligne ' + (i + 1), function () { if (d.rows.length > 1) { d.rows.splice(i, 1); draw(); changed(); } }, 'danger')
              ]));
              table.appendChild(tr);
            });
            wrap.innerHTML = '';
            wrap.appendChild(el('div', { class: 'tbl-scroll' }, [table]));
          }
          draw();
          var addRow = el('button', { type: 'button', class: 'btn', html: icon(IC.plus) + ' Ajouter une ligne' });
          addRow.addEventListener('click', function () { d.rows.push(new Array(cols()).fill('')); draw(); changed(); focusCell(d.rows.length - 1, 0); });
          var addCol = el('button', { type: 'button', class: 'btn', html: icon(IC.plus) + ' Ajouter une colonne' });
          addCol.addEventListener('click', function () { if (cols() >= 8) { alert('8 colonnes au maximum, pour rester lisible sur téléphone.'); return; } d.rows.forEach(function (r) { r.push(''); }); draw(); changed(); focusCell(0, cols() - 1); });
          return [
            el('label', { class: 'check' }, [headChk, el('span', { text: 'La première ligne est une ligne d’en-tête (titres des colonnes)' })]),
            wrap,
            el('div', { class: 'btn-row' }, [addRow, addCol]),
            el('p', { class: 'help', text: 'Astuce : la touche Tab passe à la case suivante et ajoute une ligne à la fin. Les adresses e-mail et web deviennent des liens.' })
          ];
        }
      },
      separator: {
        label: 'Ligne de séparation', desc: 'Une ligne horizontale entre deux parties.',
        defaults: function () { return {}; },
        render: function () { return [el('hr', { class: 'blk-preview-sep' }), el('p', { class: 'muted small', text: 'Une ligne horizontale sépare le contenu au-dessus et en dessous.' })]; }
      }
    };
    var ORDER = ['text', 'heading', 'image', 'gallery', 'files', 'table', 'callout', 'button', 'video', 'columns', 'people', 'contact', 'separator'];

    // ------------------------------------------------------------ choix du type de bloc
    var typeDlg = null, typeResolve = null;
    function chooseType() {
      if (!typeDlg) {
        typeDlg = el('dialog', { class: 'modal', 'aria-labelledby': 'tp-title' });
        var grid = el('ul', { class: 'type-grid', role: 'list' });
        ORDER.forEach(function (t) {
          var b = el('button', { type: 'button', class: 'type-btn', 'data-type': t }, [
            el('span', { class: 'type-icon', html: icon(TI[t], 24) }),
            el('strong', { text: TYPES[t].label }),
            el('small', { text: TYPES[t].desc })
          ]);
          b.addEventListener('click', function () { var r = typeResolve; typeResolve = null; typeDlg.close(); if (r) r(t); });
          grid.appendChild(el('li', null, [b]));
        });
        var close = el('button', { type: 'button', class: 'icon-btn', 'aria-label': 'Fermer', html: icon(IC.close, 20) });
        function cancel() { var r = typeResolve; typeResolve = null; if (typeDlg.open) typeDlg.close(); if (r) r(null); }
        close.addEventListener('click', cancel);
        typeDlg.addEventListener('cancel', function (e) { e.preventDefault(); cancel(); });
        typeDlg.addEventListener('click', function (e) { if (e.target === typeDlg) cancel(); });
        typeDlg.appendChild(el('div', { class: 'modal-inner' }, [
          el('div', { class: 'modal-head' }, [el('h2', { id: 'tp-title', text: 'Quel type de bloc voulez-vous ajouter ?' }), close]),
          el('div', { class: 'modal-body' }, [grid])
        ]));
        document.body.appendChild(typeDlg);
      }
      typeDlg.showModal();
      var first = typeDlg.querySelector('.type-btn');
      if (first) first.focus();
      return new Promise(function (resolve) { typeResolve = resolve; });
    }

    // ------------------------------------------------------------ blocs
    function changed() {
      sync();
      ASIJ.markDirty(form);
    }
    function blockEls() { return Array.prototype.filter.call(root.children, function (n) { return n.classList.contains('blk'); }); }
    function sync() {
      var list = blockEls().map(function (n) { return n._block; });
      hidden.value = JSON.stringify(list);
    }

    function buildBlock(block) {
      var T = TYPES[block.type];
      var sec = el('section', { class: 'blk', 'data-type': block.type });
      sec._block = block;
      var head = el('div', { class: 'blk-head' }, [
        el('span', { class: 'blk-handle', draggable: 'true', title: 'Glisser pour déplacer le bloc', 'aria-hidden': 'true', html: icon(IC.drag) }),
        el('span', { class: 'blk-type', html: icon(TI[block.type]) + '<span></span>' })
      ]);
      head.querySelector('.blk-type span').textContent = T.label;
      var tools = el('div', { class: 'blk-tools' }, [
        toolBtn(IC.up, 'Monter ce bloc', function () { move(sec, -1, this); }),
        toolBtn(IC.down, 'Descendre ce bloc', function () { move(sec, 1, this); }),
        toolBtn(IC.copy, 'Dupliquer ce bloc', function () { duplicate(sec); }),
        toolBtn(IC.trash, 'Supprimer ce bloc', function () { removeBlock(sec); }, 'danger')
      ]);
      tools.children[0].setAttribute('data-up', '');
      tools.children[1].setAttribute('data-down', '');
      head.appendChild(tools);
      var body = el('div', { class: 'blk-body' }, T.render(block.data, block));
      sec.appendChild(head);
      sec.appendChild(body);
      return sec;
    }

    function insertZone() {
      var b = el('button', { type: 'button', html: icon(IC.plus, 14) + ' Ajouter un bloc ici' });
      var z = el('div', { class: 'be-insert' }, [b]);
      b.addEventListener('click', function () {
        var next = z.nextElementSibling && z.nextElementSibling.classList.contains('blk') ? z.nextElementSibling : null;
        addBlock(next);
      });
      return z;
    }

    var addMain = el('div', { class: 'be-add-main' });
    var addBtn = el('button', { type: 'button', class: 'btn', html: icon(IC.plus) + ' Ajouter un bloc' });
    addBtn.addEventListener('click', function () { addBlock(null); });
    addMain.appendChild(addBtn);
    var emptyBox = el('div', { class: 'be-empty' }, [el('p', { text: 'Cette page est vide. Ajoutez un premier bloc : du texte, des photos, des documents…' })]);
    var emptyBtn = el('button', { type: 'button', class: 'btn btn-primary', html: icon(IC.plus) + ' Ajouter le premier bloc' });
    emptyBtn.addEventListener('click', function () { addBlock(null); });
    emptyBox.appendChild(emptyBtn);

    function refresh() {
      root.querySelectorAll(':scope > .be-insert').forEach(function (z) { z.remove(); });
      var els = blockEls();
      els.forEach(function (s, i) {
        if (i > 0) root.insertBefore(insertZone(), s);
        s.querySelector('[data-up]').disabled = i === 0;
        s.querySelector('[data-down]').disabled = i === els.length - 1;
        s.setAttribute('aria-label', 'Bloc ' + (i + 1) + ' sur ' + els.length + ' : ' + TYPES[s._block.type].label);
      });
      if (els.length) {
        if (emptyBox.parentNode) emptyBox.remove();
        root.appendChild(addMain);
      } else {
        if (addMain.parentNode) addMain.remove();
        root.appendChild(emptyBox);
      }
      sync();
    }

    function focusBlock(sec) {
      var f = sec.querySelector('.rte-area, input:not([type=radio]), textarea, select, .blk-body button');
      sec.scrollIntoView({ block: 'center', behavior: 'smooth' });
      if (f) setTimeout(function () { f.focus({ preventScroll: true }); }, 50);
    }

    function addBlock(before) {
      chooseType().then(function (type) {
        if (!type) { (before ? before.querySelector('.blk-head button') : addBtn).focus(); return; }
        var block = { id: newId(), type: type, data: TYPES[type].defaults() };
        var sec = buildBlock(block);
        root.insertBefore(sec, before || (addMain.parentNode ? addMain : null));
        refresh();
        sec.classList.add('is-new');
        changed();
        focusBlock(sec);
        // ouvre directement le sélecteur pour les blocs de médias
        if (type === 'image' || type === 'gallery' || type === 'files') {
          var b = sec.querySelector('.blk-body .btn');
          if (b) setTimeout(function () { b.click(); }, 150);
        }
      });
    }

    function move(sec, dir, btn) {
      var els = blockEls(), i = els.indexOf(sec), j = i + dir;
      if (j < 0 || j >= els.length) return;
      if (dir < 0) root.insertBefore(sec, els[j]); else root.insertBefore(sec, els[j].nextSibling);
      refresh();
      changed();
      sec.scrollIntoView({ block: 'nearest' });
      var again = sec.querySelector(dir < 0 ? '[data-up]' : '[data-down]');
      (again && !again.disabled ? again : sec.querySelector('.blk-tools button:not([disabled])')).focus();
      ASIJ.toast('Bloc déplacé.');
    }

    function duplicate(sec) {
      var b = clone(sec._block);
      b.id = newId();
      var copy = buildBlock(b);
      root.insertBefore(copy, sec.nextSibling);
      refresh();
      copy.classList.add('is-new');
      changed();
      focusBlock(copy);
      ASIJ.toast('Bloc dupliqué.');
    }

    function removeBlock(sec) {
      var label = TYPES[sec._block.type].label;
      if (!window.confirm('Supprimer ce bloc « ' + label + ' » ? Vous pourrez encore annuler en quittant la page sans enregistrer.')) return;
      var els = blockEls(), i = els.indexOf(sec);
      sec.remove();
      refresh();
      changed();
      var rest = blockEls();
      var target = rest[Math.min(i, rest.length - 1)];
      (target ? target.querySelector('.blk-tools button:not([disabled])') : emptyBtn).focus();
      ASIJ.toast('Bloc supprimé.');
    }

    // glisser-déposer des blocs
    var dragBlock = null;
    root.addEventListener('dragstart', function (e) {
      var h = e.target.closest && e.target.closest('.blk-handle');
      if (!h || h.hasAttribute('data-drag') || dragItem) return;
      dragBlock = h.closest('.blk');
      dragBlock.classList.add('is-dragging');
      e.dataTransfer.effectAllowed = 'move';
      try { e.dataTransfer.setData('text/plain', 'bloc'); } catch (err) { /* ignoré */ }
      try { e.dataTransfer.setDragImage(dragBlock, 30, 20); } catch (err) { /* ignoré */ }
    });
    function clearMarks() { root.querySelectorAll(':scope > .drop-before, :scope > .drop-after').forEach(function (n) { n.classList.remove('drop-before', 'drop-after'); }); }
    root.addEventListener('dragover', function (e) {
      if (!dragBlock) return;
      e.preventDefault();
      clearMarks();
      var t = e.target.closest('.blk');
      if (!t || t === dragBlock || t.parentNode !== root) return;
      var r = t.getBoundingClientRect();
      t.classList.add(e.clientY < r.top + r.height / 2 ? 'drop-before' : 'drop-after');
    });
    root.addEventListener('drop', function (e) {
      if (!dragBlock) return;
      e.preventDefault();
      var t = root.querySelector(':scope > .drop-before, :scope > .drop-after');
      clearMarks();
      if (t) {
        root.insertBefore(dragBlock, t.classList.contains('drop-before') ? t : t.nextSibling);
        refresh();
        changed();
        ASIJ.toast('Bloc déplacé.');
      }
    });
    root.addEventListener('dragend', function () {
      if (dragBlock) dragBlock.classList.remove('is-dragging');
      dragBlock = null;
      clearMarks();
    });

    // ------------------------------------------------------------ démarrage
    (Array.isArray(cfg.blocks) ? cfg.blocks : []).forEach(function (b) {
      if (!b || !TYPES[b.type]) return;
      var block = { id: b.id || newId(), type: b.type, data: (b.data && typeof b.data === 'object' && !Array.isArray(b.data)) ? b.data : TYPES[b.type].defaults() };
      root.appendChild(buildBlock(block));
    });
    refresh();

    form.addEventListener('submit', function () { sync(); });
    document.addEventListener('keydown', function (e) {
      if ((e.ctrlKey || e.metaKey) && !e.altKey && e.key.toLowerCase() === 's') {
        e.preventDefault();
        if (document.querySelector('dialog[open]')) return;
        sync();
        var btn = form.querySelector('[data-save]');
        if (form.requestSubmit) form.requestSubmit(btn || undefined); else form.submit();
      }
    });
    if (form.getAttribute('data-new') === '1') {
      var t = document.getElementById('f-title');
      if (t && !t.value) t.focus();
    }
    window.ASIJEditor = { sync: sync, blocks: function () { return JSON.parse(hidden.value); }, cleanHtml: cleanHtml };
  });
})();
