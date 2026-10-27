<?php

declare(strict_types=1);

/*
 * Command-line updater (same as Updates in the web admin).
 *
 *   php bin/update.php --check   show the latest release
 *   php bin/update.php           download, verify and install it
 *
 * After staging, this script re-executes itself from the staged (new) version
 * with --continue, so the update is applied by the new code.
 */

use Nyholm\Psr7\Factory\Psr17Factory;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use VamsysMcp\App;
use VamsysMcp\Config;
use VamsysMcp\Exception\VamsysMcpException;
use VamsysMcp\Install\ConfigFile;
use VamsysMcp\Install\Console;
use VamsysMcp\Install\EncryptedSettings;
use VamsysMcp\Install\FtpCredentials;
use VamsysMcp\Install\SecretBox;
use VamsysMcp\Paths;
use VamsysMcp\Update\GitHubReleases;
use VamsysMcp\Update\StepContext;
use VamsysMcp\Update\Updater;
use VamsysMcp\Update\UpdateJob;

require dirname(__DIR__) . '/vendor/autoload.php';

$options = getopt('', ['check', 'continue', 'root:', 'yes']);
$paths = new Paths($options['root'] ?? dirname(__DIR__));
$io = new Console();
$raw = (new ConfigFile($paths->configFile()))->load();
if ($raw === []) {
    $io->line('Not installed yet; run php bin/install.php first.');
    exit(1);
}
$config = Config::fromArray($raw);

try {
    if (isset($options['continue'])) {
        exit(runJob($paths, $config, $raw, $io));
    }

    $state = UpdateJob::readState($paths);
    if ($state !== null && in_array($state['phase'] ?? '', UpdateJob::ACTIVE_PHASES, true)) {
        $io->line("Resuming the update to {$state['to_version']} ({$state['phase']}).");
        exit(continueFromStaging($paths, $io));
    }

    $psr17 = new Psr17Factory();
    $releases = new GitHubReleases(new Psr18Client(HttpClient::create(['max_redirects' => 5, 'timeout' => 120]), $psr17, $psr17), $psr17, (string) ($raw['update']['repository'] ?? 'DarrianCZE/vAMSYS-Unofficial-MCP'));
    $updater = new Updater($paths, App::VERSION, $releases);
    $updater->cleanup();
    $check = $updater->check();
    $io->line('Installed: ' . App::VERSION . '   Latest: ' . $check['release']['version']);
    if (!$check['update_available']) {
        $io->line('Up to date.');
        exit(0);
    }
    $io->line($check['release']['notes']);
    if (isset($options['check'])) {
        exit(0);
    }
    if (($blocker = $updater->blocker()) !== null) {
        $io->line("✗ {$blocker}");
        exit(1);
    }
    if (!isset($options['yes']) && !$io->confirm("Install {$check['release']['version']} now?")) {
        exit(0);
    }
    $io->line('Downloading and verifying…');
    $updater->prepare($check['release']);
    exit(continueFromStaging($paths, $io));
} catch (VamsysMcpException $e) {
    $io->line("✗ {$e->getMessage()}");
    exit(1);
}

function continueFromStaging(Paths $paths, Console $io): int
{
    $script = $paths->updateDir('staging/bin/update.php');
    if (!is_file($script)) {
        $io->line('✗ The staged update is incomplete; cancel it in the web admin or delete var/update/.');

        return 1;
    }
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' --continue --root=' . escapeshellarg($paths->root), $code);

    return $code;
}

/**
 * @param array<string, mixed> $raw
 */
function runJob(Paths $paths, Config $config, array $raw, Console $io): int
{
    $job = new UpdateJob($paths);
    $ddl = null;
    $ftp = null;
    while (true) {
        $ftp ??= storedFtp($config, $raw);
        $status = $job->step(new StepContext(static fn (): \PDO => $config->createPdo(), $config->dbDsn, $ftp, $ddl, 30.0));
        $io->line(sprintf('  %s %d/%d', $status['phase'], $status['progress']['done'], $status['progress']['total']));

        if ($status['needs'] === 'db_credentials') {
            $io->line('This update changes the database; enter a DB user that may CREATE/ALTER tables (not stored).');
            $ddl = ['user' => $io->ask('DB admin user'), 'password' => $io->secret('DB admin password')];
        } elseif ($status['needs'] === 'ftp_credentials') {
            $io->line('PHP cannot write the installation files; enter FTP credentials.');
            $ftp = FtpCredentials::fromInput(['host' => $io->ask('FTP host'), 'port' => $io->ask('FTP port', '21'), 'username' => $io->ask('FTP user'),
                'password' => $io->secret('FTP password'), 'path' => $io->ask('FTP path of this installation'), 'tls' => $io->confirm('Use TLS?', true)]);
        } elseif ($status['phase'] === 'done') {
            $io->line("✓ Updated to {$status['to_version']}.");

            return 0;
        } elseif ($status['phase'] === 'rolled_back') {
            $io->line("Update cancelled/rolled back; {$status['from_version']} is active.");

            return 1;
        } elseif ($status['phase'] === 'failed') {
            $io->line("✗ {$status['error']}");
            $choice = $io->ask($status['can_roll_back'] ? '[r]etry, roll [b]ack, or [q]uit' : '[r]etry, [c]ancel, or [q]uit', 'q');
            if ($choice === 'r') {
                $job->retry();
            } elseif ($choice === 'b' && $status['can_roll_back']) {
                $job->startRollback();
            } elseif ($choice === 'c' && !$status['can_roll_back']) {
                $job->cancel();
            } else {
                return 1;
            }
        }
    }
}

/**
 * @param array<string, mixed> $raw
 */
function storedFtp(Config $config, array $raw): ?FtpCredentials
{
    try {
        $data = (new EncryptedSettings($config->createPdo(), SecretBox::fromBase64((string) ($raw['app_key'] ?? ''))))->get(EncryptedSettings::FTP);
    } catch (\Throwable) {
        return null;
    }

    return $data !== null ? FtpCredentials::fromArray($data) : null;
}
