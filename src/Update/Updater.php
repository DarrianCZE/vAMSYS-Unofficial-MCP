<?php

declare(strict_types=1);

namespace VamsysMcp\Update;

use VamsysMcp\Paths;

/**
 * Entry point for updates: check GitHub, then download, verify and stage a
 * release. Everything after staging is done by UpdateJob running the staged
 * (new) code.
 */
final class Updater
{
    public function __construct(
        private readonly Paths $paths,
        private readonly string $currentVersion,
        private readonly GitHubReleases $releases,
        private readonly string $publicKey = ReleaseKey::PUBLIC_KEY,
    ) {
    }

    /**
     * @return array{release: array<string, string>, update_available: bool}
     */
    public function check(): array
    {
        $release = $this->releases->latest();

        return ['release' => $release, 'update_available' => version_compare($release['version'], $this->currentVersion, '>')];
    }

    /** Reason why this installation cannot be self-updated, or null. */
    public function blocker(): ?string
    {
        if (is_dir($this->paths->root . '/.git')) {
            return 'This installation is a git checkout; update it with git pull and composer install instead.';
        }
        if ($this->publicKey === '') {
            return 'This build has no release-signing public key, so automatic updates are disabled.';
        }
        if (!class_exists(\ZipArchive::class)) {
            return 'The PHP zip extension is missing; it is needed to unpack updates.';
        }
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            return 'The PHP sodium extension is missing; it is needed to verify updates.';
        }
        $active = UpdateJob::readState($this->paths);
        if ($active !== null && in_array($active['phase'] ?? '', UpdateJob::ACTIVE_PHASES, true)) {
            return 'Another update is in progress.';
        }

        return null;
    }

    /**
     * Download, verify and unpack the given release into var/update/staging and
     * hand over to UpdateJob.
     *
     * @param array{version: string, zip_url: string, sig_url: string} $release
     */
    public function prepare(array $release): void
    {
        if (($reason = $this->blocker()) !== null) {
            throw new UpdateException($reason);
        }
        $this->cleanup();

        $zip = $this->releases->download($release['zip_url']);
        ReleasePackage::verifySignature($zip, $this->releases->download($release['sig_url']), $this->publicKey);

        $downloadDir = $this->paths->updateDir('download');
        Fs::ensureDir($downloadDir);
        $zipPath = "{$downloadDir}/vamsys-mcp-{$release['version']}.zip";
        file_put_contents($zipPath, $zip);

        $staging = $this->paths->updateDir('staging');
        $info = ReleasePackage::extract($zipPath, $staging);
        ReleasePackage::assertCompatible($info, $this->currentVersion, $release['version']);

        UpdateJob::writeState($this->paths, [
            'contract' => UpdateJob::CONTRACT,
            'phase' => 'extracted',
            'from_version' => $this->currentVersion,
            'to_version' => $release['version'],
            'staging' => $staging,
            'started_at' => gmdate('c'),
        ]);
    }

    /**
     * Remove leftovers of a finished update: staging, downloads, state, and
     * all but the newest backup (kept for manual recovery).
     */
    public function cleanup(): void
    {
        $state = UpdateJob::readState($this->paths);
        if ($state !== null && in_array($state['phase'] ?? '', UpdateJob::ACTIVE_PHASES, true)) {
            return;
        }
        Fs::removeDir($this->paths->updateDir('staging'));
        Fs::removeDir($this->paths->updateDir('download'));
        @unlink($this->paths->updateDir('state.json'));

        $backups = glob($this->paths->updateDir('backup-*'), GLOB_ONLYDIR) ?: [];
        usort($backups, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
        foreach (array_slice($backups, 1) as $old) {
            Fs::removeDir($old);
        }
    }

    /**
     * Result of the last finished update, for showing once in the UI.
     *
     * @return array<string, mixed>|null
     */
    public function finishedState(): ?array
    {
        $state = UpdateJob::readState($this->paths);

        return $state !== null && in_array($state['phase'] ?? '', ['done', 'rolled_back'], true) ? $state : null;
    }
}
