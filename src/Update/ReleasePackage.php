<?php

declare(strict_types=1);

namespace VamsysMcp\Update;

/**
 * A downloaded release zip: signature check, compatibility check and safe
 * extraction.
 *
 * Package layout (built by bin/build-release.php): the managed files at the
 * zip root, plus release.json {version, requires_php, update_from_min} and
 * manifest.json {files: {path: sha256}}.
 */
final class ReleasePackage
{
    public const int MAX_BYTES = 64 * 1024 * 1024;

    /**
     * @throws UpdateException unless $signature is a valid Ed25519 signature of $zipBytes
     */
    public static function verifySignature(string $zipBytes, string $signature, string $publicKey): void
    {
        if ($publicKey === '') {
            throw new UpdateException('This build has no release-signing public key (ReleaseKey::PUBLIC_KEY), so updates are disabled. Update manually.');
        }
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            throw new UpdateException('The PHP sodium extension is required to verify updates.');
        }
        $pk = base64_decode($publicKey, true);
        $sig = base64_decode(trim($signature), true);
        if ($pk === false || strlen($pk) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || $sig === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES
            || !sodium_crypto_sign_verify_detached($sig, $zipBytes, $pk)) {
            throw new UpdateException('The release signature is invalid. The download was not installed.');
        }
    }

    /**
     * Refuse downgrades, re-installs, too-old installations and unsupported PHP.
     *
     * @param array<string, mixed> $info release.json
     */
    public static function assertCompatible(array $info, string $currentVersion, string $expectedVersion): void
    {
        $version = (string) ($info['version'] ?? '');
        if ($version !== $expectedVersion) {
            throw new UpdateException("Package version '{$version}' does not match the release '{$expectedVersion}'.");
        }
        if (version_compare($version, $currentVersion, '<=')) {
            throw new UpdateException("Version {$version} is not newer than the installed {$currentVersion}.");
        }
        if (self::major($version) !== self::major($currentVersion) && self::major($currentVersion) >= 1) {
            throw new UpdateException("Version {$version} is a new major version with breaking changes; follow the manual upgrade steps under \"Breaking changes\" in CHANGELOG.md.");
        }
        $min = (string) ($info['update_from_min'] ?? '0.0.0');
        if (version_compare($currentVersion, $min, '<')) {
            throw new UpdateException("Version {$version} cannot be applied to {$currentVersion} automatically (needs at least {$min}); see \"Breaking changes\" in CHANGELOG.md.");
        }
        $php = (string) ($info['requires_php'] ?? '8.4.0');
        if (version_compare(PHP_VERSION, $php, '<')) {
            throw new UpdateException("Version {$version} needs PHP {$php}; this server runs PHP " . PHP_VERSION . '.');
        }
    }

    /**
     * Extract into an empty directory and verify every file against manifest.json.
     *
     * @return array<string, mixed> release.json
     */
    public static function extract(string $zipPath, string $dest): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new UpdateException('The PHP zip extension is required to install updates.');
        }
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new UpdateException('The release package is not a valid zip file.');
        }
        Fs::removeDir($dest);
        Fs::ensureDir($dest);
        try {
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $name = (string) $zip->getNameIndex($i);
                if (str_ends_with($name, '/')) {
                    continue;
                }
                if (!ManagedFiles::isManaged($name)) {
                    throw new UpdateException("The release package contains an unexpected file '{$name}'.");
                }
                $in = $zip->getStream($name);
                Fs::ensureDir(dirname("{$dest}/{$name}"));
                $out = fopen("{$dest}/{$name}", 'wb');
                if ($in === false || $out === false) {
                    throw new UpdateException("Cannot extract '{$name}'.");
                }
                stream_copy_to_stream($in, $out);
                fclose($in);
                fclose($out);
            }
        } finally {
            $zip->close();
        }

        $info = json_decode((string) @file_get_contents("{$dest}/release.json"), true);
        $manifest = json_decode((string) @file_get_contents("{$dest}/manifest.json"), true);
        if (!is_array($info) || !is_array($manifest) || !is_array($manifest['files'] ?? null)) {
            throw new UpdateException('The release package has no valid release.json/manifest.json.');
        }
        $actual = ManagedFiles::scan($dest);
        if ($actual != $manifest['files']) {
            throw new UpdateException('The release package does not match its manifest.');
        }

        return $info;
    }

    private static function major(string $version): int
    {
        return (int) explode('.', $version)[0];
    }
}
