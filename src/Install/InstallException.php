<?php

declare(strict_types=1);

namespace VamsysMcp\Install;

use VamsysMcp\Exception\VamsysMcpException;

/** An install or reconfiguration step failed; the message is shown to the operator. */
final class InstallException extends VamsysMcpException
{
}
