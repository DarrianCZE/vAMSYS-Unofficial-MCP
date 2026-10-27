<?php

declare(strict_types=1);

/*
 * Web installer and admin UI: https://your-host/setup.php
 *
 * While an update is in progress, this file loads the NEW version's code from
 * var/update/staging, so the update is driven by code that is never half
 * replaced. Keep this file small and its contract with UpdateJob stable
 * (state.json: contract 1, phase).
 *
 * If your web root is elsewhere, put a one-line stub there instead of a copy:
 *   <?php require '/home/you/vamsys-mcp/public/setup.php';
 */

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';

$stateFile = $root . '/var/update/state.json';
$staged = $root . '/var/update/staging/vendor/autoload.php';
if (is_file($stateFile) && is_file($staged)) {
    $state = json_decode((string) file_get_contents($stateFile), true);
    $activePhases = ['extracted', 'planned', 'migrated', 'backed_up', 'applying', 'finalizing', 'failed', 'rolling_back'];
    if (is_array($state) && ($state['contract'] ?? null) === 1 && in_array($state['phase'] ?? '', $activePhases, true)) {
        $autoload = $staged;
    }
}

if (!is_file($autoload)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "vendor/ is missing. Upload the complete release package (it includes vendor/) or run: composer install --no-dev\n";
    exit;
}

require $autoload;

(new VamsysMcp\Admin\AdminApp(new VamsysMcp\Paths($root)))->run();
