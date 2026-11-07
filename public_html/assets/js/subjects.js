/**
 * Brainwavers — Subject management row controls.
 * Delegated handlers so the same script works for rows rendered by PHP
 * and for rows appended by "+ Add Row".
 */
(function () {
  'use strict';

  function buildRowHTML(type) {
    var t = String(type).replace(/[^a-z]/g, '');
    return '' +
      '<input type="hidden" name="' + t + '_ids[]" value="0">' +
      '<input type="text" name="' + t + '_names[]" class="form-input subject-name" ' +
      'maxlength="200" placeholder="Subject name">' +
      '<button type="button" class="btn-icon" data-move="up" aria-label="Move up" title="Move up">▲</button>' +
      '<button type="button" class="btn-icon" data-move="down" aria-label="Move down" title="Move down">▼</button>' +
      '<button type="button" class="btn-icon btn-icon-danger" data-remove aria-label="Remove" title="Remove">✕</button>';
  }

  document.addEventListener('click', function (ev) {
    var t = ev.target;

    var addBtn = t.closest ? t.closest('[data-add-subject-row]') : null;
    if (addBtn) {
      var type = addBtn.getAttribute('data-add-subject-row');
      var container = document.querySelector('[data-subject-container="' + type + '"]');
      if (!container) return;
      var row = document.createElement('div');
      row.className = 'subject-row';
      row.innerHTML = buildRowHTML(type);
      container.appendChild(row);
      var input = row.querySelector('.subject-name');
      if (input) input.focus();
      return;
    }

    var removeBtn = t.closest ? t.closest('[data-remove]') : null;
    if (removeBtn) {
      var rrow = removeBtn.closest('.subject-row');
      if (rrow) rrow.remove();
      return;
    }

    var upBtn = t.closest ? t.closest('[data-move="up"]') : null;
    if (upBtn) {
      var urow = upBtn.closest('.subject-row');
      if (urow && urow.previousElementSibling) {
        urow.parentNode.insertBefore(urow, urow.previousElementSibling);
        var uInput = urow.querySelector('.subject-name');
        if (uInput) uInput.focus();
      }
      return;
    }

    var downBtn = t.closest ? t.closest('[data-move="down"]') : null;
    if (downBtn) {
      var drow = downBtn.closest('.subject-row');
      if (drow && drow.nextElementSibling) {
        drow.parentNode.insertBefore(drow.nextElementSibling, drow);
        var dInput = drow.querySelector('.subject-name');
        if (dInput) dInput.focus();
      }
      return;
    }
  });
})();