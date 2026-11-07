<?php
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';

/* If already logged in, go to the dashboard. */
if (auth_check() && auth_user()) {
    redirect('/admin/dashboard.php');
}

/* If the system has no admin yet, redirect to the installer. */
try {
    $count = (int)db()->query('SELECT COUNT(*) FROM admins')->fetchColumn();
    if ($count === 0) {
        redirect('/admin/install.php');
    }
} catch (Throwable $e) {
    redirect('/admin/install.php');
}

$error = '';
if (is_post()) {
    csrf_require();
    $username = post('username', '');
    $password = (string)($_POST['password'] ?? '');
    $result   = auth_login($username, $password);
    if ($result['ok']) {
        $intended = $_SESSION['_intended'] ?? '/admin/dashboard.php';
        unset($_SESSION['_intended']);
        redirect($intended);
    }
    $error = $result['error'];
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in — Brainwavers Admin</title>
<link rel="stylesheet" href="<?= e(absolute_url('/assets/css/tailwind.css')) ?>">
</head>
<body class="bg-slate-100 text-slate-900 antialiased">
<div class="min-h-screen flex items-center justify-center px-4 py-8">
  <div class="w-full max-w-sm">
    <div class="text-center mb-6">
      <span class="inline-flex h-12 w-12 items-center justify-center rounded bg-indigo-900 text-white font-bold">BW</span>
      <h1 class="mt-3 text-xl font-semibold">Admin Sign In</h1>
      <p class="mt-1 text-sm text-slate-500">Brainwavers Admission Management</p>
    </div>
    <form method="post" class="bg-white border border-slate-200 rounded-lg shadow-sm p-6 space-y-4">
      <?= csrf_field() ?>
      <?php if ($error): ?>
        <div class="text-sm rounded bg-red-50 border border-red-200 text-red-800 px-3 py-2"><?= e($error) ?></div>
      <?php endif; ?>
      <div>
        <label for="username" class="block text-sm font-medium text-slate-700 mb-1">Username or Email</label>
        <input id="username" name="username" type="text" required autofocus
               value="<?= e(post('username', '')) ?>"
               class="w-full rounded border border-slate-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500">
      </div>
      <div>
        <label for="password" class="block text-sm font-medium text-slate-700 mb-1">Password</label>
        <input id="password" name="password" type="password" required
               class="w-full rounded border border-slate-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500">
      </div>
      <button type="submit"
              class="w-full rounded bg-indigo-900 text-white py-2.5 font-medium hover:bg-indigo-800">
        Sign in
      </button>
    </form>
    <p class="text-center text-xs text-slate-500 mt-4">Protected area. Unauthorized access is prohibited.</p>
  </div>
</div>
</body>
</html>