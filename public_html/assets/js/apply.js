(function () {
  'use strict';

  function init() {
    var form = document.getElementById('apply-form');
    if (!form) return;

    var instSel  = document.getElementById('institution_id');
    var courseSel = document.getElementById('course_id');
    var compBox  = document.getElementById('compulsory_subjects_container');
    var extraBox = document.getElementById('extra_subjects_container');
    var counter  = document.getElementById('extra_subjects_counter');
    if (!instSel || !courseSel || !compBox || !extraBox) return;

    var state = window.__bw_apply_state || {};
    var sectionId = state.sectionId || parseInt(form.dataset.sectionId, 10) || 0;
    var maxExtra = 0;

    instSel.addEventListener('change', function () {
      loadCourses(instSel.value, null);
    });
    courseSel.addEventListener('change', function () {
      loadSubjects(courseSel.value);
    });

    function loadCourses(institutionId, preselectCourseId) {
      courseSel.innerHTML = '<option value="">Loading courses…</option>';
      courseSel.disabled = true;
      compBox.innerHTML = '<p class="text-sm text-slate-500">Select a course to see its compulsory subjects.</p>';
      extraBox.innerHTML = '<p class="text-sm text-slate-500">Select a course to see available extra subjects.</p>';
      extraBox.setAttribute('data-chosen', '');
      if (counter) counter.textContent = '';
      maxExtra = 0;

      if (!institutionId) {
        courseSel.innerHTML = '<option value="">Select an institution first…</option>';
        return;
      }

      fetch('/api/get-courses.php?section_id=' + encodeURIComponent(sectionId) + '&institution_id=' + encodeURIComponent(institutionId))
        .then(function (r) { return r.json(); })
        .then(function (data) {
          courseSel.innerHTML = '<option value="">Choose a course…</option>';
          if (!data.ok || !data.courses || !data.courses.length) {
            courseSel.innerHTML = '<option value="">No courses available</option>';
            return;
          }
          data.courses.forEach(function (c) {
            var opt = document.createElement('option');
            opt.value = c.id;
            opt.textContent = c.name + (c.duration ? ' (' + c.duration + ')' : '');
            if (preselectCourseId && String(c.id) === String(preselectCourseId)) opt.selected = true;
            courseSel.appendChild(opt);
          });
          courseSel.disabled = false;
          if (preselectCourseId) loadSubjects(preselectCourseId);
        })
        .catch(function () {
          courseSel.innerHTML = '<option value="">Could not load courses</option>';
        });
    }

    function loadSubjects(courseId) {
      compBox.innerHTML = '<p class="text-sm text-slate-500">Loading…</p>';
      extraBox.innerHTML = '';
      if (counter) counter.textContent = '';
      maxExtra = 0;

      if (!courseId) {
        compBox.innerHTML = '<p class="text-sm text-slate-500">Select a course to see its compulsory subjects.</p>';
        return;
      }

      fetch('/api/get-subjects.php?section_id=' + encodeURIComponent(sectionId)
            + '&institution_id=' + encodeURIComponent(instSel.value)
            + '&course_id=' + encodeURIComponent(courseId))
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (!data.ok) {
            compBox.innerHTML = '<p class="text-sm text-red-600">' + escapeHtml(data.error || 'Could not load subjects.') + '</p>';
            return;
          }
          renderCompulsory(data.compulsory || []);
          renderExtras(data.extra || [], parseInt(data.max_extra, 10) || 0);
        })
        .catch(function () {
          compBox.innerHTML = '<p class="text-sm text-red-600">Could not load subjects.</p>';
        });
    }

    function renderCompulsory(subjects) {
      if (!subjects.length) {
        compBox.innerHTML = '<p class="text-sm text-red-600">This course has no compulsory subjects. Please contact the administration.</p>';
        return;
      }
      compBox.innerHTML = '';
      subjects.forEach(function (s) {
        var div = document.createElement('div');
        div.className = 'flex items-center gap-2 py-1';
        div.innerHTML = '<input type="checkbox" checked disabled class="rounded border-slate-300">'
          + '<span class="text-sm font-medium">' + escapeHtml(s.name) + '</span>'
          + '<span class="badge badge-info text-xs">Compulsory</span>';
        compBox.appendChild(div);
      });
    }

    function renderExtras(subjects, max) {
      maxExtra = Math.max(0, max);
      extraBox.innerHTML = '';
      var chosen = (extraBox.getAttribute('data-chosen') || '').split(',').filter(Boolean).map(String);

      if (!subjects.length) {
        extraBox.innerHTML = '<p class="text-sm text-slate-500">No extra subjects are available for this course.</p>';
        if (counter) counter.textContent = '';
        return;
      }
      if (maxExtra === 0) {
        extraBox.innerHTML = '<p class="text-sm text-slate-500">This course does not allow extra subject selection.</p>';
        if (counter) counter.textContent = '';
        return;
      }
      subjects.forEach(function (s) {
        var label = document.createElement('label');
        label.className = 'flex items-center gap-2 text-sm';
        var cb = document.createElement('input');
        cb.type = 'checkbox';
        cb.name = 'extra_subject_ids[]';
        cb.value = s.id;
        cb.className = 'rounded border-slate-300';
        if (chosen.indexOf(String(s.id)) !== -1) cb.checked = true;
        cb.addEventListener('change', updateCounter);
        label.appendChild(cb);
        var span = document.createElement('span');
        span.textContent = s.name;
        label.appendChild(span);
        extraBox.appendChild(label);
      });
      // Enforce limit on init
      var checked = extraBox.querySelectorAll('input[type="checkbox"]:checked');
      if (checked.length > maxExtra) {
        for (var i = maxExtra; i < checked.length; i++) checked[i].checked = false;
      }
      updateCounter();
    }

    function updateCounter() {
      if (!counter) return;
      var checked = extraBox.querySelectorAll('input[type="checkbox"]:checked');
      if (checked.length > maxExtra) {
        checked[checked.length - 1].checked = false;
        checked = extraBox.querySelectorAll('input[type="checkbox"]:checked');
      }
      counter.textContent = 'Selected ' + checked.length + ' of ' + maxExtra + ' extra subject(s).';
      counter.className = 'text-xs mt-2 ' + (checked.length >= maxExtra ? 'text-green-700' : 'text-slate-600');
    }

    function escapeHtml(s) {
      var d = document.createElement('div');
      d.textContent = String(s);
      return d.innerHTML;
    }

    // Initial state restore
    var pi = state.preselectedInstitution || (parseInt(instSel.value, 10) || 0);
    if (pi) {
      // Ensure the institution is selected (it should be, from server-side render)
      var pc = state.preselectedCourse || 0;
      if (pc) loadCourses(pi, pc);
      else loadCourses(pi, null);
    }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();