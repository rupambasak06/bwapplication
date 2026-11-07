(function () {
  'use strict';

  /* Mobile drawer */
  const open   = document.querySelector('[data-drawer-open]');
  const drawer = document.querySelector('[data-mobile-drawer]');
  if (open && drawer) {
    open.addEventListener('click', function () { drawer.classList.remove('hidden'); });
    drawer.querySelectorAll('[data-drawer-close]').forEach(function (el) {
      el.addEventListener('click', function () { drawer.classList.add('hidden'); });
    });
  }

  /* Confirm dialogs for form submits */
  document.addEventListener('submit', function (ev) {
    const form = ev.target;
    if (!form || !form.getAttribute) return;
    const msg = form.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) {
      ev.preventDefault();
    }
  }, true);

  /* Confirm dialogs for delete links */
  document.addEventListener('click', function (ev) {
    const el = ev.target.closest('[data-confirm]');
    if (!el || el.tagName === 'FORM') return;
    if (!window.confirm(el.getAttribute('data-confirm'))) {
      ev.preventDefault();
    }
  });

  /* Flash auto-dismiss */
  const flash = document.querySelector('[data-flash-container]');
  if (flash) {
    setTimeout(function () {
      flash.querySelectorAll(':scope > div').forEach(function (node) {
        node.style.transition = 'opacity .3s ease';
        node.style.opacity = '0';
      });
      setTimeout(function () { flash.remove(); }, 400);
    }, 5000);
  }
})();