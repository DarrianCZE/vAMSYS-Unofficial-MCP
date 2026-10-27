<?php

declare(strict_types=1);

namespace VamsysMcp\Install;

use VamsysMcp\Exception\ConfigException;

/**
 * Authenticated symmetric encryption (libsodium secretbox) for secrets kept in
 * the database. The key lives in config.php ('app_key'), the ciphertext in the
 * DB, so neither a leaked DB dump nor a leaked config alone reveals them.
 */
final class SecretBox
{
    private function __construct(#[\SensitiveParameter] private readonly string $key)
    {
    }

    public static function generateKey(): string
    {
        self::assertSodium();

        return base64_encode(sodium_crypto_secretbox_keygen());
    }

    public static function fromBase64(#[\SensitiveParameter] string $key): self
    {
        self::assertSodium();
        $raw = base64_decode($key, true);
        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new ConfigException('app_key in config.php is missing or invalid.');
        }

        return new self($raw);
    }

    public function encrypt(#[\SensitiveParameter] string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce . sodium_crypto_secretbox($plaintext, $nonce, $this->key));
    }

    public function decrypt(string $ciphertext): string
    {
        $raw = base64_decode($ciphertext, true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new ConfigException('Encrypted setting is corrupt.');
        }
        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key,
        );
        if ($plain === false) {
            throw new ConfigException('Cannot decrypt a stored setting: app_key in config.php does not match the database.');
        }

        return $plain;
    }

    public function __debugInfo(): array
    {
        return ['key' => '***'];
    }

    private static function assertSodium(): void
    {
        if (!function_exists('sodium_crypto_secretbox')) {
            throw new ConfigException('The PHP sodium extension is required.');
        }
    }
}
