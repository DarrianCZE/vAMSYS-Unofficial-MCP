<?php

declare(strict_types=1);

namespace VamsysMcp\Admin;

use VamsysMcp\Paths;

/**
 * Admin session: hardened cookie, CSRF token, idle timeout, flash messages.
 * Session files live in var/admin-sessions, outside the web root.
 */
final class Session
{
    private const int IDLE_TIMEOUT = 1800;

    public static function start(Paths $paths, bool $https): void
    {
        $dir = $paths->ensureVarDir('admin-sessions');
        session_save_path($dir);
        session_name('vamsys_admin');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\') . '/',
            'secure' => $https,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.gc_maxlifetime', (string) self::IDLE_TIMEOUT);
        session_start();

        if (isset($_SESSION['last_seen']) && time() - (int) $_SESSION['last_seen'] > self::IDLE_TIMEOUT) {
            $_SESSION = [];
            session_regenerate_id(true);
        }
        $_SESSION['last_seen'] = time();
        $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }

    public static function csrf(): string
    {
        return (string) $_SESSION['csrf'];
    }

    public static function checkCsrf(mixed $token): bool
    {
        return is_string($token) && hash_equals(self::csrf(), $token);
    }

    public static function isAdmin(): bool
    {
        return ($_SESSION['admin'] ?? false) === true;
    }

    public static function login(): void
    {
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        unset($_SESSION['unlocked']);
    }

    public static function logout(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    public static function isUnlocked(): bool
    {
        return ($_SESSION['unlocked'] ?? false) === true;
    }

    public static function unlock(): void
    {
        session_regenerate_id(true);
        $_SESSION['unlocked'] = true;
    }

    public static function flash(string $type, string $message): void
    {
        $_SESSION['flash'][] = [$type, $message];
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function takeFlashes(): array
    {
        $flashes = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);

        return $flashes;
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION['data'][$key] = $value;
    }

    public static function get(string $key): mixed
    {
        return $_SESSION['data'][$key] ?? null;
    }
}
