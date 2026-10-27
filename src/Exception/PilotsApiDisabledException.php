<?php

declare(strict_types=1);

namespace VamsysMcp\Exception;

/**
 * A pilot endpoint was requested but the Pilots API is disabled for this airline.
 */
final class PilotsApiDisabledException extends VamsysMcpException
{
}
