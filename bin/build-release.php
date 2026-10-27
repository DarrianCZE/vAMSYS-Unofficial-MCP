<?php

declare(strict_types=1);

/*
 * Builds dist/vamsys-mcp-<version>.zip for a GitHub release (run by
 * .github/workflows/release.yml). Run after `composer install --no-dev
 * --optimize-autoloader`, because shared hosts cannot run Composer, so the
 * package ships vendor/.
 */

use VamsysMcp\App;
use VamsysMcp\Update\ManagedFiles;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$version = App::VERSION;

if (is_dir("{$root}/vendor/phpunit")) {
    fwrite(STDERR, "vendor/ contains dev packages; run composer install --no-dev --optimize-autoloader first.\n");
    exit(1);
}

file_put_contents("{$root}/release.json", json_encode([
    'version' => $version,
    'requires_php' => App::REQUIRES_PHP,
    'update_from_min' => App::UPDATE_FROM_MIN,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

$files = ManagedFiles::scan($root);
file_put_contents("{$root}/manifest.json", json_encode(['version' => $version, 'files' => $files], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

@mkdir("{$root}/dist", 0755, true);
$zipPath = "{$root}/dist/vamsys-mcp-{$version}.zip";
@unlink($zipPath);
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
    fwrite(STDERR, "Cannot create {$zipPath}\n");
    exit(1);
}
foreach ([...array_keys($files), 'manifest.json'] as $path) {
    $zip->addFile("{$root}/{$path}", $path);
}
$zip->close();

echo "Built {$zipPath} (" . count($files) . " files)\n";
