<?php

declare(strict_types=1);

namespace VamsysMcp\Exception;

/**
 * The per-airline request cap was reached, or vAMSYS answered HTTP 429.
 */
final class RateLimitExceededException extends VamsysMcpException
{
    /**
     * @param int|null $retryAfterSeconds when the next request can go out, if known
     */
    public function __construct(string $message, public readonly ?int $retryAfterSeconds = null)
    {
        parent::__construct($message);
    }
}
