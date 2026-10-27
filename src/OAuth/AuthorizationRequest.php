<?php

declare(strict_types=1);

namespace VamsysMcp\OAuth;

/** A validated /oauth/authorize request, kept in the session until the user approves or denies it. */
final class AuthorizationRequest
{
    public function __construct(
        public readonly string $clientId,
        public readonly string $clientName,
        public readonly string $redirectUri,
        public readonly string $codeChallenge,
        public readonly string $resource,
        public readonly string $scope,
        public readonly ?string $state,
    ) {
    }

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['clientId'], (string) $data['clientName'], (string) $data['redirectUri'], (string) $data['codeChallenge'],
            (string) $data['resource'], (string) $data['scope'], isset($data['state']) ? (string) $data['state'] : null,
        );
    }
}
