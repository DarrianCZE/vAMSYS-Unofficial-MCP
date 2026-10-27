<?php

declare(strict_types=1);

/*
 * Easiest: let the installer write config/config.php for you (open
 * https://your-host/setup.php, or run php bin/install.php). This file
 * documents every option for a hand-written config.
 *
 * By hand: copy to config/config.php (git-ignored) and fill in. Keep this directory
 * OUTSIDE the web root and readable only by your PHP user (chmod 600).
 * A hand-written config (without the installer's marker line) is never
 * overwritten by the installer or the web admin.
 *
 * Secrets can come from environment variables instead of being written here,
 * e.g. 'client_secret' => getenv('VAMSYS_SWV_CLIENT_SECRET') ?: ''.
 * Secrets are never stored in the database.
 */

return [
    'database' => [
        // MariaDB 11 / MySQL 8. The user needs only SELECT, INSERT, UPDATE, DELETE.
        'dsn' => 'mysql:host=localhost;dbname=vamsys_mcp;charset=utf8mb4',
        'username' => 'vamsys_mcp',
        'password' => getenv('VAMSYS_MCP_DB_PASSWORD') ?: 'change-me',
    ],

    'airlines' => [
        // The key ("swv") is what MCP clients pass as the airline argument.
        'swv' => [
            'name' => 'Example Virtual',
            'client_id' => '123',                       // vAMSYS Operations API OAuth client ID
            'client_secret' => getenv('VAMSYS_SWV_CLIENT_SECRET') ?: 'change-me',
            'weight_unit' => 'kg',                      // 'kg' or 'lbs'; sent on every /routes call
            'rate_cap' => 60,                           // requests/minute this server may use (vAMSYS limit: 100, shared by ALL your OAuth clients)
            'pilots_api' => false,                      // keep false unless you really need pilot data
        ],
    ],

    'vamsys' => [
        'api_base_url' => 'https://vamsys.io/api/v3/operations',
        'token_url' => 'https://vamsys.io/oauth/token',
        'page_size' => 100,                    // page[size] for paginated lists
        'max_pages_per_call' => 10,            // upper bound of pages one tool call may fetch
        'max_rate_wait_seconds' => 10,         // wait this long for the next rate window before failing; keep below PHP's max_execution_time
        'token_refresh_margin_seconds' => 300, // refresh cached tokens this long before they expire
        'http_timeout_seconds' => 20,
        'airport_cache_ttl' => 86400,          // seconds before the cached airport list (ID -> ICAO/IATA) is reloaded
        'route_cache_ttl' => 600,              // seconds a route set read for one question is reused for the next ones
        'scan_max_pages' => 100,               // most pages one full scan (routes or airports) reads per call
        'scan_time_budget_seconds' => 15,      // a route scan pauses after this long and continues on the next call; keep well below max_execution_time and MCP clients' ~25 s timeout
    ],

    // Log every outgoing vAMSYS call (airline, path, status, duration) to call_log.
    'audit_log' => false,

    // Only used by the HTTP endpoint (public/index.php).
    'http' => [
        // Host names this endpoint is served under (DNS-rebinding protection).
        'allowed_hosts' => ['mcp.example.com'],
        // Optional: public base URL if it is not https://<first allowed host> (no trailing slash).
        // 'public_url' => 'https://mcp.example.com',
        // Session files for clients on the older, handshake-based protocol. Must be outside the web root.
        'session_dir' => dirname(__DIR__) . '/var/sessions',
    ],

    // OAuth sign-in for MCP clients (Claude etc.). Users are managed under "MCP users" in the admin page.
    'oauth' => [
        // Hosts OAuth clients may be sent back to after sign-in. claude.ai = Claude web/Desktop/mobile;
        // localhost/127.0.0.1 = Claude Code and MCP Inspector. Add e.g. 'chatgpt.com' only if you use that client.
        'allowed_redirect_hosts' => ['claude.ai', 'localhost', '127.0.0.1'],
        'access_token_ttl' => 3600,          // seconds
        'refresh_token_ttl' => 30 * 86400,   // seconds; refresh tokens rotate on every use
    ],

    // Web admin at /setup.php (settings, MCP users, FTP, updates).
    'admin' => [
        // password_hash() of the admin password. Forgot it? Use "Forgot the password?" on the login page.
        'password_hash' => '',
        // false = setup.php answers 404 (manage everything via this file and bin/*.php).
        'web_enabled' => true,
    ],

    // Key for secrets stored encrypted in the database (FTP login for updates).
    // Generate: php -r "echo base64_encode(random_bytes(32));"   Changing it makes stored FTP credentials unreadable.
    'app_key' => '',

    // GitHub repository whose signed releases the updater installs.
    'update' => [
        'repository' => 'DarrianCZE/vAMSYS-Unofficial-MCP',
    ],
];
