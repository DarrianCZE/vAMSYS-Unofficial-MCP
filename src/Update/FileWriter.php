<?php

declare(strict_types=1);

namespace VamsysMcp\Update;

/**
 * Writes installation files during an update, either directly (PHP owns the
 * files) or over FTP. Paths are relative to the installation directory.
 */
interface FileWriter
{
    public function put(string $relativePath, string $sourceFile): void;

    public function delete(string $relativePath): void;

    public function close(): void;
}
