<?php

declare(strict_types=1);

namespace VamsysMcp\OAuth;

use VamsysMcp\Exception\VamsysMcpException;

/**
 * An OAuth error (RFC 6749 error code + description). $redirectable is true when
 * the error may be sent back to the client's redirect_uri; otherwise it is shown
 * to the user, because the redirect target itself is not trusted.
 */
final class OAuthException extends VamsysMcpException
{
    public function __construct(
        public readonly string $error,
        string $description,
        public readonly int $status = 400,
        public readonly bool $redirectable = false,
    ) {
        parent::__construct($description);
    }

    /**
     * @return array{error: string, error_description: string}
     */
    public function toArray(): array
    {
        return ['error' => $this->error, 'error_description' => $this->getMessage()];
    }
}
