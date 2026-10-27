<?php

declare(strict_types=1);

namespace VamsysMcp\OAuth;

use Mcp\Server\Authorization\AccessToken;
use Mcp\Server\Transport\Http\OAuth\AuthorizationResult;
use Mcp\Server\Transport\Http\OAuth\AuthorizationTokenValidatorInterface;

/** Plugs this server's OAuth access tokens into the SDK's AuthorizationMiddleware. */
final class TokenValidator implements AuthorizationTokenValidatorInterface
{
    public function __construct(private readonly OAuthService $oauth)
    {
    }

    public function validate(string $accessToken): AuthorizationResult
    {
        $token = $this->oauth->validateAccessToken($accessToken);
        if ($token === null) {
            return AuthorizationResult::unauthorized('invalid_token', 'The access token is invalid, expired or revoked.');
        }

        return AuthorizationResult::allow(new AccessToken([$token['scope']], [
            'sub' => (string) $token['user_id'],
            'client_id' => $token['client_id'],
            'aud' => $token['resource'],
        ]));
    }
}
