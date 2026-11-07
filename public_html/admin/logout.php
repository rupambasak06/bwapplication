<?php
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';

auth_logout();
flash_set('info', 'You have been signed out.');
redirect('/admin/login.php');