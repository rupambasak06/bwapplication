<?php
/**
 * Brainwavers — Application configuration.
 * Keep this file OUTSIDE the public web root.
 *
 * app_key   : 64-char random hex. Generated automatically by the installer
 *             if empty and this file is writable.
 * base_url  : optional. Leave empty to auto-detect.
 * environment : 'production' (default) or 'development'.
 */
declare(strict_types=1);

return [
    'environment'      => 'production',
    'app_key'          => '',
    'base_url'         => '',
    'session_name'     => 'bwsid',
    'session_lifetime' => 0,
    'timezone'         => 'UTC',
];