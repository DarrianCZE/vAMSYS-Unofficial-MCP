<?php

declare(strict_types=1);

namespace VamsysMcp\OAuth;

use Mcp\Server\Transport\Http\OAuth\ProtectedResourceMetadata;
use VamsysMcp\Admin\Html;
use VamsysMcp\Admin\LoginThrottle;
use VamsysMcp\Admin\Session;
use VamsysMcp\Config;
use VamsysMcp\Paths;

/**
 * HTTP side of the OAuth authorization server, routed from public/index.php:
 *
 *   GET  /.well-known/oauth-authorization-server     RFC 8414 metadata
 *   GET  /.well-known/oauth-protected-resource[/mcp] RFC 9728 metadata
 *   GET  /oauth/authorize   sign-in + consent page;  POST /oauth/authorize  decision
 *   POST /oauth/token       code exchange and refresh (form-urlencoded)
 *   POST /oauth/register    Dynamic Client Registration (JSON)
 */
final class OAuthApp
{
    public const array PATHS = [
        '/.well-known/oauth-authorization-server', '/.well-known/oauth-protected-resource', '/.well-known/oauth-protected-resource/mcp',
        '/oauth/authorize', '/oauth/token', '/oauth/register',
    ];
    private const int MAX_PENDING_REQUESTS = 5;

    public function __construct(
        private readonly Config $config,
        private readonly OAuthService $oauth,
        private readonly McpUsers $users,
        private readonly \PDO $pdo,
        private readonly Paths $paths,
    ) {
    }

    public static function handles(string $path): bool
    {
        return in_array($path, self::PATHS, true);
    }

    public function run(string $path): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($path !== '/oauth/authorize') {
            // Machine endpoints: callable from browser-based clients (e.g. MCP Inspector); they use no cookies.
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Headers: Content-Type, Authorization, MCP-Protocol-Version');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            if ($method === 'OPTIONS') {
                http_response_code(204);

                return;
            }
        }

        try {
            match (true) {
                $path === '/.well-known/oauth-authorization-server' => $this->json($this->oauth->metadata()),
                $path === '/.well-known/oauth-protected-resource' => $this->json($this->resourceMetadata($this->config->baseUrl())),
                $path === '/.well-known/oauth-protected-resource/mcp' => $this->json($this->resourceMetadata($this->config->baseUrl() . '/mcp')),
                $path === '/oauth/authorize' => $this->authorize($method),
                $path === '/oauth/token' && $method === 'POST' => $this->token(),
                $path === '/oauth/register' && $method === 'POST' => $this->register(),
                default => $this->json(['error' => 'invalid_request', 'error_description' => 'Method not allowed.'], 405),
            };
        } catch (OAuthException $e) {
            $this->json($e->toArray(), $e->status, ['Cache-Control' => 'no-store']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function resourceMetadata(string $resource): array
    {
        return (new ProtectedResourceMetadata($resource, [$this->config->baseUrl()], [OAuthService::SCOPE], 'vAMSYS MCP'))->jsonSerialize();
    }

    private function token(): void
    {
        $result = $this->oauth->token($_POST);
        $this->json($result, 200, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
    }

    private function register(): void
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $throttle = new LoginThrottle($this->pdo, 'dcr:', 20, 200);
        if ($throttle->isBlocked($ip)) {
            throw new OAuthException('invalid_request', 'Too many client registrations; try again later.', 429);
        }
        $body = (string) file_get_contents('php://input', false, null, 0, 16384);
        $request = json_decode($body, true);
        if (!is_array($request)) {
            throw new OAuthException('invalid_client_metadata', 'The registration request must be a JSON object.');
        }
        $throttle->recordFailure($ip); // counts registrations, successful or not
        $this->json($this->oauth->registerClient($request), 201, ['Cache-Control' => 'no-store']);
    }

    private function authorize(string $method): void
    {
        $https = $this->isHttps();
        $nonce = base64_encode(random_bytes(16));
        Session::start($this->paths, $https, 'vamsys_oauth', '/oauth/');
        $html = new Html($nonce);

        if ($method === 'POST') {
            $this->decide($html, $nonce);

            return;
        }

        try {
            $request = $this->oauth->parseAuthorizationRequest($_GET);
        } catch (OAuthException $e) {
            if ($e->redirectable) {
                $this->redirect($this->oauth->errorRedirectUrl($_GET, $e));

                return;
            }
            $this->page($html, $nonce, null, 400, 'Cannot connect', '<div class="flash error">' . Html::e($e->getMessage()) . '</div>');

            return;
        }

        $id = bin2hex(random_bytes(12));
        $pending = (array) (Session::get('authz') ?? []);
        $pending[$id] = $request->toArray();
        Session::set('authz', array_slice($pending, -self::MAX_PENDING_REQUESTS, null, true));
        $this->consentPage($html, $nonce, $request, $id, '');
    }

    private function decide(Html $html, string $nonce): void
    {
        $id = (string) ($_POST['request_id'] ?? '');
        $pending = (array) (Session::get('authz') ?? []);
        if (!Session::checkCsrf($_POST['csrf'] ?? null) || !isset($pending[$id])) {
            $this->page($html, $nonce, null, 400, 'Cannot connect', '<div class="flash error">This sign-in page expired. Start connecting again from your MCP client.</div>');

            return;
        }
        $request = AuthorizationRequest::fromArray((array) $pending[$id]);

        if (($_POST['decision'] ?? '') !== 'allow') {
            unset($pending[$id]);
            Session::set('authz', $pending);
            $this->redirect($this->oauth->denyUrl($request));

            return;
        }

        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $throttle = new LoginThrottle($this->pdo, 'mcp:');
        if ($throttle->isBlocked($ip)) {
            $this->consentPage($html, $nonce, $request, $id, 'Too many failed sign-ins. Wait 15 minutes.');

            return;
        }
        $user = $this->users->verify((string) ($_POST['username'] ?? ''), (string) ($_POST['password'] ?? ''));
        if ($user === null) {
            $throttle->recordFailure($ip);
            $this->consentPage($html, $nonce, $request, $id, 'Wrong user name or password, or the user is disabled.');

            return;
        }
        $throttle->reset($ip);
        unset($pending[$id]);
        Session::set('authz', $pending);
        $this->redirect($this->oauth->approve($request, $user['id']));
    }

    private function consentPage(Html $html, string $nonce, AuthorizationRequest $request, string $id, string $error): void
    {
        $host = RedirectPolicy::host($request->redirectUri);
        $loopback = RedirectPolicy::loopbackKey($request->redirectUri) !== null;
        $body = ($error !== '' ? '<div class="flash error">' . Html::e($error) . '</div>' : '')
            . '<p><strong>' . Html::e($request->clientName) . '</strong> wants <strong>read-only</strong> access to the vAMSYS data of this MCP server ('
            . Html::e(implode(', ', array_keys($this->config->airlines))) . ').</p>'
            . '<p>After you allow it, you will be sent back to <strong>' . Html::e($host) . '</strong>.</p>'
            . ($loopback ? '<div class="flash warn">This returns to a program on your own computer. Only continue if you started this connection yourself just now (e.g. in Claude Code).</div>' : '')
            . '<form method="post" action="/oauth/authorize">' . $html->csrfField()
            . '<input type="hidden" name="request_id" value="' . Html::e($id) . '">'
            . '<label><span>MCP user name</span><input type="text" name="username" autocomplete="username" autofocus></label>'
            . '<label><span>Password</span><input type="password" name="password" autocomplete="current-password"></label>'
            . '<p><button name="decision" value="allow">Allow</button> <button class="secondary" name="decision" value="deny">Deny</button></p></form>'
            . '<p class="help">MCP users are managed by the operator of this server in its admin page. You can revoke this connection there at any time.</p>';
        $this->page($html, $nonce, $request->redirectUri, 200, 'Connect ' . $request->clientName, $body);
    }

    private function page(Html $html, string $nonce, ?string $redirectUri, int $status, string $title, string $body): void
    {
        // form-action also governs the redirect after the form is submitted, so allow exactly this request's target.
        $formAction = "'self'" . ($redirectUri !== null ? ' ' . self::origin($redirectUri) : '');
        header("Content-Security-Policy: default-src 'none'; style-src 'nonce-{$nonce}'; script-src 'nonce-{$nonce}'; form-action {$formAction}; frame-ancestors 'none'; base-uri 'none'");
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        if (!$this->isHttps() && !$this->isLocal()) {
            $body = '<div class="flash error">This page must be opened over https://.</div>';
        }
        echo $html->page($title, $body);
    }

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $headers
     */
    private function json(array $data, int $status = 200, array $headers = []): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        foreach ($headers as $name => $value) {
            header("{$name}: {$value}");
        }
        echo json_encode($data, JSON_UNESCAPED_SLASHES);
    }

    private function redirect(string $url): void
    {
        header('Location: ' . $url, true, 302);
        header('Cache-Control: no-store');
    }

    private static function origin(string $uri): string
    {
        $p = parse_url($uri);

        return strtolower(($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '')) . (isset($p['port']) ? ':' . $p['port'] : (RedirectPolicy::loopbackKey($uri) !== null ? ':*' : ''));
    }

    private function isHttps(): bool
    {
        return (($_SERVER['HTTPS'] ?? '') !== '' && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
            || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
    }

    private function isLocal(): bool
    {
        return in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
    }
}
