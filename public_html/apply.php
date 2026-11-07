<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/form-renderer.php';

$page_title = 'Apply Online';
$siteAvailable = (int)app_setting('website_available', '1') === 1;

$sections = [];
if ($siteAvailable) {
    try {
        $sections = db()->query(
            'SELECT s.id, s.name, s.description
             FROM academic_sections s
             JOIN application_forms f ON f.academic_section_id = s.id AND f.status = "published"
             WHERE s.is_active = 1 AND s.is_archived = 0
             ORDER BY s.display_order, s.name'
        )->fetchAll();
    } catch (Throwable $e) { /* ignore */ }
}

$sectionId = (int)($_GET['section_id'] ?? 0);
$form = null;
$fields = [];
$institutions = [];
$formError = '';
$applyValues = [];
$applyErrors = [];

if ($sectionId > 0 && $siteAvailable) {
    try {
        $stmt = db()->prepare(
            'SELECT f.*, s.name AS section_name
             FROM application_forms f
             JOIN academic_sections s ON s.id = f.academic_section_id
             WHERE f.academic_section_id = ? AND f.status = "published"
               AND s.is_active = 1 AND s.is_archived = 0
             LIMIT 1'
        );
        $stmt->execute([$sectionId]);
        $form = $stmt->fetch();

        if ($form) {
            $stmt = db()->prepare(
                'SELECT * FROM form_fields WHERE form_id = ? AND is_active = 1
                 ORDER BY display_order, id'
            );
            $stmt->execute([(int)$form['id']]);
            $fields = $stmt->fetchAll();

            $stmt = db()->prepare(
                'SELECT id, name FROM institutions WHERE academic_section_id = ?
                 AND is_active = 1 AND is_archived = 0 ORDER BY display_order, name'
            );
            $stmt->execute([$sectionId]);
            $institutions = $stmt->fetchAll();

            if (isset($_SESSION['_apply_last']) && is_array($_SESSION['_apply_last'])) {
                $last = $_SESSION['_apply_last'];
                if (($last['section_id'] ?? 0) === $sectionId) {
                    $applyValues = $last['values'] ?? [];
                    $applyErrors = $last['errors'] ?? [];
                }
                unset($_SESSION['_apply_last']);
            }
        } else {
            $formError = 'The application form for this section is not available.';
        }
    } catch (Throwable $e) {
        $formError = 'Could not load the application form.';
    }
}

require __DIR__ . '/../includes/public-header.php';
?>

<?php if (!$siteAvailable): ?>
  <div class="card card-body max-w-2xl mx-auto text-center">
    <h2 class="text-xl font-semibold mb-2">Applications Temporarily Closed</h2>
    <p class="text-slate-600">New applications are not being accepted at this time.</p>
  </div>

<?php elseif ($sectionId === 0): ?>

  <div class="max-w-4xl mx-auto">
    <h2 class="text-2xl font-semibold mb-2">Apply Online</h2>
    <p class="text-slate-600 mb-6">Choose an academic section to begin. Your application form is determined by the section you select.</p>

    <?php if (empty($sections)): ?>
      <div class="card card-body text-center text-slate-500">
        No applications are open at this time. Please check back soon.
      </div>
    <?php else: ?>
      <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <?php foreach ($sections as $s): ?>
          <a href="<?= e(absolute_url('/apply.php?section_id=' . (int)$s['id'])) ?>"
             class="card card-body hover:bg-slate-50 transition block">
            <div class="font-semibold text-slate-900"><?= e($s['name']) ?></div>
            <?php if (!empty($s['description'])): ?>
              <p class="text-sm text-slate-600 mt-2 line-clamp-3"><?= e($s['description']) ?></p>
            <?php endif; ?>
            <div class="mt-3 text-sm text-indigo-900 font-medium">Start application →</div>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

<?php elseif ($formError !== ''): ?>

  <div class="card card-body max-w-2xl mx-auto text-center">
    <div class="alert alert-error"><?= e($formError) ?></div>
    <a href="<?= e(absolute_url('/apply.php')) ?>" class="btn btn-primary mt-4">Choose Another Section</a>
  </div>

<?php else: ?>

  <div class="max-w-3xl mx-auto">
    <div class="card mb-4">
      <div class="card-body">
        <div class="text-xs text-slate-500">Academic Section</div>
        <div class="text-lg font-semibold"><?= e($form['section_name']) ?></div>
        <div class="text-sm text-slate-600 mt-1"><?= e($form['title']) ?></div>
        <?php if (!empty($form['description'])): ?>
          <p class="text-sm text-slate-600 mt-2"><?= e($form['description']) ?></p>
        <?php endif; ?>
      </div>
    </div>

    <?php if (!empty($applyErrors['general'])): ?>
      <div class="alert alert-error mb-4"><?= e($applyErrors['general']) ?></div>
    <?php endif; ?>

    <?php if (empty($institutions)): ?>
      <div class="alert alert-warning mb-4">No institutions are currently available for this section. Please choose a different section.</div>
    <?php endif; ?>

    <form id="apply-form"
          method="post"
          action="<?= e(absolute_url('/submit-application.php')) ?>"
          enctype="multipart/form-data"
          data-section-id="<?= (int)$sectionId ?>"
          novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="section_id" value="<?= (int)$sectionId ?>">
      <input type="hidden" name="_form_id"   value="<?= (int)$form['id'] ?>">
      <input type="hidden" name="_form_version_id" value="<?= (int)($form['current_version_id'] ?? 0) ?>">

      <div class="card card-body space-y-4">
        <?php
          $ctx = [
            'errors'       => $applyErrors,
            'field_values' => $applyValues['fields'] ?? [],
            'institutions' => $institutions,
            'institution_id' => (int)($applyValues['institution_id'] ?? 0),
            'course_id'      => (int)($applyValues['course_id'] ?? 0),
            'extra_ids'      => array_map('intval', $applyValues['extra_subject_ids'] ?? []),
          ];
          foreach ($fields as $f) {
              echo render_form_field($f, $ctx);
          }
        ?>
      </div>

      <div class="card card-body mt-4 flex flex-wrap items-center justify-between gap-3">
        <a href="<?= e(absolute_url('/apply.php')) ?>" class="text-sm text-slate-600 hover:text-indigo-900">← Choose another section</a>
        <button type="submit" class="btn btn-primary">
          Submit Application
        </button>
      </div>
    </form>

    <p class="text-xs text-slate-500 text-center mt-4">
      Your data is transmitted securely. Review all details before submitting.
    </p>
  </div>

  <script>
    window.__bw_apply_state = {
      sectionId:            <?= json_encode($sectionId) ?>,
      preselectedInstitution: <?= json_encode((int)($applyValues['institution_id'] ?? 0)) ?>,
      preselectedCourse:      <?= json_encode((int)($applyValues['course_id'] ?? 0)) ?>
    };
  </script>
  <script src="<?= e(absolute_url('/assets/js/apply.js')) ?>"></script>

<?php endif; ?>

<?php require __DIR__ . '/../includes/public-footer.php'; ?>