<?php

declare(strict_types=1);

namespace VamsysMcp\Install;

/**
 * Small key/value store for secrets that must live in the DB (currently only
 * the FTP credentials used by the updater), always encrypted with SecretBox.
 */
final class EncryptedSettings
{
    public const string FTP = 'ftp';

    public function __construct(
        private readonly \PDO $pdo,
        private readonly SecretBox $box,
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $name): ?array
    {
        $select = $this->pdo->prepare('SELECT ciphertext FROM encrypted_setting WHERE name = :name');
        $select->execute(['name' => $name]);
        $ciphertext = $select->fetchColumn();
        if (!is_string($ciphertext)) {
            return null;
        }
        $value = json_decode($this->box->decrypt($ciphertext), true);

        return is_array($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $value
     */
    public function put(string $name, #[\SensitiveParameter] array $value): void
    {
        $sql = match ($this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)) {
            'mysql' => 'INSERT INTO encrypted_setting (name, ciphertext, updated_at) VALUES (:name, :c, :u)
                ON DUPLICATE KEY UPDATE ciphertext = VALUES(ciphertext), updated_at = VALUES(updated_at)',
            'sqlite' => 'INSERT INTO encrypted_setting (name, ciphertext, updated_at) VALUES (:name, :c, :u)
                ON CONFLICT (name) DO UPDATE SET ciphertext = excluded.ciphertext, updated_at = excluded.updated_at',
            default => throw new \LogicException('Unsupported database driver.'),
        };
        $this->pdo->prepare($sql)->execute([
            'name' => $name,
            'c' => $this->box->encrypt((string) json_encode($value)),
            'u' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function delete(string $name): void
    {
        $this->pdo->prepare('DELETE FROM encrypted_setting WHERE name = :name')->execute(['name' => $name]);
    }
}
