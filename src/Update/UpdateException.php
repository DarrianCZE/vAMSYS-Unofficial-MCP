<?php

declare(strict_types=1);

namespace VamsysMcp\Update;

use VamsysMcp\Exception\VamsysMcpException;

/** An update could not be checked, verified or applied; the message is shown to the operator. */
final class UpdateException extends VamsysMcpException
{
}
