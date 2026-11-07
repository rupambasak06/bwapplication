(function () {
  'use strict';

  function init() {
    var container = document.getElementById('fields-container');
    var addBtn    = document.getElementById('add-field-btn');
    var template  = document.getElementById('field-template');
    var theForm   = document.getElementById('form-builder-form');
    if (!container || !addBtn || !template || !theForm) return;

    var nextIdx = container.querySelectorAll('[data-field-card]').length + 1000;

    addBtn.addEventListener('click', function () {
      var html = template.innerHTML.replace(/__IDX__/g, String(nextIdx++));
      var wrap = document.createElement('div');
      wrap.innerHTML = html.trim();
      var node = wrap.firstChild;
      container.appendChild(node);
      var firstInput = node.querySelector('input[name$="[label]"]');
      if (firstInput) firstInput.focus();
    });

    container.addEventListener('click', function (ev) {
      var removeBtn = ev.target.closest ? ev.target.closest('[data-remove-field]') : null;
      if (removeBtn) {
        var card = removeBtn.closest('[data-field-card]');
        if (card) card.remove();
        return;
      }
      var upBtn = ev.target.closest ? ev.target.closest('[data-move="up"]') : null;
      if (upBtn) {
        var c = upBtn.closest('[data-field-card]');
        if (c && c.previousElementSibling) c.parentNode.insertBefore(c, c.previousElementSibling);
        return;
      }
      var downBtn = ev.target.closest ? ev.target.closest('[data-move="down"]') : null;
      if (downBtn) {
        var c2 = downBtn.closest('[data-field-card]');
        if (c2 && c2.nextElementSibling) c2.parentNode.insertBefore(c2.nextElementSibling, c2);
        return;
      }
    });

    // On submit, recompute display_order from DOM position
    theForm.addEventListener('submit', function () {
      var cards = container.querySelectorAll('[data-field-card]');
      var o = 0;
      cards.forEach(function (card) {
        o++;
        var input = card.querySelector('[data-order-input]');
        if (input) input.value = String(o);
      });
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();