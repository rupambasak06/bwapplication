(function () {
  'use strict';

  const toggle = document.querySelector('[data-mobile-menu-toggle]');
  const menu   = document.querySelector('[data-mobile-menu]');
  if (toggle && menu) {
    toggle.addEventListener('click', function () {
      menu.classList.toggle('hidden');
    });
  }

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