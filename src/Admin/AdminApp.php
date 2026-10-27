<?php

declare(strict_types=1);

namespace VamsysMcp\Admin;

use Nyholm\Psr7\Factory\Psr17Factory;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use VamsysMcp\App;
use VamsysMcp\Config;
use VamsysMcp\Exception\VamsysMcpException;
use VamsysMcp\Install\ConfigFile;
use VamsysMcp\Install\EncryptedSettings;
use VamsysMcp\Install\FtpCredentials;
use VamsysMcp\Install\InstallService;
use VamsysMcp\Install\Migrator;
use VamsysMcp\Install\OwnershipProof;
use VamsysMcp\Install\SecretBox;
use VamsysMcp\OAuth\McpUsers;
use VamsysMcp\Paths;
use VamsysMcp\Update\FtpFileWriter;
use VamsysMcp\Update\GitHubReleases;
use VamsysMcp\Update\LocalFileWriter;
use VamsysMcp\Update\StepContext;
use VamsysMcp\Update\Updater;
use VamsysMcp\Update\UpdateJob;

/**
 * Web installer and admin UI (public/setup.php).
 *
 * States: not installed → ownership proof + install form; installed without admin
 * password (or "forgot password") → ownership proof + set password; otherwise login → admin pages.
 * Only reachable over HTTPS (or from localhost); every POST needs the CSRF token.
 */
final class AdminApp
{
    /** Actions called by the update console's fetch(); they answer JSON instead of redirecting. */
    private const array JSON_ACTIONS = ['update-prepare', 'update-step', 'update-rollback', 'update-retry', 'update-cancel'];

    private Html $html;
    private ConfigFile $configFile;

    public function __construct(private readonly Paths $paths)
    {
        $this->configFile = new ConfigFile($paths->configFile());
    }

    private static function isJsonAction(?string $action): bool
    {
        return in_array($action, self::JSON_ACTIONS, true);
    }

    public function run(): void
    {
        $nonce = base64_encode(random_bytes(16));
        $this->html = new Html($nonce);
        header("Content-Security-Policy: default-src 'none'; style-src 'nonce-{$nonce}'; script-src 'nonce-{$nonce}'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');

        if (!$this->isHttps() && !$this->isLocal()) {
            $this->send(403, 'HTTPS required', '<p>Open this page over <strong>https://</strong>. The admin pages handle passwords and secrets and refuse plain HTTP.</p>');

            return;
        }

        try {
            $raw = $this->configFile->load();
        } catch (\Throwable) {
            $this->send(500, 'Configuration error', '<p>config/config.php exists but cannot be loaded. Fix or delete it via FTP / file manager.</p>');

            return;
        }
        if ($raw !== [] && ($raw['admin']['web_enabled'] ?? true) === false) {
            $this->send(404, 'Not found', '<p>The web admin is disabled in config.php (admin.web_enabled).</p>');

            return;
        }

        Session::start($this->paths, $this->isHttps());
        $action = isset($_GET['a']) ? (string) $_GET['a'] : null;
        if ($action !== null) {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                $this->send(405, 'Method not allowed', '');

                return;
            }
            if (!Session::checkCsrf($_POST['csrf'] ?? null)) {
                self::isJsonAction($action)
                    ? $this->json(['phase' => 'failed', 'error' => 'Your session expired; reload the page.'], 400)
                    : $this->redirect('?', 'error', 'Your session expired; please try again.');

                return;
            }
        }

        try {
            match (true) {
                $raw === [] => $this->installFlow($action),
                empty($raw['admin']['password_hash']) => $this->resetPasswordFlow($raw, $action),
                !Session::isAdmin() => $this->loginFlow($raw, $action),
                default => $this->adminFlow($raw, $action, (string) ($_GET['p'] ?? 'dashboard')),
            };
        } catch (VamsysMcpException $e) {
            self::isJsonAction($action)
                ? $this->json(['phase' => 'failed', 'error' => $e->getMessage()])
                : $this->redirect('?' . (isset($_GET['p']) ? 'p=' . urlencode((string) $_GET['p']) : ''), 'error', $e->getMessage());
        }
    }

    // ---------------------------------------------------------------- install

    private function installFlow(?string $action): void
    {
        if (!$this->unlockFlow($action, 'Install')) {
            return;
        }
        $values = ['allowed_hosts' => $this->requestHost(), 'airlines' => [[]], 'db' => ['host' => 'localhost', 'port' => 3306], 'verify_airlines' => true];
        $errors = [];
        if ($action === 'install') {
            $input = $this->settingsInput();
            [$raw, $errors] = $this->installer()->buildConfig($input, []);
            $ftp = null;
            if ($errors === [] && trim((string) ($_POST['ftp']['host'] ?? '')) !== '') {
                try {
                    $ftp = FtpCredentials::fromInput((array) $_POST['ftp']);
                } catch (VamsysMcpException $e) {
                    $errors[] = $e->getMessage();
                }
            }
            if ($errors === []) {
                try {
                    $mcpUser = (array) ($_POST['mcp_user'] ?? []);
                    $result = $this->installer()->install($raw, $this->ddlInput(), (bool) $input['verify_airlines'], $ftp, [
                        'username' => (string) ($mcpUser['username'] ?? ''), 'password' => (string) ($mcpUser['password'] ?? ''),
                    ]);
                    Session::login();
                    $this->send(200, 'Installed', $this->installedPage($raw, $result['mcp_user']));

                    return;
                } catch (VamsysMcpException $e) {
                    $errors[] = $e->getMessage();
                }
            }
            $values = $input;
        }

        $body = $this->errorList($errors)
            . '<form method="post" action="?a=install">' . $this->html->csrfField()
            . $this->databaseFieldset((array) ($values['db'] ?? []), true)
            . $this->ddlFieldset()
            . $this->airlinesFieldset((array) ($values['airlines'] ?? [[]]), [])
            . $this->endpointFieldset($values)
            . '<fieldset><legend>Web admin password</legend>'
            . $this->html->input('Password (at least ' . InstallService::MIN_ADMIN_PASSWORD_LENGTH . ' characters)', 'admin_password', '', 'password')
            . $this->html->input('Repeat password', 'admin_password_confirm', '', 'password') . '</fieldset>'
            . '<fieldset><legend>First MCP user</legend><p class="help">The person who signs in when connecting Claude (or another MCP client). Separate from the admin password; add more users later under MCP users.</p>'
            . '<div class="grid">' . $this->html->input('User name', 'mcp_user[username]', (string) ($_POST['mcp_user']['username'] ?? ''))
            . $this->html->input('Password (at least ' . McpUsers::MIN_PASSWORD_LENGTH . ' characters)', 'mcp_user[password]', '', 'password') . '</div></fieldset>'
            . $this->ftpFieldset(null, 'Optional. Only needed if PHP cannot write the installation files itself; you can add it later. Stored encrypted.')
            . '<button>Install</button></form>' . $this->airlineTemplateScript();
        $this->send(200, 'Install', $body);
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function installedPage(array $raw, ?string $mcpUser): string
    {
        $url = Config::fromArray($raw)->baseUrl() . '/mcp';

        return '<div class="flash ok">Installation complete. Secrets were saved to config.php outside the web root.</div>'
            . ($mcpUser === null ? '<div class="flash warn">No MCP user yet: create one under MCP users before connecting Claude.</div>' : '')
            . $this->connectHelp($url)
            . '<p><a class="button" href="?p=dashboard">Go to the dashboard</a></p>';
    }

    private function connectHelp(string $url): string
    {
        return '<h3>Connecting Claude</h3><p>MCP server URL: <code>' . Html::e($url) . '</code></p>'
            . '<ul><li><strong>claude.ai / Claude Desktop:</strong> Customize → Connectors → Add custom connector, paste the URL, keep the default sign-in. '
            . 'Claude opens this server\'s sign-in page; log in with an MCP user and click Allow.</li>'
            . '<li><strong>Claude Code:</strong> <code>claude mcp add --transport http vamsys ' . Html::e($url) . '</code>, then <code>/mcp</code> in Claude Code to sign in.</li></ul>';
    }

    /**
     * Ownership gate (see OwnershipProof). Returns true when the session is unlocked.
     */
    private function unlockFlow(?string $action, string $purpose): bool
    {
        if (Session::isUnlocked()) {
            return true;
        }
        $code = Session::get('proof_code');
        if (!OwnershipProof::isValidCode($code)) {
            $code = OwnershipProof::newCode();
            Session::set('proof_code', $code);
        }
        $proof = new OwnershipProof($this->paths->root);
        $error = '';
        if ($action === 'unlock') {
            if ($proof->verify($code)) {
                Session::unlock();
                Session::set('proof_code', null);
                if (!$proof->remove($code)) {
                    Session::flash('warn', "Done. Please delete {$proof->fileName($code)} from the installation folder; it is no longer needed.");
                }

                return true;
            }
            $error = '<div class="flash error">The file was not found yet. Check the name and the folder, then try again.</div>';
        }
        $this->send(200, $purpose . ': prove you control this server', $error
            . '<ol><li>Connect with FTP, or open your hosting file manager.</li>'
            . '<li>Go to the installation folder: the one that contains <code>public</code>, <code>src</code> and <code>vendor</code>. On this server it is:<pre>' . Html::e($proof->folder()) . '</pre></li>'
            . '<li>Create an empty file with exactly this name:<pre>' . Html::e($proof->fileName($code)) . '</pre></li>'
            . '<li>Click Continue.</li></ol>'
            . '<form method="post" action="?a=unlock">' . $this->html->csrfField() . '<button>Continue</button></form>'
            . '<p class="help">Only someone with access to the server\'s files can create that file, so nobody else can use this page. The code is new for every browser session.</p>');

        return false;
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function resetPasswordFlow(array $raw, ?string $action): void
    {
        if (!$this->configFile->isManaged()) {
            $this->send(200, 'Web admin not set up', '<p>config.php is maintained by hand and has no <code>admin.password_hash</code>. Add one, e.g. the output of <code>php -r "echo password_hash(\'your password\', PASSWORD_DEFAULT);"</code>, or delete the marker-less config and run the installer.</p>');

            return;
        }
        if (!$this->unlockFlow($action, 'Set admin password')) {
            return;
        }
        $error = '';
        if ($action === 'set-password') {
            $password = (string) ($_POST['admin_password'] ?? '');
            if (strlen($password) < InstallService::MIN_ADMIN_PASSWORD_LENGTH || $password !== (string) ($_POST['admin_password_confirm'] ?? '')) {
                $error = '<div class="flash error">Passwords must match and have at least ' . InstallService::MIN_ADMIN_PASSWORD_LENGTH . ' characters.</div>';
            } else {
                $raw['admin']['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
                $this->configFile->write($raw);
                Session::login();
                $this->redirect('?p=dashboard', 'ok', 'Admin password set.');

                return;
            }
        }
        $this->send(200, 'Set admin password', $error . '<form method="post" action="?a=set-password">' . $this->html->csrfField()
            . $this->html->input('New password', 'admin_password', '', 'password')
            . $this->html->input('Repeat password', 'admin_password_confirm', '', 'password') . '<button>Save</button></form>');
    }

    // ------------------------------------------------------------------ login

    /**
     * @param array<string, mixed> $raw
     */
    private function loginFlow(array $raw, ?string $action): void
    {
        if (self::isJsonAction($action)) {
            $this->json(['phase' => 'failed', 'error' => 'You were logged out; log in again and resume the update.'], 401);

            return;
        }
        if (($_GET['p'] ?? '') === 'reset' || in_array($action, ['unlock', 'set-password'], true)) {
            $this->resetPasswordFlow($raw, $action);

            return;
        }
        $error = '';
        if ($action === 'login') {
            $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
            $throttle = $this->throttle($raw);
            if ($throttle?->isBlocked($ip)) {
                $error = 'Too many failed logins. Wait 15 minutes.';
            } elseif (password_verify((string) ($_POST['password'] ?? ''), (string) $raw['admin']['password_hash'])) {
                $throttle?->reset($ip);
                if (password_needs_rehash((string) $raw['admin']['password_hash'], PASSWORD_DEFAULT) && $this->configFile->isManaged()) {
                    $raw['admin']['password_hash'] = password_hash((string) $_POST['password'], PASSWORD_DEFAULT);
                    $this->configFile->write($raw);
                }
                Session::login();
                $this->redirect('?p=dashboard');

                return;
            } else {
                $throttle?->recordFailure($ip);
                $throttle === null && sleep(2);
                $error = 'Wrong password.';
            }
        }
        $this->send(200, 'Log in', ($error !== '' ? '<div class="flash error">' . Html::e($error) . '</div>' : '')
            . '<form method="post" action="?a=login">' . $this->html->csrfField()
            . '<label><span>Admin password</span><input type="password" name="password" autocomplete="current-password" autofocus></label>'
            . '<button>Log in</button></form>'
            . '<p class="help"><a href="?p=reset">Forgot the password?</a> You can set a new one after proving you control the server\'s files.</p>');
    }

    // ------------------------------------------------------------------ admin

    /**
     * @param array<string, mixed> $raw
     */
    private function adminFlow(array $raw, ?string $action, string $page): void
    {
        match ($action) {
            'logout' => $this->logout(),
            'save-settings' => $this->saveSettings($raw),
            'user-add', 'user-disable', 'user-enable', 'user-password', 'user-delete', 'grant-revoke' => $this->userAction($raw, $action),
            'ftp-save', 'ftp-test' => $this->saveFtp($raw, $action === 'ftp-test'),
            'ftp-delete' => $this->deleteFtp($raw),
            'update-check' => $this->checkUpdate($raw),
            'update-prepare' => $this->prepareUpdate($raw),
            'update-step', 'update-rollback', 'update-retry', 'update-cancel' => $this->updateStep($raw, $action),
            null => match ($page) {
                'settings' => $this->settingsPage($raw),
                'users' => $this->usersPage($raw),
                'ftp' => $this->ftpPage($raw),
                'update' => $this->updatePage($raw),
                default => $this->dashboard($raw),
            },
            default => $this->redirect('?p=dashboard', 'error', 'Unknown action.'),
        };
    }

    private function logout(): void
    {
        Session::logout();
        $this->redirect('?', 'ok', 'Logged out.');
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function dashboard(array $raw): void
    {
        $rows = [
            'Version' => App::VERSION,
            'PHP' => PHP_VERSION,
            'Extensions' => $this->extensionSummary(),
            'Config file' => $this->paths->configFile() . ($this->configFile->isManaged() ? '' : ' (maintained by hand; settings are read-only here)'),
        ];
        try {
            $pdo = Config::fromArray($raw)->createPdo();
            $pending = (new Migrator($this->paths->migrationsDir()))->pending($pdo);
            $rows['Database'] = 'connected' . ($pending === [] ? '' : '; pending migrations: ' . implode(', ', $pending) . ' (open Settings and save with a DB admin login)');
        } catch (\Throwable $e) {
            $rows['Database'] = 'NOT reachable: ' . ($e instanceof \PDOException ? $e->getMessage() : $e::class);
        }
        $airlines = [];
        foreach ((array) ($raw['airlines'] ?? []) as $key => $a) {
            $airlines[] = sprintf('%s (%s, %s, cap %d/min%s)', $key, $a['name'] ?? $key, $a['weight_unit'] ?? '?', $a['rate_cap'] ?? 60, !empty($a['pilots_api']) ? ', Pilots API ON' : '');
        }
        $rows['Airlines'] = implode('; ', $airlines);
        $rows['MCP endpoint'] = Config::fromArray($raw)->baseUrl() . '/mcp (OAuth sign-in)';
        if (isset($pdo) && ($pending ?? []) === []) {
            $rows['MCP users'] = sprintf('%d user(s), %d active connection(s)', count((new McpUsers($pdo))->all()), count(App::oauth(Config::fromArray($raw), $pdo)->activeGrants()));
        }
        if (is_file($this->paths->maintenanceFlag())) {
            $rows['Maintenance'] = 'ON: an update is in progress or was interrupted; see Updates.';
        }

        $table = '<table>';
        foreach ($rows as $k => $v) {
            $table .= '<tr><th>' . Html::e($k) . '</th><td>' . Html::e($v) . '</td></tr>';
        }
        $this->send(200, 'Dashboard', $table . '</table>');
    }

    /**
     * @param array<string, mixed> $raw
     * @param array<string, mixed>|null $values posted values to redisplay
     * @param list<string> $errors
     */
    private function settingsPage(array $raw, ?array $values = null, array $errors = []): void
    {
        if (!$this->configFile->isManaged()) {
            $this->send(200, 'Settings', '<p>config.php is maintained by hand, so it cannot be edited here.</p>');

            return;
        }
        $db = InstallService::parseDsn((string) ($raw['database']['dsn'] ?? ''));
        $values ??= [
            'db' => ['host' => $db['host'] ?? 'localhost', 'port' => $db['port'] ?? 3306, 'name' => $db['dbname'] ?? '', 'user' => $raw['database']['username'] ?? ''],
            'airlines' => array_map(static fn ($k, $a) => ['key' => $k] + (array) $a, array_keys((array) $raw['airlines']), array_values((array) $raw['airlines'])),
            'allowed_hosts' => implode(', ', (array) ($raw['http']['allowed_hosts'] ?? [])),
            'audit_log' => (bool) ($raw['audit_log'] ?? false),
            'verify_airlines' => false,
        ];
        $body = $this->errorList($errors)
            . '<form method="post" action="?a=save-settings">' . $this->html->csrfField()
            . $this->airlinesFieldset((array) $values['airlines'], array_keys((array) $raw['airlines']))
            . $this->endpointFieldset($values)
            . $this->databaseFieldset((array) $values['db'], false)
            . '<fieldset><legend>Change admin password</legend>'
            . $this->html->input('Current password', 'current_password', '', 'password')
            . '<div class="grid">' . $this->html->input('New password', 'admin_password', '', 'password')
            . $this->html->input('Repeat new password', 'admin_password_confirm', '', 'password') . '</div></fieldset>'
            . $this->ddlFieldset('Only needed if the dashboard reports pending migrations.')
            . '<button>Save settings</button></form>' . $this->airlineTemplateScript();
        $this->send(200, 'Settings', $body);
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function saveSettings(array $raw): void
    {
        $input = $this->settingsInput();
        [$newRaw, $errors] = $this->installer()->buildConfig($input, $raw);
        if ($input['admin_password'] !== '' && !password_verify((string) ($_POST['current_password'] ?? ''), (string) $raw['admin']['password_hash'])) {
            $errors[] = 'The current admin password is wrong.';
        }
        if ($errors === []) {
            try {
                $this->installer()->install($newRaw, $this->ddlInput(), (bool) $input['verify_airlines'], null);
                $this->redirect('?p=settings', 'ok', 'Settings saved.');

                return;
            } catch (VamsysMcpException $e) {
                $errors[] = $e->getMessage();
            }
        }
        $this->settingsPage($raw, $input, $errors);
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function usersPage(array $raw): void
    {
        $config = Config::fromArray($raw);
        $pdo = $config->createPdo();
        if ((new Migrator($this->paths->migrationsDir()))->pending($pdo) !== []) {
            $this->send(200, 'MCP users', '<div class="flash warn">The database needs new tables first. Open <a href="?p=settings">Settings</a>, enter a DB admin login at the bottom and save.</div>');

            return;
        }
        $csrf = $this->html->csrfField();
        $button = static fn (string $action, int|string $id, string $label, string $class = 'secondary', string $idField = 'id'): string => '<form method="post" action="?a=' . $action . '" class="inline">' . $csrf
            . '<input type="hidden" name="' . $idField . '" value="' . Html::e($id) . '"><button class="' . $class . '">' . Html::e($label) . '</button></form> ';

        $body = '<p>MCP users sign in on this server\'s OAuth page when someone connects Claude (or another MCP client). They get read-only access to the vAMSYS data, not to this admin page.</p>'
            . '<table><tr><th>User</th><th>Status</th><th>Last sign-in</th><th></th></tr>';
        foreach ((new McpUsers($pdo))->all() as $u) {
            $body .= '<tr><td>' . Html::e($u['username']) . '</td><td>' . ($u['disabled'] ? 'disabled' : 'active') . '</td><td>' . Html::e($u['last_login_at'] ?? 'never') . '</td><td>'
                . ($u['disabled'] ? $button('user-enable', $u['id'], 'Enable') : $button('user-disable', $u['id'], 'Disable'))
                . $button('user-delete', $u['id'], 'Delete', 'danger')
                . '<form method="post" action="?a=user-password" class="inline">' . $csrf . '<input type="hidden" name="id" value="' . $u['id'] . '">'
                . '<input type="password" name="password" placeholder="new password" autocomplete="new-password"> <button class="secondary">Set password</button></form>'
                . '</td></tr>';
        }
        $body .= '</table><form method="post" action="?a=user-add"><fieldset><legend>Add MCP user</legend>' . $csrf . '<div class="grid">'
            . $this->html->input('User name', 'username') . $this->html->input('Password (at least ' . McpUsers::MIN_PASSWORD_LENGTH . ' characters)', 'password', '', 'password')
            . '</div><button>Add user</button></fieldset></form>';

        $body .= '<h3>Active connections</h3><table><tr><th>Client</th><th>User</th><th>Connected</th><th>Last token refresh</th><th></th></tr>';
        foreach (App::oauth($config, $pdo)->activeGrants() as $g) {
            $body .= '<tr><td>' . Html::e($g['client_name'] ?? '?') . '</td><td>' . Html::e($g['username'] ?? '?') . '</td><td>' . Html::e($g['connected_at']) . '</td><td>'
                . Html::e($g['last_refresh']) . '</td><td>' . $button('grant-revoke', (string) $g['grant_id'], 'Revoke', 'danger', 'grant') . '</td></tr>';
        }
        $body .= '</table><p class="help">Times are UTC. Revoking ends the connection immediately; the client has to sign in again.</p>'
            . $this->connectHelp($config->baseUrl() . '/mcp');
        $this->send(200, 'MCP users', $body);
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function userAction(array $raw, string $action): void
    {
        $config = Config::fromArray($raw);
        $pdo = $config->createPdo();
        $users = new McpUsers($pdo);
        $id = (int) ($_POST['id'] ?? 0);
        $password = (string) ($_POST['password'] ?? '');
        switch ($action) {
            case 'user-add':
                $users->create((string) ($_POST['username'] ?? ''), $password);
                $message = 'User added.';
                break;
            case 'user-disable':
                $users->setDisabled($id, true);
                $message = 'User disabled; their connections were revoked.';
                break;
            case 'user-enable':
                $users->setDisabled($id, false);
                $message = 'User enabled.';
                break;
            case 'user-password':
                $users->setPassword($id, $password);
                $message = 'Password changed.';
                break;
            case 'user-delete':
                $users->delete($id);
                $message = 'User deleted; their connections were revoked.';
                break;
            default:
                App::oauth($config, $pdo)->revokeGrant((string) ($_POST['grant'] ?? ''));
                $message = 'Connection revoked.';
        }
        $this->redirect('?p=users', 'ok', $message);
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function ftpPage(array $raw): void
    {
        $stored = $this->storedFtp($raw);
        $direct = LocalFileWriter::canWrite($this->paths->root, ['src/App.php', 'vendor/autoload.php', 'public/index.php']);
        $body = '<p>' . ($direct
            ? 'PHP can write the installation files itself, so updates do not need FTP.'
            : 'PHP <strong>cannot</strong> write the installation files itself, so updates need FTP.') . '</p>'
            . '<p class="help">FTP credentials are stored encrypted in the database; the key is in config.php. Neither alone reveals them.</p>'
            . '<form method="post" action="?a=ftp-save">' . $this->html->csrfField()
            . $this->ftpFieldset($stored, $stored !== null ? 'Saved. Leave the password empty to keep it.' : '')
            . '<button>Save</button> <button class="secondary" formaction="?a=ftp-test">Test and save</button></form>';
        if ($stored !== null) {
            $body .= '<form method="post" action="?a=ftp-delete">' . $this->html->csrfField() . '<p><button class="danger">Delete saved FTP credentials</button></p></form>';
        }
        $this->send(200, 'FTP for updates', $body);
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function saveFtp(array $raw, bool $test): void
    {
        $ftp = FtpCredentials::fromInput((array) ($_POST['ftp'] ?? []), $this->storedFtp($raw));
        if ($test) {
            $writer = new FtpFileWriter($ftp);
            try {
                $writer->probe($this->paths->root);
            } finally {
                $writer->close();
            }
        }
        $this->settings($raw)->put(EncryptedSettings::FTP, $ftp->toArray());
        $this->redirect('?p=ftp', 'ok', $test ? 'FTP works and points at this installation. Saved.' : 'FTP credentials saved (encrypted).');
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function deleteFtp(array $raw): void
    {
        $this->settings($raw)->delete(EncryptedSettings::FTP);
        $this->redirect('?p=ftp', 'ok', 'FTP credentials deleted.');
    }

    // ---------------------------------------------------------------- updates

    /**
     * @param array<string, mixed> $raw
     */
    private function updatePage(array $raw): void
    {
        $updater = $this->updater($raw);
        $flashes = [];
        if (($finished = $updater->finishedState()) !== null) {
            $flashes[] = $finished['phase'] === 'done'
                ? ['ok', "Updated from {$finished['from_version']} to {$finished['to_version']}."]
                : ['warn', "The update to {$finished['to_version']} was rolled back or cancelled; version {$finished['from_version']} is active."];
            $updater->cleanup();
        }
        $state = UpdateJob::readState($this->paths);
        $active = $state !== null && in_array($state['phase'] ?? '', UpdateJob::ACTIVE_PHASES, true);
        $release = Session::get('release');
        $blocker = $updater->blocker();

        $body = '<p>Installed version: <strong>' . Html::e(App::VERSION) . '</strong>. Updates come from GitHub releases of <code>'
            . Html::e($raw['update']['repository'] ?? InstallService::DEFAULT_REPOSITORY) . '</code>, are signature-checked, and never touch config.php, var/ or your database data.</p>';
        if ($active) {
            $body .= '<div class="flash warn">An update to ' . Html::e($state['to_version']) . ' is in progress (' . Html::e($state['phase']) . ').</div>';
        } elseif ($blocker !== null) {
            $body .= '<div class="flash warn">' . Html::e($blocker) . '</div>';
        }
        $body .= '<form method="post" action="?a=update-check">' . $this->html->csrfField() . '<p><button class="secondary">Check for updates</button></p></form>';

        if (is_array($release) && !$active) {
            $body .= '<h3>Latest release: ' . Html::e($release['version']) . '</h3><pre>' . Html::e($release['notes'] ?: '(no release notes)') . '</pre>';
            if (!version_compare($release['version'], App::VERSION, '>')) {
                $body .= '<p>You are up to date.</p>';
            } elseif ($blocker === null) {
                $body .= '<p>A backup of every replaced file is kept in var/update/. If PHP cannot write the files, you will be asked for FTP (or the saved FTP login is used); '
                    . 'if the database changes, you will be asked for a DB admin login (not stored).</p>';
            }
        }

        $canStart = !$active && $blocker === null && is_array($release) && version_compare($release['version'], App::VERSION, '>');
        $body .= $this->updateConsole($active, $canStart);
        $this->send(200, 'Updates', $body, $flashes);
    }

    private function updateConsole(bool $active, bool $canStart): string
    {
        if (!$active && !$canStart) {
            return '';
        }
        $csrf = Html::e(Session::csrf());
        $start = $canStart ? '<button data-update-action="update-prepare">Install update</button>' : '<button data-update-action="update-step">Continue update</button>';

        return <<<HTML
            <div id="update-box" data-csrf="{$csrf}">
              <p>{$start}</p>
              <p id="update-status"></p><progress id="update-progress" class="hidden" value="0" max="1"></progress>
              <p id="update-error" class="flash error hidden"></p>
              <p id="update-actions" class="hidden">
                <button class="secondary" data-update-action="update-retry">Retry</button>
                <button class="danger hidden" id="btn-rollback" data-update-action="update-rollback">Roll back</button>
                <button class="secondary hidden" id="btn-cancel" data-update-action="update-cancel">Cancel update</button>
              </p>
              <form id="need-db" class="hidden"><fieldset><legend>Database admin login</legend>
                <p class="help">This update changes the database. Enter a DB user allowed to CREATE/ALTER tables. It is used once and not stored.</p>
                <div class="grid"><label><span>DB user</span><input type="text" name="ddl_user"></label><label><span>Password</span><input type="password" name="ddl_password" autocomplete="off"></label></div>
                <button>Continue</button></fieldset></form>
              <form id="need-ftp" class="hidden"><fieldset><legend>FTP login</legend>
                <p class="help">PHP cannot write the installation files, so the update needs FTP. Tip: save it under FTP to skip this next time.</p>
                <div class="grid"><label><span>Host</span><input type="text" name="ftp[host]"></label><label><span>Port</span><input type="number" name="ftp[port]" value="21"></label>
                <label><span>User</span><input type="text" name="ftp[username]"></label><label><span>Password</span><input type="password" name="ftp[password]" autocomplete="off"></label></div>
                <label><span>FTP path of the installation (folder with src/ and vendor/)</span><input type="text" name="ftp[path]" placeholder="/vamsys-mcp"></label>
                <label class="check"><input type="checkbox" name="ftp[tls]" value="1" checked> Use TLS (FTPS)</label>
                <label class="check"><input type="checkbox" name="ftp_save" value="1"> Save encrypted for future updates</label>
                <button>Continue</button></fieldset></form>
            </div>
            <script nonce="{$this->html->nonce}">
            (() => {
              const box = document.getElementById('update-box'), extra = new Map();
              const $ = (id) => document.getElementById(id);
              async function post(action) {
                const fd = new FormData(); fd.append('csrf', box.dataset.csrf);
                for (const [k, v] of extra) fd.append(k, v);
                try { const r = await fetch('?a=' + action, {method: 'POST', body: fd, credentials: 'same-origin'}); return await r.json(); }
                catch (e) { return {phase: 'failed', error: 'Lost connection to the server (' + e + '). Reload the page to resume.'}; }
              }
              function show(s) {
                const p = s.progress || {done: 0, total: 0};
                $('update-status').textContent = 'Phase: ' + s.phase + (s.to_version ? ' (to ' + s.to_version + ')' : '') + (p.total ? ' · ' + p.done + ' / ' + p.total + ' files' : '');
                $('update-progress').classList.toggle('hidden', !p.total); $('update-progress').max = p.total || 1; $('update-progress').value = p.done;
                const failed = s.phase === 'failed';
                $('update-error').classList.toggle('hidden', !failed); $('update-error').textContent = s.error || '';
                $('update-actions').classList.toggle('hidden', !failed);
                $('btn-rollback').classList.toggle('hidden', !s.can_roll_back); $('btn-cancel').classList.toggle('hidden', !failed || s.can_roll_back);
              }
              async function loop(action) {
                document.querySelectorAll('[data-update-action]').forEach((b) => b.disabled = true);
                let s = await post(action);
                while (true) {
                  show(s);
                  if (s.needs === 'db_credentials') { $('need-db').classList.remove('hidden'); break; }
                  if (s.needs === 'ftp_credentials') { $('need-ftp').classList.remove('hidden'); break; }
                  if (s.finished) { location.href = '?p=update'; return; }
                  if (s.phase === 'failed') break;
                  s = await post('update-step');
                }
                document.querySelectorAll('[data-update-action]').forEach((b) => b.disabled = false);
              }
              document.querySelectorAll('[data-update-action]').forEach((b) => b.addEventListener('click', (e) => { e.preventDefault(); loop(b.dataset.updateAction); }));
              for (const id of ['need-db', 'need-ftp']) {
                $(id).addEventListener('submit', (e) => {
                  e.preventDefault();
                  for (const [k, v] of new FormData(e.target)) extra.set(k, v);
                  e.target.classList.add('hidden'); loop('update-step');
                });
              }
            })();
            </script>
            HTML;
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function checkUpdate(array $raw): void
    {
        $result = $this->updater($raw)->check();
        Session::set('release', $result['release']);
        $this->redirect('?p=update');
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function prepareUpdate(array $raw): void
    {
        $release = Session::get('release');
        if (!is_array($release)) {
            throw new \VamsysMcp\Update\UpdateException('Check for updates first.');
        }
        $this->updater($raw)->prepare($release);
        $this->json(['phase' => 'extracted', 'to_version' => $release['version'], 'progress' => ['done' => 0, 'total' => 0]]);
    }

    /**
     * Runs with the staged (new) code while an update is active; see public/setup.php.
     *
     * @param array<string, mixed> $raw
     */
    private function updateStep(array $raw, string $action): void
    {
        $job = new UpdateJob($this->paths);
        match ($action) {
            'update-rollback' => $job->startRollback(),
            'update-retry' => $job->retry(),
            'update-cancel' => $job->cancel(),
            default => null,
        };
        if ($action === 'update-cancel') {
            $this->json($job->status());

            return;
        }

        $config = Config::fromArray($raw);
        $ftp = null;
        if (trim((string) ($_POST['ftp']['host'] ?? '')) !== '') {
            $ftp = FtpCredentials::fromInput((array) $_POST['ftp']);
            if (($_POST['ftp_save'] ?? '') === '1') {
                $this->settings($raw)->put(EncryptedSettings::FTP, $ftp->toArray());
            }
        }
        $ftp ??= $this->storedFtp($raw);
        $ddl = trim((string) ($_POST['ddl_user'] ?? '')) !== ''
            ? ['user' => trim((string) $_POST['ddl_user']), 'password' => (string) ($_POST['ddl_password'] ?? '')]
            : null;
        @set_time_limit(60);

        $this->json($job->step(new StepContext(static fn (): \PDO => $config->createPdo(), $config->dbDsn, $ftp, $ddl)));
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @return array<string, mixed>
     */
    private function settingsInput(): array
    {
        return [
            'db' => (array) ($_POST['db'] ?? []),
            'airlines' => array_values((array) ($_POST['airlines'] ?? [])),
            'allowed_hosts' => (string) ($_POST['allowed_hosts'] ?? ''),
            'audit_log' => ($_POST['audit_log'] ?? '0') === '1',
            'verify_airlines' => ($_POST['verify_airlines'] ?? '0') === '1',
            'admin_password' => (string) ($_POST['admin_password'] ?? ''),
            'admin_password_confirm' => (string) ($_POST['admin_password_confirm'] ?? ''),
        ];
    }

    /**
     * @return array{user: string, password: string}|null
     */
    private function ddlInput(): ?array
    {
        $user = trim((string) ($_POST['ddl']['user'] ?? ''));

        return $user === '' ? null : ['user' => $user, 'password' => (string) ($_POST['ddl']['password'] ?? '')];
    }

    /**
     * @param array<string, mixed> $db
     */
    private function databaseFieldset(array $db, bool $install): string
    {
        return '<fieldset><legend>Database (MariaDB / MySQL)</legend>'
            . ($install ? '<p class="help">Create an empty database and a user with SELECT, INSERT, UPDATE, DELETE on it in your hosting panel first.</p>' : '')
            . '<div class="grid">' . $this->html->input('Host', 'db[host]', $db['host'] ?? 'localhost')
            . $this->html->input('Port', 'db[port]', $db['port'] ?? 3306, 'number')
            . $this->html->input('Database name', 'db[name]', $db['name'] ?? '')
            . $this->html->input('User', 'db[user]', $db['user'] ?? '')
            . $this->html->input('Password', 'db[password]', '', 'password', $install ? '' : 'Leave empty to keep the current password.') . '</div></fieldset>';
    }

    private function ddlFieldset(string $help = 'Used once to create the tables; not stored. Leave empty if the user above may CREATE tables.'): string
    {
        return '<fieldset><legend>Database admin login (optional)</legend><p class="help">' . Html::e($help) . '</p><div class="grid">'
            . $this->html->input('DB admin user', 'ddl[user]') . $this->html->input('Password', 'ddl[password]', '', 'password') . '</div></fieldset>';
    }

    /**
     * @param list<array<string, mixed>> $airlines
     * @param list<string>               $existingKeys keys whose secret may be left empty
     */
    private function airlinesFieldset(array $airlines, array $existingKeys): string
    {
        $html = '<fieldset id="airlines"><legend>Airlines</legend><p class="help">vAMSYS → Airline settings → API: create an Operations API OAuth client for each airline.</p>';
        foreach (array_values($airlines) as $i => $a) {
            $html .= $this->airlineBlock((string) $i, (array) $a, in_array($a['key'] ?? null, $existingKeys, true));
        }

        return $html . '<p><button type="button" class="secondary" id="add-airline">Add airline</button></p>'
            . '<template id="airline-template">' . $this->airlineBlock('__I__', [], false) . '</template></fieldset>';
    }

    /**
     * @param array<string, mixed> $a
     */
    private function airlineBlock(string $i, array $a, bool $existing): string
    {
        $n = static fn (string $f): string => "airlines[{$i}][{$f}]";

        return '<fieldset><legend>' . Html::e($a['key'] ?? 'New airline') . '</legend><div class="grid">'
            . $this->html->input('Key (short, e.g. swv)', $n('key'), $a['key'] ?? '', 'text', '', $existing ? ' readonly' : '')
            . $this->html->input('Name', $n('name'), $a['name'] ?? '')
            . $this->html->input('OAuth client ID', $n('client_id'), $a['client_id'] ?? '')
            . $this->html->input('OAuth client secret', $n('client_secret'), '', 'password', $existing ? 'Leave empty to keep the current secret.' : '')
            . $this->html->select('Weight unit (for /routes)', $n('weight_unit'), ['' => 'choose…', 'kg' => 'kg', 'lbs' => 'lbs'], (string) ($a['weight_unit'] ?? ''))
            . $this->html->input('Rate cap (requests/min, max 100)', $n('rate_cap'), $a['rate_cap'] ?? 60, 'number', 'The vAMSYS limit of 100/min is shared with all your other API clients.')
            . '</div>'
            . $this->html->checkbox('Enable the Pilots API (personal data; off by default)', $n('pilots_api'), (bool) ($a['pilots_api'] ?? false))
            . ($existing ? $this->html->checkbox('Remove this airline', $n('delete'), false) : '')
            . '</fieldset>';
    }

    private function airlineTemplateScript(): string
    {
        return '<script nonce="' . Html::e($this->html->nonce) . '">document.getElementById("add-airline").addEventListener("click",()=>{'
            . 'const t=document.getElementById("airline-template"),i=document.querySelectorAll("#airlines > fieldset").length+Date.now();'
            . 'const d=document.createElement("div");d.innerHTML=t.innerHTML.replaceAll("__I__",String(i));t.before(d.firstElementChild);});</script>';
    }

    /**
     * @param array<string, mixed> $values
     */
    private function endpointFieldset(array $values): string
    {
        return '<fieldset><legend>MCP endpoint</legend>'
            . $this->html->input('Host name(s) of the endpoint', 'allowed_hosts', $values['allowed_hosts'] ?? '', 'text', 'Comma-separated, e.g. mcp.your-va.com. Requests for other hosts are rejected.')
            . $this->html->checkbox('Audit log: record every vAMSYS call (path and status only)', 'audit_log', (bool) ($values['audit_log'] ?? false))
            . $this->html->checkbox('Verify the vAMSYS credentials now (one token request per airline)', 'verify_airlines', (bool) ($values['verify_airlines'] ?? false))
            . '</fieldset>';
    }

    private function ftpFieldset(?FtpCredentials $ftp, string $help): string
    {
        return '<fieldset><legend>FTP login for updates</legend>' . ($help !== '' ? '<p class="help">' . Html::e($help) . '</p>' : '')
            . '<div class="grid">' . $this->html->input('Host', 'ftp[host]', $ftp?->host ?? '')
            . $this->html->input('Port', 'ftp[port]', $ftp?->port ?? 21, 'number')
            . $this->html->input('User', 'ftp[username]', $ftp?->username ?? '')
            . $this->html->input('Password', 'ftp[password]', '', 'password') . '</div>'
            . $this->html->input('FTP path of the installation', 'ftp[path]', $ftp?->path ?? '', 'text', 'The folder containing src/ and vendor/, as the FTP server shows it, e.g. /vamsys-mcp.')
            . $this->html->checkbox('Use TLS (FTPS)', 'ftp[tls]', $ftp?->tls ?? true) . '</fieldset>';
    }

    /**
     * @param list<string> $errors
     */
    private function errorList(array $errors): string
    {
        return implode('', array_map(static fn (string $e): string => '<div class="flash error">' . Html::e($e) . '</div>', $errors));
    }

    private function extensionSummary(): string
    {
        $out = [];
        foreach (['pdo_mysql' => 'required', 'sodium' => 'required', 'curl' => 'recommended', 'zip' => 'updates', 'ftp' => 'updates over FTP'] as $ext => $why) {
            $out[] = $ext . (extension_loaded($ext) ? ' ✓' : " ✗ ({$why})");
        }

        return implode(', ', $out);
    }

    private function installer(): InstallService
    {
        return new InstallService($this->paths, new Psr18Client(HttpClient::create(['max_redirects' => 0, 'timeout' => 20])));
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function updater(array $raw): Updater
    {
        $psr17 = new Psr17Factory();
        $client = new Psr18Client(HttpClient::create(['max_redirects' => 5, 'timeout' => 120]), $psr17, $psr17);

        return new Updater($this->paths, App::VERSION, new GitHubReleases($client, $psr17, (string) ($raw['update']['repository'] ?? InstallService::DEFAULT_REPOSITORY)));
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function settings(array $raw): EncryptedSettings
    {
        return new EncryptedSettings(Config::fromArray($raw)->createPdo(), SecretBox::fromBase64((string) ($raw['app_key'] ?? '')));
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function storedFtp(array $raw): ?FtpCredentials
    {
        $data = $this->settings($raw)->get(EncryptedSettings::FTP);

        return $data !== null ? FtpCredentials::fromArray($data) : null;
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function throttle(array $raw): ?LoginThrottle
    {
        try {
            return new LoginThrottle(Config::fromArray($raw)->createPdo());
        } catch (\Throwable) {
            return null; // DB down: fall back to a fixed delay per failed attempt
        }
    }

    private function isHttps(): bool
    {
        return (($_SERVER['HTTPS'] ?? '') !== '' && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
            || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
    }

    private function isLocal(): bool
    {
        return in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)
            && in_array($this->requestHost(), ['localhost', '127.0.0.1', '[::1]'], true);
    }

    private function requestHost(): string
    {
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));

        return preg_replace('/:\d+$/', '', $host) ?? $host;
    }

    /**
     * @param list<array{0: string, 1: string}> $extraFlashes
     */
    private function send(int $status, string $title, string $body, array $extraFlashes = []): void
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        $flashes = session_status() === PHP_SESSION_ACTIVE ? Session::takeFlashes() : [];
        echo $this->html->page($title, $body, [...$flashes, ...$extraFlashes], session_status() === PHP_SESSION_ACTIVE && Session::isAdmin());
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data);
    }

    private function redirect(string $to, string $flashType = '', string $message = ''): void
    {
        if ($message !== '') {
            Session::flash($flashType, $message);
        }
        header('Location: ' . ($to === '' ? '?' : $to), true, 303);
    }
}
