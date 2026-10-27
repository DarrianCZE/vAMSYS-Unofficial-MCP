<?php

declare(strict_types=1);

namespace VamsysMcp\OAuth;

use VamsysMcp\Exception\ConfigException;
use VamsysMcp\Sql;

/**
 * People who may connect MCP clients (Claude etc.) through the OAuth sign-in
 * page. Managed in the web admin or with bin/mcp-user.php. Separate from the
 * web admin password on purpose: connecting Claude does not grant admin rights.
 */
final class McpUsers
{
    public const int MIN_PASSWORD_LENGTH = 12;

    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function create(string $username, #[\SensitiveParameter] string $password): int
    {
        $username = self::validUsername($username);
        self::assertPassword($password);
        $exists = $this->pdo->prepare('SELECT 1 FROM mcp_user WHERE username = :u');
        $exists->execute(['u' => $username]);
        if ($exists->fetchColumn() !== false) {
            throw new ConfigException("A user named '{$username}' already exists.");
        }
        $this->pdo->prepare('INSERT INTO mcp_user (username, password_hash, disabled, created_at) VALUES (:u, :p, 0, :c)')
            ->execute(['u' => $username, 'p' => password_hash($password, PASSWORD_DEFAULT), 'c' => Sql::now()]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return array{id: int, username: string}|null the user, if the credentials are valid and the user is enabled
     */
    public function verify(string $username, #[\SensitiveParameter] string $password): ?array
    {
        $select = $this->pdo->prepare('SELECT id, username, password_hash, disabled FROM mcp_user WHERE username = :u');
        $select->execute(['u' => strtolower(trim($username))]);
        $row = $select->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            // Spend the same time as for a real user, so response times don't reveal which names exist.
            static $dummy = null;
            password_verify($password, $dummy ??= password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT));

            return null;
        }
        if (!password_verify($password, (string) $row['password_hash']) || (int) $row['disabled'] === 1) {
            return null;
        }
        $update = ['id' => $row['id'], 'l' => Sql::now()];
        if (password_needs_rehash((string) $row['password_hash'], PASSWORD_DEFAULT)) {
            $this->pdo->prepare('UPDATE mcp_user SET password_hash = :p WHERE id = :id')->execute(['p' => password_hash($password, PASSWORD_DEFAULT), 'id' => $row['id']]);
        }
        $this->pdo->prepare('UPDATE mcp_user SET last_login_at = :l WHERE id = :id')->execute($update);

        return ['id' => (int) $row['id'], 'username' => (string) $row['username']];
    }

    public function isActive(int $id): bool
    {
        $select = $this->pdo->prepare('SELECT disabled FROM mcp_user WHERE id = :id');
        $select->execute(['id' => $id]);
        $disabled = $select->fetchColumn();

        return $disabled !== false && (int) $disabled === 0;
    }

    /**
     * @return list<array{id: int, username: string, disabled: bool, created_at: string, last_login_at: string|null}>
     */
    public function all(): array
    {
        $rows = $this->pdo->query('SELECT id, username, disabled, created_at, last_login_at FROM mcp_user ORDER BY username')->fetchAll(\PDO::FETCH_ASSOC);

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'username' => (string) $r['username'], 'disabled' => (int) $r['disabled'] === 1,
            'created_at' => (string) $r['created_at'], 'last_login_at' => $r['last_login_at'] !== null ? (string) $r['last_login_at'] : null,
        ], $rows);
    }

    public function setDisabled(int $id, bool $disabled): void
    {
        $this->pdo->prepare('UPDATE mcp_user SET disabled = :d WHERE id = :id')->execute(['d' => $disabled ? 1 : 0, 'id' => $id]);
        if ($disabled) {
            $this->revokeTokens($id);
        }
    }

    public function setPassword(int $id, #[\SensitiveParameter] string $password): void
    {
        self::assertPassword($password);
        $this->pdo->prepare('UPDATE mcp_user SET password_hash = :p WHERE id = :id')->execute(['p' => password_hash($password, PASSWORD_DEFAULT), 'id' => $id]);
    }

    public function delete(int $id): void
    {
        $this->revokeTokens($id);
        $this->pdo->prepare('DELETE FROM mcp_user WHERE id = :id')->execute(['id' => $id]);
    }

    public function idByName(string $username): ?int
    {
        $select = $this->pdo->prepare('SELECT id FROM mcp_user WHERE username = :u');
        $select->execute(['u' => strtolower(trim($username))]);
        $id = $select->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    private function revokeTokens(int $userId): void
    {
        $this->pdo->prepare('UPDATE mcp_oauth_token SET revoked = 1 WHERE user_id = :id')->execute(['id' => $userId]);
    }

    private static function validUsername(string $username): string
    {
        $username = strtolower(trim($username));
        if (preg_match('/^[a-z0-9._-]{2,64}$/', $username) !== 1) {
            throw new ConfigException('User names may contain 2-64 characters: a-z, 0-9, ".", "_" and "-".');
        }

        return $username;
    }

    private static function assertPassword(#[\SensitiveParameter] string $password): void
    {
        if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
            throw new ConfigException('Passwords must have at least ' . self::MIN_PASSWORD_LENGTH . ' characters.');
        }
    }
}
