<?php

declare(strict_types=1);

/*
 * Interactive installer / reconfiguration for SSH users (the same as the web
 * installer at /setup.php). Re-run it any time: pressing Enter keeps the
 * current value, an empty secret keeps the current secret.
 */

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use VamsysMcp\Exception\VamsysMcpException;
use VamsysMcp\Install\ConfigFile;
use VamsysMcp\Install\Console;
use VamsysMcp\Install\FtpCredentials;
use VamsysMcp\Install\InstallService;
use VamsysMcp\Paths;

require dirname(__DIR__) . '/vendor/autoload.php';

$paths = new Paths();
$io = new Console();
$configFile = new ConfigFile($paths->configFile());

if ($configFile->exists() && !$configFile->isManaged()) {
    $io->line("{$configFile->path} was written by hand; edit it directly or delete it to use the installer.");
    exit(1);
}
$existing = $configFile->load();
$fresh = $existing === [];
$io->line($fresh ? 'vAMSYS MCP: new installation' : "vAMSYS MCP: reconfiguring {$configFile->path}");
$io->line('Secrets you type here are saved to config.php (outside the web root), never to the database.');
$io->line();

$dsn = InstallService::parseDsn((string) ($existing['database']['dsn'] ?? ''));
$input = ['db' => [
    'host' => $io->ask('Database host', $dsn['host'] ?? 'localhost'),
    'port' => $io->ask('Database port', $dsn['port'] ?? '3306'),
    'name' => $io->ask('Database name', $dsn['dbname'] ?? 'vamsys_mcp'),
    'user' => $io->ask('Database user (SELECT/INSERT/UPDATE/DELETE)', (string) ($existing['database']['username'] ?? 'vamsys_mcp')),
    'password' => $io->secret('Database password' . ($fresh ? '' : ' (Enter = keep)')),
]];

$airlines = [];
foreach ((array) ($existing['airlines'] ?? []) as $key => $a) {
    if ($io->confirm("Remove airline '{$key}'?")) {
        continue;
    }
    $airlines[] = $io->confirm("Edit airline '{$key}'?") ? askAirline($io, $key, (array) $a) : ['key' => $key, 'client_secret' => ''] + (array) $a;
}
while ($airlines === [] || $io->confirm('Add another airline?')) {
    $airlines[] = askAirline($io, null, []);
}
$input['airlines'] = $airlines;

$input['allowed_hosts'] = $io->ask('Host name(s) of the MCP endpoint, comma-separated', implode(', ', (array) ($existing['http']['allowed_hosts'] ?? [])));
$input['audit_log'] = $io->confirm('Enable the audit log of vAMSYS calls?', (bool) ($existing['audit_log'] ?? false));
$input['admin_password'] = $io->secret('Web admin password' . ($fresh ? ' (at least ' . InstallService::MIN_ADMIN_PASSWORD_LENGTH . ' characters)' : ' (Enter = keep)'));
$input['admin_password_confirm'] = $input['admin_password'] !== '' ? $io->secret('Repeat the admin password') : '';

$installer = new InstallService($paths, new Psr18Client(HttpClient::create(['max_redirects' => 0, 'timeout' => 20])));
[$raw, $errors] = $installer->buildConfig($input, $existing);
if ($errors !== []) {
    $io->line();
    foreach ($errors as $error) {
        $io->line("✗ {$error}");
    }
    exit(1);
}

$ddl = null;
$ddlUser = $io->ask('DB admin user for creating tables (Enter = use the user above)');
if ($ddlUser !== '') {
    $ddl = ['user' => $ddlUser, 'password' => $io->secret('DB admin password (not stored)')];
}

$ftp = null;
if ($io->confirm('Save FTP credentials (encrypted) for web updates? Only needed if PHP cannot write its own files')) {
    try {
        $ftp = FtpCredentials::fromInput([
            'host' => $io->ask('FTP host'),
            'port' => $io->ask('FTP port', '21'),
            'username' => $io->ask('FTP user'),
            'password' => $io->secret('FTP password'),
            'path' => $io->ask('FTP path of this installation (folder with src/ and vendor/)', '/'),
            'tls' => $io->confirm('Use TLS (FTPS)?', true),
        ]);
    } catch (VamsysMcpException $e) {
        $io->line("✗ {$e->getMessage()}");
        exit(1);
    }
}
$verify = $io->confirm('Verify the vAMSYS credentials now (one token request per airline)?', true);

try {
    $result = $installer->install($raw, $ddl, $verify, $ftp);
} catch (VamsysMcpException $e) {
    $io->line("✗ {$e->getMessage()}");
    exit(1);
}

$io->line();
$io->line('✓ Saved ' . $configFile->path . ($result['migrated'] !== [] ? '; database migrations applied: ' . implode(', ', $result['migrated']) : ''));
if ($result['http_token'] !== null) {
    $io->line('MCP access token (shown only once; clients send "Authorization: Bearer <token>"):');
    $io->line('  ' . $result['http_token']);
}

/**
 * @param array<string, mixed> $current
 *
 * @return array<string, mixed>
 */
function askAirline(Console $io, ?string $key, array $current): array
{
    $key ??= $io->ask('Airline key (short, e.g. swv)');

    return [
        'key' => $key,
        'name' => $io->ask('  Name', (string) ($current['name'] ?? $key)),
        'client_id' => $io->ask('  OAuth client ID', (string) ($current['client_id'] ?? '')),
        'client_secret' => $io->secret('  OAuth client secret' . ($current !== [] ? ' (Enter = keep)' : '')),
        'weight_unit' => $io->ask('  Weight unit for /routes (kg or lbs)', (string) ($current['weight_unit'] ?? 'kg')),
        'rate_cap' => $io->ask('  Rate cap, requests/min (vAMSYS limit 100 is shared with your other clients)', (string) ($current['rate_cap'] ?? 60)),
        'pilots_api' => $io->confirm('  Enable the Pilots API (personal data)?', (bool) ($current['pilots_api'] ?? false)),
    ];
}
