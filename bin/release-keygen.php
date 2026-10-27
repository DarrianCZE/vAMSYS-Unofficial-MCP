<?php

declare(strict_types=1);

/*
 * One-time setup for maintainers: creates the Ed25519 key pair that signs
 * release packages. Nothing is written to disk.
 *
 *  - Public key  → paste into src/Update/ReleaseKey.php (PUBLIC_KEY) and commit.
 *  - Secret key  → GitHub repo → Settings → Secrets and variables → Actions →
 *                  new secret RELEASE_SIGNING_KEY. Keep an offline backup;
 *                  never commit it. If it leaks, generate a new pair and ship
 *                  the new public key in a release signed with the old key.
 */

$pair = sodium_crypto_sign_keypair();

echo "Public key (src/Update/ReleaseKey.php):\n  " . base64_encode(sodium_crypto_sign_publickey($pair)) . "\n\n";
echo "Secret key (GitHub Actions secret RELEASE_SIGNING_KEY, shown once):\n  " . base64_encode(sodium_crypto_sign_secretkey($pair)) . "\n";
