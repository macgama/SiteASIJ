/* Espace sécurisé: petites aides sans dépendance (afficher le mot de passe, confirmations, copie). */
(function () {
  'use strict';
  document.addEventListener('change', function (ev) {
    var t = ev.target;
    if (t && t.matches && t.matches('[data-show-password]')) {
      t.getAttribute('data-show-password').split(/\s+/).forEach(function (id) {
        var input = document.getElementById(id);
        if (input) { input.type = t.checked ? 'text' : 'password'; }
      });
    }
  });
  document.addEventListener('submit', function (ev) {
    var f = ev.target;
    if (f && f.matches && f.matches('form[data-confirm]') && !window.confirm(f.getAttribute('data-confirm'))) {
      ev.preventDefault();
    }
  });
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest ? ev.target.closest('[data-copy]') : null;
    if (!b) { return; }
    var src = document.getElementById(b.getAttribute('data-copy'));
    if (!src || !navigator.clipboard) { return; }
    navigator.clipboard.writeText(src.textContent.replace(/\s+/g, '')).then(function () {
      var old = b.textContent;
      b.textContent = 'Clé copiée';
      setTimeout(function () { b.textContent = old; }, 2000);
    });
  });
})();
