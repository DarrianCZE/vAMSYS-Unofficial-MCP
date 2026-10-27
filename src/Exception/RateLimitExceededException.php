<?php

declare(strict_types=1);

namespace VamsysMcp\Exception;

/**
 * The per-airline request cap was reached, or vAMSYS answered HTTP 429.
 */
final class RateLimitExceededException extends VamsysMcpException
{
}
