<?php

declare(strict_types=1);

namespace VamsysMcp\Update;

/**
 * Ed25519 public key (base64) that release packages must be signed with.
 * Generate the key pair with `php bin/release-keygen.php`; the secret half
 * goes into the GitHub Actions secret RELEASE_SIGNING_KEY, never into the repo.
 *
 * While this is empty, the updater refuses to install anything.
 */
final class ReleaseKey
{
    public const string PUBLIC_KEY = '+6DQYUoz86ONIeT1znVuVoge17l7mMXBqo9dKRAxQ14=';
}
