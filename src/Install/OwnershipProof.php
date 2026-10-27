<?php

declare(strict_types=1);

namespace VamsysMcp\Install;

/**
 * Proves that the person in the browser controls the server's files, which
 * unlocks the web installer and the admin-password reset.
 *
 * The page shows a random code; the operator creates an empty file named
 * vamsys-setup-<code>.txt in the installation folder (next to public/ and
 * src/) with FTP or the hosting file manager. Unlike a key file written by
 * PHP, this works on hosts where PHP runs as a different user than FTP and
 * the FTP account cannot see files PHP created. The installation folder is
 * outside the web root, so the file is never served.
 */
final class OwnershipProof
{
    public function __construct(private readonly string $root)
    {
    }

    public static function newCode(): string
    {
        return bin2hex(random_bytes(6));
    }

    public static function isValidCode(mixed $code): bool
    {
        return is_string($code) && preg_match('/^[0-9a-f]{12}$/', $code) === 1;
    }

    public function fileName(string $code): string
    {
        return "vamsys-setup-{$code}.txt";
    }

    public function folder(): string
    {
        return $this->root;
    }

    public function verify(string $code): bool
    {
        clearstatcache();

        return self::isValidCode($code) && is_file($this->root . '/' . $this->fileName($code));
    }

    /** Remove the proof file; false if PHP is not allowed to (then the operator deletes it). */
    public function remove(string $code): bool
    {
        $file = $this->root . '/' . $this->fileName($code);

        return !is_file($file) || @unlink($file);
    }
}
