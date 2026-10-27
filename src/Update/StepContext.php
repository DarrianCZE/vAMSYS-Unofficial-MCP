<?php

declare(strict_types=1);

namespace VamsysMcp\Update;

use VamsysMcp\Install\FtpCredentials;

/**
 * Inputs for one update step. Credentials come from the operator (or, for FTP,
 * the encrypted store) and live only for the duration of the request.
 */
final class StepContext
{
    /**
     * @param \Closure(): \PDO                       $appPdo  connection as the application user
     * @param array{user: string, password: string}|null $ddl DB login allowed to CREATE/ALTER
     */
    public function __construct(
        public readonly \Closure $appPdo,
        public readonly string $dbDsn,
        public readonly ?FtpCredentials $ftp = null,
        #[\SensitiveParameter] public readonly ?array $ddl = null,
        public readonly float $budgetSeconds = 15.0,
    ) {
    }
}
