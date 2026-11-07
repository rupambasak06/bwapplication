<?php
declare(strict_types=1);
if (!defined('BRAINWAVERS_BOOTSTRAPPED')) { exit('Direct access is not allowed.'); }

$institute_name    = app_setting('institute_name', 'Brainwavers');
$institute_logo    = app_setting('institute_logo', '');
$institute_email   = app_setting('institute_email', '');
$institute_mobile  = app_setting('institute_mobile', '');
$institute_address = app_setting('institute_address', '');
$footer_text       = app_setting('footer_text', '');
$theme_color       = app_setting('theme_color', '#1e3a8a');
$site_available    = (int)app_setting('website_available', '1');

$page_title       = $page_title       ?? $institute_name;
$page_description = $page_description ?? ('Official admission portal for ' . $institute_name . '.');

$publicNavAll = [
    'Home'              => '/index.php',
    'About'             => '/about.php',
    'Academic Sections' => '/academic-sections.php',
    'Institutions'      => '/institutions.php',
    'Courses'           => '/courses.php',
    'Check Status'      => '/check-status.php',
];
$publicNav = [];
foreach ($publicNavAll as $label => $href) {
    if (is_file(BW_PUBLIC_DIR . $href)) {
        $publicNav[$label] = $href;
    }
}
$hasApply = is_file(BW_PUBLIC_DIR . '/apply.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($page_title) ?> — <?= e($institute_name) ?></title>
<meta name="description" content="<?= e($page_description) ?>">
<meta name="theme-color" content="<?= e($theme_color) ?>">
<link rel="stylesheet" href="<?= e(absolute_url('/assets/css/tailwind.css')) ?>">
<style>:root{--bw-primary: <?= e($theme_color) ?>;}</style>
</head>
<body class="bg-slate-50 text-slate-900 antialiased">
<?php if (!$site_available): ?>
<div class="bg-amber-100 text-amber-900 text-sm px-4 py-2 text-center">
    New applications are temporarily unavailable. Please check back shortly.
</div>
<?php endif; ?>

<header class="bg-white border-b border-slate-200 sticky top-0 z-40">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
    <div class="flex items-center justify-between h-16">
      <a href="<?= e(absolute_url('/')) ?>" class="flex items-center gap-3 min-w-0">
        <?php if ($institute_logo): ?>
          <img src="<?= e(absolute_url('/download-logo.php?type=institute')) ?>" alt="<?= e($institute_name) ?>" class="h-10 w-10 object-contain rounded">
        <?php else: ?>
          <span class="inline-flex h-10 w-10 items-center justify-center rounded bg-indigo-900 text-white font-bold">BW</span>
        <?php endif; ?>
        <span class="text-lg font-semibold truncate"><?= e($institute_name) ?></span>
      </a>
      <nav class="hidden md:flex items-center gap-6 text-sm text-slate-600">
        <?php foreach ($publicNav as $label => $href): ?>
          <a href="<?= e(absolute_url($href)) ?>" class="hover:text-indigo-900"><?= e($label) ?></a>
        <?php endforeach; ?>
        <?php if ($hasApply): ?>
          <a href="<?= e(absolute_url('/apply.php')) ?>" class="inline-flex items-center gap-2 rounded-md bg-indigo-900 text-white px-4 py-2 hover:bg-indigo-800">Apply Now</a>
        <?php endif; ?>
      </nav>
      <button type="button" class="md:hidden p-2 rounded text-slate-600" data-mobile-menu-toggle aria-label="Open menu">☰</button>
    </div>
    <nav class="md:hidden hidden pb-4 space-y-2" data-mobile-menu>
      <?php foreach ($publicNav as $label => $href): ?>
        <a href="<?= e(absolute_url($href)) ?>" class="block py-2 text-slate-700"><?= e($label) ?></a>
      <?php endforeach; ?>
      <?php if ($hasApply): ?>
        <a href="<?= e(absolute_url('/apply.php')) ?>" class="block py-2 rounded-md bg-indigo-900 text-white text-center">Apply Now</a>
      <?php endif; ?>
    </nav>
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

<main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 sm:py-8">