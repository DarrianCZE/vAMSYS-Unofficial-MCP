<?php

declare(strict_types=1);

namespace VamsysMcp\Exception;

/**
 * Base class for every error this project raises. Messages are written to be
 * shown to the MCP client, so they must never contain tokens or secrets.
 */
class VamsysMcpException extends \RuntimeException
{
}
