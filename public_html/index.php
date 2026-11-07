<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';

$page_title = 'Welcome';
$sections = [];
$institutionCount = 0;
$courseCount = 0;

try {
    $sections = db()->query(
        'SELECT id, name, description
         FROM academic_sections
         WHERE is_active = 1 AND is_archived = 0
         ORDER BY display_order, name
         LIMIT 8'
    )->fetchAll();
    $institutionCount = (int)db()->query(
        'SELECT COUNT(*) FROM institutions WHERE is_active = 1 AND is_archived = 0'
    )->fetchColumn();
    $courseCount = (int)db()->query(
        'SELECT COUNT(*) FROM courses WHERE is_active = 1 AND is_archived = 0'
    )->fetchColumn();
} catch (Throwable $e) {
    // Database not yet configured; render the static skeleton.
}

require __DIR__ . '/../includes/public-header.php';
?>

<section class="bg-white border border-slate-200 rounded-lg p-6 sm:p-8 mb-8">
  <h2 class="text-2xl sm:text-3xl font-bold">Welcome to <?= e(app_setting('institute_name', 'Brainwavers')) ?></h2>
  <p class="mt-3 text-slate-600 max-w-2xl">
    Apply online for programs offered by our affiliated universities, colleges, schools, and institutes.
    Complete the application, upload your documents, and track your admission status — all in one place.
  </p>
  <div class="mt-6 flex flex-wrap gap-3">
    <?php if (is_file(BW_PUBLIC_DIR . '/apply.php')): ?>
      <a href="<?= e(absolute_url('/apply.php')) ?>" class="rounded bg-indigo-900 text-white px-5 py-2.5 font-medium hover:bg-indigo-800">Start Application</a>
    <?php endif; ?>
    <?php if (is_file(BW_PUBLIC_DIR . '/check-status.php')): ?>
      <a href="<?= e(absolute_url('/check-status.php')) ?>" class="rounded border border-slate-300 text-slate-700 px-5 py-2.5 hover:bg-slate-100">Check Application Status</a>
    <?php endif; ?>
  </div>
</section>

<section class="mb-8">
  <h3 class="text-lg font-semibold mb-4">Academic Sections</h3>
  <?php if (empty($sections)): ?>
    <div class="bg-white border border-slate-200 rounded-lg p-6 text-slate-500">
      No academic sections are available yet.
    </div>
  <?php else: ?>
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
      <?php foreach ($sections as $s): ?>
        <div class="bg-white border border-slate-200 rounded-lg p-5">
          <div class="font-semibold"><?= e($s['name']) ?></div>
          <?php if (!empty($s['description'])): ?>
            <p class="mt-2 text-sm text-slate-600 line-clamp-3"><?= e($s['description']) ?></p>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<section class="grid gap-4 sm:grid-cols-2">
  <div class="bg-white border border-slate-200 rounded-lg p-6">
    <div class="text-3xl font-bold text-indigo-900"><?= (int)$institutionCount ?></div>
    <div class="text-slate-600 mt-1">Affiliated Institutions</div>
  </div>
  <div class="bg-white border border-slate-200 rounded-lg p-6">
    <div class="text-3xl font-bold text-indigo-900"><?= (int)$courseCount ?></div>
    <div class="text-slate-600 mt-1">Available Courses</div>
  </div>
</section>

<?php require __DIR__ . '/../includes/public-footer.php'; ?>