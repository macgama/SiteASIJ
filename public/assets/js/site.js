/* ASIJ – site public : menu (burger + sous-menus accessibles au clavier) et visionneuse photo.
   Sans dépendance. */
(function () {
  'use strict';

  var desktop = window.matchMedia('(min-width: 64em)');

  /* ------------------------------------------------------------ Menu */
  function initMenu() {
    var nav = document.getElementById('main-nav');
    if (!nav) return;
    var burger = document.querySelector('.nav-toggle');

    function setOpen(btn, open) {
      var panel = document.getElementById(btn.getAttribute('aria-controls'));
      if (!panel) return;
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      panel.classList.toggle('is-open', open);
      if (!open) {
        // fermer aussi les sous-niveaux
        panel.querySelectorAll('.sub-toggle[aria-expanded="true"]').forEach(function (b) { setOpen(b, false); });
      }
    }

    function closeSiblings(btn) {
      var li = btn.closest('li');
      if (!li || !li.parentNode) return;
      Array.prototype.forEach.call(li.parentNode.children, function (other) {
        if (other === li) return;
        var b = other.querySelector(':scope > .nav-row > .sub-toggle');
        if (b && b.getAttribute('aria-expanded') === 'true') setOpen(b, false);
      });
    }

    function closeAll() {
      nav.querySelectorAll('.nav-l1 > li > .nav-row > .sub-toggle[aria-expanded="true"]').forEach(function (b) { setOpen(b, false); });
    }

    // Sur la page courante, ouvrir la branche active dans le menu mobile.
    function openActiveBranch() {
      nav.querySelectorAll('li.is-active > .nav-row > .sub-toggle').forEach(function (b) {
        setOpen(b, true);
      });
    }

    nav.addEventListener('click', function (ev) {
      var btn = ev.target.closest('.sub-toggle');
      if (!btn) return;
      var open = btn.getAttribute('aria-expanded') !== 'true';
      if (open) closeSiblings(btn);
      setOpen(btn, open);
    });

    if (burger) {
      burger.addEventListener('click', function () {
        var open = burger.getAttribute('aria-expanded') !== 'true';
        burger.setAttribute('aria-expanded', open ? 'true' : 'false');
        nav.classList.toggle('is-open', open);
        if (open) {
          openActiveBranch();
          var first = nav.querySelector('a');
          if (first) first.focus();
        }
      });
    }

    // Échap : ferme le sous-menu ouvert (et rend le focus à son bouton), sinon le menu mobile.
    document.addEventListener('keydown', function (ev) {
      var lb = document.getElementById('lightbox');
      if (ev.key !== 'Escape' || (lb && !lb.hidden)) return;
      var inside = nav.contains(document.activeElement);
      var openBtns = nav.querySelectorAll('.sub-toggle[aria-expanded="true"]');
      if (openBtns.length && (inside || desktop.matches)) {
        var focusedLi = document.activeElement && document.activeElement.closest ? document.activeElement.closest('.nav-sub') : null;
        var target = null;
        var act = document.activeElement;
        if (act && act.classList && act.classList.contains('sub-toggle') && act.getAttribute('aria-expanded') === 'true') {
          target = act;
        } else if (focusedLi) {
          target = nav.querySelector('.sub-toggle[aria-controls="' + focusedLi.id + '"]');
        }
        if (!target) target = openBtns[openBtns.length - 1];
        setOpen(target, false);
        if (inside) target.focus();
        ev.preventDefault();
        return;
      }
      if (burger && burger.getAttribute('aria-expanded') === 'true' && !desktop.matches) {
        burger.click();
        burger.focus();
      }
    });

    // Grand écran : clic ailleurs ou focus qui quitte le menu = fermeture.
    document.addEventListener('click', function (ev) {
      if (desktop.matches && !nav.contains(ev.target)) closeAll();
    });
    nav.addEventListener('focusout', function (ev) {
      if (!desktop.matches) return;
      var li = ev.target.closest('.nav-l1 > li');
      if (li && ev.relatedTarget && !li.contains(ev.relatedTarget)) {
        var b = li.querySelector(':scope > .nav-row > .sub-toggle');
        if (b) setOpen(b, false);
      }
    });

    // Flèches haut/bas dans un panneau déroulant (confort clavier).
    nav.addEventListener('keydown', function (ev) {
      if (!desktop.matches || (ev.key !== 'ArrowDown' && ev.key !== 'ArrowUp')) return;
      var panel = ev.target.closest('.nav-sub-l2');
      var btn = ev.target.closest('.nav-l1 > li > .nav-row > .sub-toggle');
      if (btn && ev.key === 'ArrowDown') {
        setOpen(btn, true);
        panel = document.getElementById(btn.getAttribute('aria-controls'));
      } else if (!panel) {
        return;
      }
      var items = Array.prototype.filter.call(panel.querySelectorAll('a, .sub-toggle'), function (el) {
        return el.offsetParent !== null;
      });
      if (!items.length) return;
      var i = items.indexOf(document.activeElement);
      var next = ev.key === 'ArrowDown' ? items[Math.min(items.length - 1, i + 1)] : items[Math.max(0, i - 1)];
      if (next) { next.focus(); ev.preventDefault(); }
    });

    desktop.addEventListener && desktop.addEventListener('change', function () {
      closeAll();
      if (burger) burger.setAttribute('aria-expanded', 'false');
      nav.classList.remove('is-open');
    });
  }

  /* ------------------------------------------------------------ Visionneuse */
  function initLightbox() {
    var box = document.getElementById('lightbox');
    if (!box) return;
    var img = box.querySelector('.lightbox-img');
    var cap = box.querySelector('.lightbox-caption');
    var count = box.querySelector('.lightbox-count');
    var btnPrev = box.querySelector('.lightbox-prev');
    var btnNext = box.querySelector('.lightbox-next');
    var btnClose = box.querySelector('.lightbox-close');
    var items = [];
    var index = 0;
    var opener = null;
    var touchX = null;

    function show(i) {
      index = (i + items.length) % items.length;
      var a = items[index];
      var thumb = a.querySelector('img');
      img.src = a.getAttribute('href');
      img.alt = thumb ? thumb.alt : '';
      var c = a.getAttribute('data-caption') || '';
      cap.textContent = c;
      cap.hidden = c === '';
      count.textContent = items.length > 1 ? 'Photo ' + (index + 1) + ' sur ' + items.length : '';
      btnPrev.hidden = btnNext.hidden = items.length < 2;
      // précharger la suivante
      if (items.length > 1) {
        var pre = new Image();
        pre.src = items[(index + 1) % items.length].getAttribute('href');
      }
    }

    function open(link) {
      var gallery = link.closest('[data-gallery]');
      items = Array.prototype.slice.call((gallery || document).querySelectorAll('a[data-lightbox]'));
      opener = link;
      show(Math.max(0, items.indexOf(link)));
      box.hidden = false;
      document.body.classList.add('lightbox-open');
      btnClose.focus();
    }

    function close() {
      box.hidden = true;
      document.body.classList.remove('lightbox-open');
      img.src = 'data:,';
      if (opener) opener.focus();
    }

    document.addEventListener('click', function (ev) {
      var link = ev.target.closest('a[data-lightbox]');
      if (!link || ev.ctrlKey || ev.metaKey || ev.shiftKey || ev.button > 0) return;
      ev.preventDefault();
      open(link);
    });
    btnClose.addEventListener('click', close);
    btnPrev.addEventListener('click', function () { show(index - 1); });
    btnNext.addEventListener('click', function () { show(index + 1); });
    box.addEventListener('click', function (ev) {
      if (ev.target === box || ev.target.classList.contains('lightbox-inner')) close();
    });

    document.addEventListener('keydown', function (ev) {
      if (box.hidden) return;
      if (ev.key === 'Escape') { ev.preventDefault(); close(); }
      else if (ev.key === 'ArrowLeft') { ev.preventDefault(); show(index - 1); }
      else if (ev.key === 'ArrowRight') { ev.preventDefault(); show(index + 1); }
      else if (ev.key === 'Tab') {
        // garder le focus dans la visionneuse
        var f = Array.prototype.filter.call(box.querySelectorAll('button'), function (b) { return !b.hidden; });
        var i = f.indexOf(document.activeElement);
        var next = ev.shiftKey ? f[(i - 1 + f.length) % f.length] : f[(i + 1) % f.length];
        if (next) { ev.preventDefault(); next.focus(); }
      }
    });

    box.addEventListener('touchstart', function (ev) { touchX = ev.touches[0].clientX; }, { passive: true });
    box.addEventListener('touchend', function (ev) {
      if (touchX === null) return;
      var dx = ev.changedTouches[0].clientX - touchX;
      touchX = null;
      if (Math.abs(dx) > 50 && items.length > 1) show(index + (dx < 0 ? 1 : -1));
    });
  }

  /* ------------------------------------------------------------ Apparition des cartes */
  function initReveal() {
    if (!('IntersectionObserver' in window) || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    var els = document.querySelectorAll('.news-card, .rub-card, .subpage-list > li, .gallery-grid > li, .block-people > li');
    if (!els.length) return;
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        if (en.isIntersecting) { en.target.classList.add('is-visible'); io.unobserve(en.target); }
      });
    }, { rootMargin: '0px 0px -8% 0px' });
    Array.prototype.forEach.call(els, function (el) {
      var sib = el.parentNode ? Array.prototype.indexOf.call(el.parentNode.children, el) : 0;
      el.style.setProperty('--d', String(Math.min(sib, 8)));
      el.classList.add('reveal');
      io.observe(el);
    });
  }

  function init() {
    initMenu();
    initLightbox();
    initReveal();
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
