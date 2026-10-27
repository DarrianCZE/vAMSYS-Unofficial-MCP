<?php

declare(strict_types=1);

namespace VamsysMcp\Tests\OAuth;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use VamsysMcp\Exception\ConfigException;
use VamsysMcp\OAuth\ClientMetadataFetcher;
use VamsysMcp\OAuth\ClientRegistry;
use VamsysMcp\OAuth\McpUsers;
use VamsysMcp\OAuth\OAuthException;
use VamsysMcp\OAuth\OAuthService;
use VamsysMcp\OAuth\RedirectPolicy;
use VamsysMcp\Tests\Support\FakeClock;
use VamsysMcp\Tests\Support\TestDatabase;

final class OAuthTest extends TestCase
{
    private const string ISSUER = 'https://mcp.example.com';
    private const string CLAUDE_CALLBACK = 'https://claude.ai/api/mcp/auth_callback';
    private const string CIMD_URL = 'https://claude.ai/oauth/claude-client-metadata';

    private \PDO $pdo;
    private FakeClock $clock;
    private McpUsers $users;
    private OAuthService $oauth;
    private int $userId;
    /** @var list<MockResponse> */
    private array $cimdResponses = [];
    /** @var list<string> */
    private array $resolvedIps = ['160.79.104.10'];

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::create();
        $this->clock = new FakeClock();
        $this->users = new McpUsers($this->pdo);
        $this->userId = $this->users->create('pilot.ops', 'correct horse battery');
        $fetcher = new ClientMetadataFetcher(
            new MockHttpClient(fn () => array_shift($this->cimdResponses) ?? new MockResponse('', ['http_code' => 404])),
            fn (string $host): array => $this->resolvedIps,
        );
        $registry = new ClientRegistry($this->pdo, $fetcher, new RedirectPolicy(), $this->clock);
        $this->oauth = new OAuthService($this->pdo, $registry, $this->users, $this->clock, self::ISSUER, [self::ISSUER . '/mcp', self::ISSUER]);
    }

    public function testMetadataAdvertisesWhatClaudeNeeds(): void
    {
        $m = $this->oauth->metadata();

        self::assertSame(self::ISSUER, $m['issuer']);
        self::assertSame(['S256'], $m['code_challenge_methods_supported']);
        self::assertSame(['none'], $m['token_endpoint_auth_methods_supported']);
        self::assertTrue($m['client_id_metadata_document_supported']);
        self::assertSame(self::ISSUER . '/oauth/register', $m['registration_endpoint']);
        self::assertContains('offline_access', $m['scopes_supported']);
    }

    public function testRegistrationAcceptsOnlyAllowedRedirectHostsAndPublicClients(): void
    {
        $client = $this->oauth->registerClient(['client_name' => 'Claude', 'redirect_uris' => [self::CLAUDE_CALLBACK]]);
        self::assertStringStartsWith('dcr_', $client['client_id']);
        self::assertSame('none', $client['token_endpoint_auth_method']);

        foreach ([['redirect_uris' => ['https://evil.example/cb']], ['redirect_uris' => ['http://claude.ai/cb']], ['redirect_uris' => [self::CLAUDE_CALLBACK], 'token_endpoint_auth_method' => 'client_secret_post']] as $bad) {
            try {
                $this->oauth->registerClient($bad);
                self::fail('Registration should be rejected: ' . json_encode($bad));
            } catch (OAuthException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testFullFlowWithPkceRefreshRotationAndReuseDetection(): void
    {
        $clientId = $this->oauth->registerClient(['client_name' => 'Claude', 'redirect_uris' => [self::CLAUDE_CALLBACK]])['client_id'];
        [$verifier, $challenge] = self::pkce();

        $request = $this->oauth->parseAuthorizationRequest($this->authorizeQuery($clientId, $challenge, ['resource' => self::ISSUER . '/mcp/']));
        self::assertSame(self::ISSUER . '/mcp', $request->resource, 'trailing slash is normalized');
        $redirect = $this->oauth->approve($request, $this->userId);
        parse_str((string) parse_url($redirect, PHP_URL_QUERY), $params);
        self::assertStringStartsWith(self::CLAUDE_CALLBACK . '?', $redirect);
        self::assertSame('xyz', $params['state']);
        self::assertSame(self::ISSUER, $params['iss']);

        $tokens = $this->oauth->token(['grant_type' => 'authorization_code', 'code' => $params['code'], 'client_id' => $clientId,
            'redirect_uri' => self::CLAUDE_CALLBACK, 'code_verifier' => $verifier, 'resource' => self::ISSUER . '/mcp']);
        self::assertSame('Bearer', $tokens['token_type']);
        $access = $this->oauth->validateAccessToken($tokens['access_token']);
        self::assertSame($this->userId, $access['user_id']);
        self::assertSame(OAuthService::SCOPE, $access['scope']);
        self::assertNull($this->oauth->validateAccessToken('vmcp_at_forged'));

        // Rotation: new pair, old access token retired.
        $rotated = $this->oauth->token(['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'], 'client_id' => $clientId]);
        self::assertNotSame($tokens['refresh_token'], $rotated['refresh_token']);
        self::assertNull($this->oauth->validateAccessToken($tokens['access_token']));
        self::assertNotNull($this->oauth->validateAccessToken($rotated['access_token']));
        self::assertCount(1, $this->oauth->activeGrants());

        // Replaying the old refresh token revokes the whole connection.
        $this->expectOAuthError('invalid_grant', fn () => $this->oauth->token(['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token']]));
        self::assertNull($this->oauth->validateAccessToken($rotated['access_token']));
        self::assertSame([], $this->oauth->activeGrants());
    }

    public function testCodeNeedsCorrectVerifierAndCanBeUsedOnce(): void
    {
        $clientId = $this->oauth->registerClient(['redirect_uris' => [self::CLAUDE_CALLBACK]])['client_id'];
        [$verifier, $challenge] = self::pkce();
        $code = $this->code($clientId, $challenge);
        $form = ['grant_type' => 'authorization_code', 'code' => $code, 'client_id' => $clientId, 'redirect_uri' => self::CLAUDE_CALLBACK];

        $this->expectOAuthError('invalid_grant', fn () => $this->oauth->token($form + ['code_verifier' => str_repeat('a', 43)]));
        // A failed attempt still consumed the code (single use).
        $this->expectOAuthError('invalid_grant', fn () => $this->oauth->token($form + ['code_verifier' => $verifier]));

        $code = $this->code($clientId, $challenge);
        $this->oauth->token(['code' => $code, 'code_verifier' => $verifier] + $form);
        $this->expectOAuthError('invalid_grant', fn () => $this->oauth->token(['code' => $code, 'code_verifier' => $verifier] + $form));
        self::assertSame([], $this->oauth->activeGrants(), 'a replayed code revokes the tokens it produced');
    }

    public function testExpiredCodeAndExpiredAccessToken(): void
    {
        $clientId = $this->oauth->registerClient(['redirect_uris' => [self::CLAUDE_CALLBACK]])['client_id'];
        [$verifier, $challenge] = self::pkce();
        $form = ['grant_type' => 'authorization_code', 'client_id' => $clientId, 'redirect_uri' => self::CLAUDE_CALLBACK, 'code_verifier' => $verifier];

        $code = $this->code($clientId, $challenge);
        $this->clock->advance(121);
        $this->expectOAuthError('invalid_grant', fn () => $this->oauth->token($form + ['code' => $code]));

        $tokens = $this->oauth->token($form + ['code' => $this->code($clientId, $challenge)]);
        $this->clock->advance(3601);
        self::assertNull($this->oauth->validateAccessToken($tokens['access_token']));
        self::assertArrayHasKey('access_token', $this->oauth->token(['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token']]));
    }

    public function testAuthorizationRequestValidation(): void
    {
        $clientId = $this->oauth->registerClient(['redirect_uris' => [self::CLAUDE_CALLBACK, 'http://localhost/callback']])['client_id'];
        [, $challenge] = self::pkce();

        // Loopback redirects match on any port (Claude Code).
        $ok = $this->oauth->parseAuthorizationRequest($this->authorizeQuery($clientId, $challenge, ['redirect_uri' => 'http://localhost:51234/callback']));
        self::assertSame('http://localhost:51234/callback', $ok->redirectUri);

        $cases = [
            'unknown client' => [['client_id' => 'dcr_nope'], 'invalid_client', false],
            'unregistered redirect' => [['redirect_uri' => 'https://claude.ai/other'], 'invalid_request', false],
            'loopback other path' => [['redirect_uri' => 'http://localhost:5000/evil'], 'invalid_request', false],
            'no PKCE' => [['code_challenge' => null], 'invalid_request', true],
            'plain PKCE' => [['code_challenge_method' => 'plain'], 'invalid_request', true],
            'wrong response type' => [['response_type' => 'token'], 'unsupported_response_type', true],
            'foreign resource' => [['resource' => 'https://other.example/mcp'], 'invalid_target', true],
        ];
        foreach ($cases as $name => [$override, $error, $redirectable]) {
            try {
                $this->oauth->parseAuthorizationRequest(array_filter($this->authorizeQuery($clientId, $challenge, $override), static fn ($v) => $v !== null));
                self::fail("{$name} should fail");
            } catch (OAuthException $e) {
                self::assertSame([$error, $redirectable], [$e->error, $e->redirectable], $name);
            }
        }
    }

    public function testClientIdMetadataDocument(): void
    {
        $this->cimdResponses[] = new MockResponse((string) json_encode([
            'client_id' => self::CIMD_URL, 'client_name' => 'Claude', 'redirect_uris' => [self::CLAUDE_CALLBACK, 'https://evil.example/cb'],
            'token_endpoint_auth_method' => 'none',
        ]), ['response_headers' => ['content-type' => 'application/json']]);
        [, $challenge] = self::pkce();

        $request = $this->oauth->parseAuthorizationRequest($this->authorizeQuery(self::CIMD_URL, $challenge));
        self::assertSame('Claude', $request->clientName);
        // Cached: no second fetch needed.
        $this->oauth->parseAuthorizationRequest($this->authorizeQuery(self::CIMD_URL, $challenge));
        // Redirects outside the allowlist are dropped from the document.
        $this->expectOAuthError('invalid_request', fn () => $this->oauth->parseAuthorizationRequest($this->authorizeQuery(self::CIMD_URL, $challenge, ['redirect_uri' => 'https://evil.example/cb'])));
    }

    public function testClientIdMetadataDocumentMustMatchItsUrlAndBePublic(): void
    {
        [, $challenge] = self::pkce();
        $this->cimdResponses[] = new MockResponse((string) json_encode(['client_id' => 'https://other.example/meta', 'redirect_uris' => [self::CLAUDE_CALLBACK]]));
        $this->expectOAuthError('invalid_client', fn () => $this->oauth->parseAuthorizationRequest($this->authorizeQuery(self::CIMD_URL, $challenge)));

        $this->resolvedIps = ['10.0.0.5'];
        $this->expectOAuthError('invalid_client', fn () => $this->oauth->parseAuthorizationRequest($this->authorizeQuery('https://intranet.example/meta', $challenge)));
        $this->resolvedIps = ['127.0.0.1'];
        $this->expectOAuthError('invalid_client', fn () => $this->oauth->parseAuthorizationRequest($this->authorizeQuery('https://localhost.example/meta', $challenge)));
    }

    public function testDisabledUserLosesAccessImmediately(): void
    {
        $clientId = $this->oauth->registerClient(['redirect_uris' => [self::CLAUDE_CALLBACK]])['client_id'];
        [$verifier, $challenge] = self::pkce();
        $tokens = $this->oauth->token(['grant_type' => 'authorization_code', 'code' => $this->code($clientId, $challenge), 'client_id' => $clientId,
            'redirect_uri' => self::CLAUDE_CALLBACK, 'code_verifier' => $verifier]);

        $this->users->setDisabled($this->userId, true);
        self::assertNull($this->oauth->validateAccessToken($tokens['access_token']));
        $this->expectOAuthError('invalid_grant', fn () => $this->oauth->token(['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token']]));
    }

    public function testUsers(): void
    {
        self::assertSame(['id' => $this->userId, 'username' => 'pilot.ops'], $this->users->verify(' Pilot.Ops ', 'correct horse battery'));
        self::assertNull($this->users->verify('pilot.ops', 'wrong password!!'));
        self::assertNull($this->users->verify('nobody', 'correct horse battery'));

        foreach ([['pilot.ops', 'another long password'], ['x', 'long enough password'], ['new.user', 'short']] as [$name, $password]) {
            try {
                $this->users->create($name, $password);
                self::fail("create({$name}) should fail");
            } catch (ConfigException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testRedirectPolicy(): void
    {
        $policy = new RedirectPolicy();
        self::assertTrue($policy->isAllowed(self::CLAUDE_CALLBACK));
        self::assertTrue($policy->isAllowed('http://127.0.0.1:33418/callback'));
        self::assertFalse($policy->isAllowed('http://claude.ai/api/mcp/auth_callback'), 'https required for non-loopback');
        self::assertFalse($policy->isAllowed('https://claude.ai.evil.example/cb'));
        self::assertFalse($policy->isAllowed('https://user@claude.ai/cb'));
        self::assertTrue((new RedirectPolicy(['chatgpt.com']))->isAllowed('https://chatgpt.com/connector_platform_oauth_redirect'));
    }

    /**
     * @param array<string, string|null> $override
     *
     * @return array<string, string|null>
     */
    private function authorizeQuery(string $clientId, string $challenge, array $override = []): array
    {
        return $override + [
            'response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => self::CLAUDE_CALLBACK,
            'code_challenge' => $challenge, 'code_challenge_method' => 'S256', 'state' => 'xyz', 'scope' => 'vamsys:read offline_access',
        ];
    }

    private function code(string $clientId, string $challenge): string
    {
        $redirect = $this->oauth->approve($this->oauth->parseAuthorizationRequest($this->authorizeQuery($clientId, $challenge)), $this->userId);
        parse_str((string) parse_url($redirect, PHP_URL_QUERY), $params);

        return (string) $params['code'];
    }

    /**
     * @return array{0: string, 1: string} verifier, S256 challenge
     */
    private static function pkce(): array
    {
        $verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        return [$verifier, rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=')];
    }

    private function expectOAuthError(string $error, \Closure $call): void
    {
        try {
            $call();
            self::fail("Expected OAuth error {$error}");
        } catch (OAuthException $e) {
            self::assertSame($error, $e->error, $e->getMessage());
        }
    }
}
