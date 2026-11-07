<?php
declare(strict_types=1);
if (!defined('BRAINWAVERS_BOOTSTRAPPED')) { exit('Direct access is not allowed.'); }

/* Boot-time guard: if the system has no admin yet, go to installer. */
try {
    $bwAdminCount = (int)db()->query('SELECT COUNT(*) FROM admins')->fetchColumn();
    if ($bwAdminCount === 0) {
        redirect('/admin/install.php');
    }
} catch (Throwable $e) {
    redirect('/admin/install.php');
}

auth_require();

$currentAdmin     = auth_user();
$currentPage      = $currentPage      ?? basename($_SERVER['SCRIPT_NAME'] ?? '');
$institute_name   = app_setting('institute_name', 'Brainwavers');
$institute_logo   = app_setting('institute_logo', '');
$page_title       = $page_title       ?? 'Admin';

$navItems = [
    ['label' => 'Dashboard',         'href' => '/admin/dashboard.php',         'file' => 'dashboard.php'],
    ['label' => 'Academic Sections', 'href' => '/admin/academic-sections.php', 'file' => 'academic-sections.php'],
    ['label' => 'Institutions',      'href' => '/admin/institutions.php',      'file' => 'institutions.php'],
    ['label' => 'Courses',           'href' => '/admin/courses.php',           'file' => 'courses.php'],
    ['label' => 'Subjects',          'href' => '/admin/subjects.php',          'file' => 'subjects.php'],
    ['label' => 'Forms',             'href' => '/admin/forms.php',             'file' => 'forms.php'],
    ['label' => 'Applications',      'href' => '/admin/applications.php',      'file' => 'applications.php'],
    ['label' => 'Students',          'href' => '/admin/students.php',          'file' => 'students.php'],
    ['label' => 'Settings',          'href' => '/admin/settings.php',          'file' => 'settings.php'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($page_title) ?> — Admin — <?= e($institute_name) ?></title>
<link rel="stylesheet" href="<?= e(absolute_url('/assets/css/tailwind.css')) ?>">
<link rel="stylesheet" href="<?= e(absolute_url('/assets/css/print.css')) ?>" media="print">
</head>
<body class="bg-slate-50 text-slate-900 antialiased">
<div class="min-h-screen md:flex">

  <!-- Desktop sidebar -->
  <aside class="hidden md:flex md:flex-col md:w-64 md:shrink-0 bg-white border-r border-slate-200">
    <div class="h-16 flex items-center px-4 border-b border-slate-200 gap-3">
      <?php if ($institute_logo): ?>
        <img src="<?= e(absolute_url('/download-logo.php?type=institute')) ?>" class="h-8 w-8 object-contain rounded" alt="">
      <?php else: ?>
        <span class="inline-flex h-8 w-8 items-center justify-center rounded bg-indigo-900 text-white font-bold text-sm">BW</span>
      <?php endif; ?>
      <span class="font-semibold truncate"><?= e($institute_name) ?></span>
    </div>
    <nav class="flex-1 px-2 py-4 space-y-1 overflow-y-auto">
      <?php foreach ($navItems as $item): ?>
        <?php $active = $currentPage === $item['file']; ?>
        <a href="<?= e(absolute_url($item['href'])) ?>"
           class="block rounded-md px-3 py-2 text-sm <?= $active ? 'bg-indigo-900 text-white' : 'text-slate-700 hover:bg-slate-100' ?>">
          <?= e($item['label']) ?>
        </a>
      <?php endforeach; ?>
    </nav>
    <div class="border-t border-slate-200 p-4 text-sm">
      <div class="text-slate-500 text-xs">Signed in as</div>
      <div class="font-medium truncate"><?= e($currentAdmin['full_name'] ?? $currentAdmin['username'] ?? '') ?></div>
      <a href="<?= e(absolute_url('/admin/logout.php')) ?>" class="mt-2 inline-block text-red-600 hover:text-red-700">Sign out</a>
    </div>
  </aside>

  <!-- Mobile drawer -->
  <div class="md:hidden fixed inset-0 z-40 hidden" data-mobile-drawer>
    <div class="absolute inset-0 bg-black/40" data-drawer-close></div>
    <aside class="relative w-64 max-w-[80vw] h-full bg-white border-r border-slate-200 flex flex-col">
      <div class="h-16 flex items-center justify-between px-4 border-b border-slate-200">
        <span class="font-semibold truncate"><?= e($institute_name) ?></span>
        <button type="button" data-drawer-close class="p-2 text-slate-500">✕</button>
      </div>
      <nav class="flex-1 px-2 py-4 space-y-1 overflow-y-auto">
        <?php foreach ($navItems as $item): ?>
          <?php $active = $currentPage === $item['file']; ?>
          <a href="<?= e(absolute_url($item['href'])) ?>"
             class="block rounded-md px-3 py-2 text-sm <?= $active ? 'bg-indigo-900 text-white' : 'text-slate-700 hover:bg-slate-100' ?>">
            <?= e($item['label']) ?>
          </a>
        <?php endforeach; ?>
      </nav>
      <div class="border-t border-slate-200 p-4 text-sm">
        <div class="text-slate-500 text-xs">Signed in as</div>
        <div class="font-medium truncate"><?= e($currentAdmin['full_name'] ?? $currentAdmin['username'] ?? '') ?></div>
        <a href="<?= e(absolute_url('/admin/logout.php')) ?>" class="mt-2 inline-block text-red-600">Sign out</a>
      </div>
    </aside>
  </div>

  <!-- Main column -->
  <div class="flex-1 flex flex-col min-w-0">
    <header class="h-16 bg-white border-b border-slate-200 flex items-center justify-between px-4 sm:px-6 lg:px-8 no-print">
      <div class="flex items-center gap-3 min-w-0">
        <button type="button" class="md:hidden p-2 rounded text-slate-600" data-drawer-open aria-label="Open menu">☰</button>
        <h1 class="text-lg font-semibold truncate"><?= e($page_title) ?></h1>
      </div>
    </header>

    <?php if ($flashes = flash_get_all()): ?>
      <div class="fixed top-20 right-4 z-50 space-y-2" data-flash-container>
        <?php foreach ($flashes as $f): ?>
          <?php
            $cls = $f['type'] === 'error'
                ? 'bg-red-50 border-red-200 text-red-800'
                : ($f['type'] === 'success' ? 'bg-green-50 border-green-200 text-green-800' : 'bg-indigo-50 border-indigo-200 text-indigo-900');
          ?>
          <div class="max-w-sm rounded-lg shadow-lg border px-4 py-3 text-sm <?= $cls ?>"><?= e($f['message']) ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <main class="p-4 sm:p-6 lg:p-8 flex-1 min-w-0">