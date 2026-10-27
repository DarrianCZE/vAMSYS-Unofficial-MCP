<?php

declare(strict_types=1);

/*
 * Signs a release zip with the Ed25519 secret key from the RELEASE_SIGNING_KEY
 * environment variable (a GitHub Actions secret) and writes <zip>.sig.
 *
 *   RELEASE_SIGNING_KEY=... php bin/sign-release.php dist/vamsys-mcp-1.2.3.zip
 */

use VamsysMcp\Update\ReleaseKey;

require dirname(__DIR__) . '/vendor/autoload.php';

$zipPath = $argv[1] ?? '';
$secret = base64_decode((string) getenv('RELEASE_SIGNING_KEY'), true);
if (!is_file($zipPath) || $secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
    fwrite(STDERR, "Usage: RELEASE_SIGNING_KEY=<base64 secret key> php bin/sign-release.php <zip>\n");
    exit(1);
}

$publicKey = base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret));
if ($publicKey !== ReleaseKey::PUBLIC_KEY) {
    fwrite(STDERR, "RELEASE_SIGNING_KEY does not belong to ReleaseKey::PUBLIC_KEY; installations would reject this release.\n");
    exit(1);
}

$signature = base64_encode(sodium_crypto_sign_detached((string) file_get_contents($zipPath), $secret));
file_put_contents($zipPath . '.sig', $signature . "\n");
sodium_memzero($secret);

echo "Signed {$zipPath}\n";
