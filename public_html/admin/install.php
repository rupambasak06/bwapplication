<?php
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';

/* ---------- Ensure storage skeleton ---------- */
foreach ([
    BW_STORAGE_DIR . '/logs',
    BW_STORAGE_DIR . '/tmp',
    BW_STORAGE_DIR . '/uploads',
    BW_STORAGE_DIR . '/uploads/institutions',
    BW_STORAGE_DIR . '/uploads/academic-sections',
    BW_STORAGE_DIR . '/uploads/documents',
] as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
}

/* ---------- Step 1: APP_KEY ---------- */
$appKeyStatus = 'ok';       // 'ok' | 'generated' | 'manual'
$generatedKey = '';

$appKey = (string)app_config('app_key', '');
if ($appKey === '') {
    $newKey       = bin2hex(random_bytes(32));
    $generatedKey = $newKey;
    $configPath   = BW_CONFIG_DIR . '/app.php';
    $contents     = @file_get_contents($configPath);

    if ($contents !== false && is_writable($configPath)) {
        $updated = preg_replace(
            "/'app_key'\s*=>\s*'[^']*'/",
            "'app_key' => '" . $newKey . "'",
            $contents,
            1
        );
        if (is_string($updated)
            && $updated !== $contents
            && @file_put_contents($configPath, $updated) !== false) {
            $appKeyStatus = 'generated';
            $GLOBALS['BW_APP']['app_key'] = $newKey;
        } else {
            $appKeyStatus = 'manual';
        }
    } else {
        $appKeyStatus = 'manual';
    }
}

/* ---------- Step 2: DB ---------- */
$dbOk    = false;
$dbError = '';
try {
    db()->query('SELECT 1');
    $dbOk = true;
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

/* ---------- Step 3: admins table state ---------- */
$adminCount = null;
if ($dbOk) {
    try {
        $adminCount = (int)db()->query('SELECT COUNT(*) FROM admins')->fetchColumn();
    } catch (Throwable $e) {
        $adminCount = null;
        $dbError    = 'Could not read the admins table. ' . $e->getMessage();
    }
}

$installerLocked = $adminCount !== null && $adminCount > 0;
$errors          = [];
$success         = false;
$createdUsername = '';

if (!$installerLocked && $dbOk && $appKeyStatus !== 'manual' && is_post()) {
    csrf_require();

    $username = post('username', '');
    $fullName = post('full_name', '');
    $email    = post('email', '');
    $password = (string)($_POST['password'] ?? '');
    $confirm  = (string)($_POST['password_confirm'] ?? '');

    $v = new Validator(compact('username', 'fullName', 'email', 'password'));
    $v->required('username', 'Username')->min('username', 3, 'Username')->max('username', 60, 'Username');
    $v->required('fullName', 'Full name')->max('fullName', 120, 'Full name');
    $v->required('email', 'Email address')->email('email', 'Email address');

    if ($username !== '' && !preg_match('/^[A-Za-z0-9._-]+$/', $username)) {
        $errors['username'] = 'Username may contain only letters, digits, dot, dash, and underscore.';
    }
    if (strlen($password) < 8) {
        $errors['password'] = 'Password must be at least 8 characters.';
    }
    if ($password !== $confirm) {
        $errors['password_confirm'] = 'Passwords do not match.';
    }
    $errors = array_merge($v->errors(), $errors);

    if (empty($errors)) {
        try {
            $stmt = db()->prepare(
                'INSERT INTO admins (username, email, password_hash, full_name, role, is_active, created_at)
                 VALUES (?, ?, ?, ?, "super_admin", 1, NOW())'
            );
            $stmt->execute([
                $username,
                $email,
                password_hash($password, PASSWORD_DEFAULT),
                $fullName,
            ]);
            set_setting('app_installed_at', now_utc());
            log_activity('install.admin_created', 'admin', (int)db()->lastInsertId());
            $success         = true;
            $createdUsername = $username;
        } catch (Throwable $e) {
            $errors['general'] = 'Could not create the administrator. ' . $e->getMessage();
        }
    } else {
        old_set(['username' => $username, 'full_name' => $fullName, 'email' => $email]);
    }
}

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Install — Brainwavers</title>
<link rel="stylesheet" href="<?= e(absolute_url('/assets/css/tailwind.css')) ?>">
</head>
<body class="bg-slate-100 text-slate-900 antialiased">
<div class="min-h-screen py-10 px-4">
  <div class="max-w-2xl mx-auto">

    <div class="text-center mb-6">
      <span class="inline-flex h-12 w-12 items-center justify-center rounded bg-indigo-900 text-white font-bold">BW</span>
      <h1 class="mt-3 text-2xl font-semibold">Install Brainwavers</h1>
      <p class="mt-1 text-sm text-slate-500">Create the first administrator account.</p>
    </div>

    <?php if ($installerLocked): ?>
      <div class="bg-white border border-slate-200 rounded-lg shadow-sm p-6">
        <h2 class="text-lg font-semibold text-green-800">Installation already completed</h2>
        <p class="mt-2 text-slate-600 text-sm">
          An administrator already exists. This installer is now locked.
        </p>
        <p class="mt-4 text-slate-700 text-sm">
          <strong>Next step:</strong> delete or rename <code>public/admin/install.php</code>, then
          <a class="text-indigo-900 underline" href="<?= e(absolute_url('/admin/login.php')) ?>">sign in to the admin panel</a>.
        </p>
      </div>

    <?php elseif (!$dbOk): ?>
      <div class="bg-white border border-red-200 rounded-lg shadow-sm p-6">
        <h2 class="text-lg font-semibold text-red-700">Database connection failed</h2>
        <p class="mt-2 text-sm text-slate-700">
          Update <code>config/database.php</code> with your MySQL credentials, then reload this page.
        </p>
        <?php if ($dbError !== ''): ?>
          <pre class="mt-3 bg-red-50 border border-red-200 text-red-800 p-3 rounded text-xs overflow-x-auto"><?= e($dbError) ?></pre>
        <?php endif; ?>
      </div>

    <?php elseif ($appKeyStatus === 'manual'): ?>
      <div class="bg-white border border-amber-200 rounded-lg shadow-sm p-6">
        <h2 class="text-lg font-semibold text-amber-900">APP_KEY not configured</h2>
        <p class="mt-2 text-sm text-slate-700">
          The installer generated an encryption key, but it could not write to
          <code>config/app.php</code>. Add this line manually:
        </p>
        <pre class="mt-3 bg-slate-50 border border-slate-200 p-3 rounded text-xs overflow-x-auto">'app_key' => '<?= e($generatedKey) ?>',</pre>
        <p class="mt-3 text-sm text-slate-600">Then reload this page to continue.</p>
      </div>

    <?php elseif ($success): ?>
      <div class="bg-white border border-green-200 rounded-lg shadow-sm p-6">
        <h2 class="text-lg font-semibold text-green-800">Installation complete</h2>
        <p class="mt-2 text-sm text-slate-700">
          Administrator <strong><?= e($createdUsername) ?></strong> has been created.
        </p>
        <p class="mt-4 text-sm text-red-700 font-medium">
          For security, delete or rename <code>public/admin/install.php</code> before using the system.
        </p>
        <a href="<?= e(absolute_url('/admin/login.php')) ?>"
           class="mt-6 inline-block rounded bg-indigo-900 text-white px-5 py-2.5 font-medium hover:bg-indigo-800">
          Go to Admin Login
        </a>
      </div>

    <?php else: ?>
      <form method="post" class="bg-white border border-slate-200 rounded-lg shadow-sm p-6 space-y-4" autocomplete="off">
        <?= csrf_field() ?>

        <?php if (!empty($errors['general'])): ?>
          <div class="text-sm rounded bg-red-50 border border-red-200 text-red-800 px-3 py-2"><?= e($errors['general']) ?></div>
        <?php endif; ?>

        <?php if ($appKeyStatus === 'generated'): ?>
          <div class="text-sm rounded bg-indigo-50 border border-indigo-200 text-indigo-900 px-3 py-2">
            APP_KEY generated and saved to <code>config/app.php</code>.
          </div>
        <?php endif; ?>

        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1" for="username">Username</label>
          <input id="username" name="username" type="text" required minlength="3" maxlength="60"
                 value="<?= e(post('username', '')) ?>"
                 class="w-full rounded border border-slate-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500">
          <?php if (!empty($errors['username'])): ?><p class="text-xs text-red-600 mt-1"><?= e($errors['username']) ?></p><?php endif; ?>
        </div>

        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1" for="full_name">Full Name</label>
          <input id="full_name" name="full_name" type="text" required maxlength="120"
                 value="<?= e(post('full_name', '')) ?>"
                 class="w-full rounded border border-slate-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500">
          <?php if (!empty($errors['fullName'])): ?><p class="text-xs text-red-600 mt-1"><?= e($errors['fullName']) ?></p><?php endif; ?>
        </div>

        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1" for="email">Email Address</label>
          <input id="email" name="email" type="email" required maxlength="180"
                 value="<?= e(post('email', '')) ?>"
                 class="w-full rounded border border-slate-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500">
          <?php if (!empty($errors['email'])): ?><p class="text-xs text-red-600 mt-1"><?= e($errors['email']) ?></p><?php endif; ?>
        </div>

        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1" for="password">Password</label>
          <input id="password" name="password" type="password" required minlength="8" autocomplete="new-password"
                 class="w-full rounded border border-slate-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500">
          <?php if (!empty($errors['password'])): ?><p class="text-xs text-red-600 mt-1"><?= e($errors['password']) ?></p><?php endif; ?>
        </div>

        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1" for="password_confirm">Confirm Password</label>
          <input id="password_confirm" name="password_confirm" type="password" required minlength="8" autocomplete="new-password"
                 class="w-full rounded border border-slate-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500">
          <?php if (!empty($errors['password_confirm'])): ?><p class="text-xs text-red-600 mt-1"><?= e($errors['password_confirm']) ?></p><?php endif; ?>
        </div>

        <button type="submit"
                class="w-full rounded bg-indigo-900 text-white py-2.5 font-medium hover:bg-indigo-800">
          Create Administrator
        </button>
      </form>
    <?php endif; ?>

    <p class="text-center text-xs text-slate-500 mt-6">
      Delete or rename <code>public/admin/install.php</code> once the administrator is created.
    </p>
  </div>
</div>
</body>
</html>