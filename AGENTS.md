# AGENTS.md

Instructions for coding agents (and humans) working on this repository.

## Project purpose

A self-hosted **MCP server in PHP** that sits in front of the **vAMSYS Operations API**, so MCP clients (Claude Code, Claude Desktop, claude.ai connectors, MCP Inspector) can answer questions about a virtual airline's data. It is **general-purpose for any vAMSYS virtual airline** and supports several airlines in one install. Do not add code, defaults or wording specific to one airline. It is unofficial and not affiliated with vAMSYS.

## Ground rules

- **Do not commit or push unless the user explicitly asks.**
- **v1 is read-only.** No code path may send POST, PUT, PATCH or DELETE to the vAMSYS API. `VamsysClient` deliberately implements only `get()`; do not add a generic `request()` method or any write method. (The only POST this project sends is the OAuth token request to `/oauth/token`, which is not an API call.)
- **The Pilots API is disabled by default.** Any path with a `pilots` or `pilot` segment (`/pilots…`, `/hubs/{id}/pilots`, `/ranks/{id}/pilots`, `/badges/{id}/pilots`, `/statistics/pilot/{id}`) must be unreachable unless the operator sets `'pilots_api' => true` for that airline. This is enforced in `VamsysClient::assertPathAllowed()`. Never loosen it and never default it to `true`.
- **The source of truth for endpoints and fields is `docs/vamsys-openapi.json`** (OpenAPI 3.1). Read it before adding or changing a tool. Do not guess field names.
- **`mcp/sdk` is pre-1.0.** Check its docs (php.sdk.modelcontextprotocol.io, github.com/modelcontextprotocol/php-sdk) or the code in `vendor/mcp/sdk/src` before using an API. Do not rely on memory.

## vAMSYS API rules (hard rules: the code must enforce all of them)

1. **Token request is form-urlencoded.** Build the body of the OAuth client-credentials request with `http_build_query()` and `Content-Type: application/x-www-form-urlencoded` (`grant_type=client_credentials`, `client_id`, `client_secret`, `scope=*`). A JSON body causes intermittent HTTP 500 errors. (`TokenStore`)
2. **Every API request sends both `Authorization: Bearer <token>` and `Accept: application/json`.** Without `Accept`, vAMSYS answers with HTML redirect pages **with HTTP 200**, so every response must be checked explicitly: a non-JSON `Content-Type` or an undecodable body is an error, whatever the status code. Redirects are not followed. (`VamsysClient::decode()`)
3. **Tokens are cached per airline in the DB** (`oauth_token`) and refreshed shortly before they expire (`token_refresh_margin_seconds`, default 300). The lifetime comes from `expires_in` in each token response. Never hard-code it: the spec says 24 h (86400), operators have seen 7 days (604800). After an HTTP 401 the cached token is dropped and the request is retried once.
4. **Rate limit: 100 requests/min per airline, shared with every other OAuth client of that airline** (the operator's other scripts, bots, etc.). `RateLimiter` keeps a DB-backed fixed one-minute window per airline, updated atomically with `INSERT … ON DUPLICATE KEY UPDATE` inside a transaction. The cap is configurable per airline (`rate_cap`, default 60, max 100). Every outgoing request, including token requests, must call `RateLimiter::acquire()` first. When the cap is reached it waits for the next window (up to `max_rate_wait_seconds`) or throws a clear `RateLimitExceededException`. Never send the request anyway. HTTP 429 from vAMSYS is reported the same way.
5. **Pagination is cursor-based only** (`page[size]`, `page[cursor]`, `meta.next_cursor`). Pages of one endpoint must be fetched **sequentially**, never in parallel. Use `VamsysClient::paginate()`, which also caps pages per call (`max_pages_per_call`).
6. **`/routes` weight unit.** Every call to `/routes` and `/routes/{id}` must pass `weight_unit` as a **URL query parameter** (never in a body), with the value from the airline's config (`kg` or `lbs`). Calls must never go out with mixed units: `VamsysClient` always sets it and rejects a conflicting value.
7. **Tags are case-sensitive.** Pass them through unchanged; never lower- or upper-case tags.
8. **`departure_time` / `arrival_time` null semantics (matters for future write tools).** In a route update, an explicit `null` **clears** the value, while **omitting** the field **keeps** it. Write tools must never send `null` for a field they did not mean to clear; build payloads from only the fields being changed.

## Architecture

```
bin/server-stdio.php      STDIO entry point (local clients, Inspector)
bin/install.php           interactive CLI installer / reconfiguration (same logic as the web installer)
bin/update.php            CLI updater (same logic as the web updater)
bin/mcp-user.php          manage MCP users (who may sign in when connecting a client)
bin/build-release.php, bin/sign-release.php, bin/release-keygen.php   release tooling (CI)
public/index.php          Streamable HTTP entry point (PHP-FPM, one request per run); 503 while var/maintenance exists
public/setup.php          web installer + admin UI; loads the staged code while an update is active
public/.htaccess          passes Authorization through Apache/FPM, routes to index.php
config/config.example.php documented config; real config/config.php is git-ignored
migrations/NNNN_*.sql     database migrations (source of truth for the schema)
schema.sql                all migrations concatenated, for manual setups (a test keeps it in sync)
docs/vamsys-openapi.json  vAMSYS Operations API spec (source of truth)
.github/workflows/release.yml   on tag vX.Y.Z: test, build zip with vendor/, sign, publish GitHub release
src/
  App.php                 wiring: Config → PDO → RateLimiter/TokenStore → VamsysClient → tools → MCP Server builder
  Config.php, AirlineConfig.php   config loading + validation (secrets hidden from var_dump/serialise)
  RateLimiter.php         DB fixed-window limiter per airline
  TokenStore.php          OAuth token fetch + DB cache
  VamsysClient.php        read-only GET client; enforces the API rules above
  CallLog.php             optional audit log (path + status only)
  OAuth/                  built-in OAuth 2.1 authorization server: OAuthService (codes, tokens, PKCE, rotation),
                          ClientRegistry (DCR + Client ID Metadata Documents), ClientMetadataFetcher (SSRF-safe),
                          RedirectPolicy, McpUsers, TokenValidator (plugs into the SDK's AuthorizationMiddleware), OAuthApp (HTTP)
  Tools/                  one class per MCP tool (+ ToolResult for compact output)
  Exception/              VamsysMcpException and subclasses: messages are safe to show to clients
  Install/                ConfigFile (writes config.php), InstallService, Migrator, SecretBox + EncryptedSettings, OwnershipProof, Console
  Update/                 Updater (check/download/verify/stage), UpdateJob (resumable apply), ReleasePackage, ManagedFiles, Local/FtpFileWriter
  Admin/                  web admin (AdminApp controller, Session/CSRF, LoginThrottle, Html)
tests/                    PHPUnit; SQLite DB, fake PSR-18 HTTP client, fake clock, temp dirs
```

HTTP transport: `Server::builder()->build()` serves both protocol eras. Clients on `2026-07-28` are stateless. Older handshake clients get sessions in `FileSessionStore` + `FileSessionLock` (directory outside the web root). Middleware: `CorsMiddleware` (no browser origins), `DnsRebindingProtectionMiddleware` (`http.allowed_hosts`), and the SDK's `AuthorizationMiddleware` with our `TokenValidator`.

Routing in `public/index.php`: `/mcp` (recommended) and `/` serve MCP; `/.well-known/oauth-authorization-server`, `/.well-known/oauth-protected-resource[/mcp]` and `/oauth/{authorize,token,register}` go to `OAuthApp`; everything else is 404. The site must be served at the root of its (sub)domain.

## Designing tools

- Design tools around **questions people ask**, not a 1:1 mirror of endpoints.
- **Keep outputs small**: compact JSON (`ToolResult::of()`), only the fields needed, empty fields dropped, a `limit` with `has_more`, never full collections.
- Filter server-side where vAMSYS supports it (`filter[...]`), otherwise while paging, and stop paging once the answer is known.
- Annotate every v1 tool with `readOnlyHint: true`.
- Errors the user/LLM should see: throw `Mcp\Exception\ToolCallException` (its message reaches the client). Convert `VamsysMcpException` to `ToolCallException` in the tool. Any other exception becomes a generic error.

## Secrets handling

- vAMSYS client secrets, the DB password, the admin password hash, HTTP token hashes and `app_key` live **only** in `config/config.php` (outside the web root, `chmod 600`, git-ignored) or in environment variables it reads. Never in plaintext in the database, never in the repo.
- The only secret in the database is the updater's FTP login, stored **encrypted** (libsodium secretbox, `EncryptedSettings`) with `app_key` from config.php. Key and ciphertext are deliberately split across file and DB. Do not add other secrets to the DB without the same scheme.
- The DB admin login used for migrations is asked for when needed and **never stored**.
- The DB otherwise stores only cached access tokens, rate-window rows, login-throttle rows and the optional call log.
- Never put tokens, secrets, request headers, token-response bodies or DSNs into exception messages, logs, or tool output. `CallLog` records the path only (no query string). `public/index.php` logs only the exception class.
- MCP users' passwords are stored with `password_hash()`. OAuth codes, access and refresh tokens are random 256-bit strings stored only as SHA-256 hashes.

## OAuth (hard rules)

Since 0.2.0 the HTTP endpoint is protected **only** by OAuth; there are no static tokens.

- **Requirements Claude enforces.** Do not regress any of them:
  - a `401` with `WWW-Authenticate: Bearer resource_metadata="…"`;
  - protected-resource metadata whose `resource` equals the URL the user enters (`/mcp`, or the root);
  - authorization-server metadata advertising `code_challenge_methods_supported: ["S256"]`, `token_endpoint_auth_methods_supported: ["none"]` and `client_id_metadata_document_supported: true`, plus a `registration_endpoint`;
  - a form-urlencoded `/oauth/token`;
  - RFC 6749 error codes (`invalid_grant` for bad refresh tokens);
  - rotating refresh tokens;
  - responses within 10 s.
- **PKCE S256 is mandatory; public clients only.** Never add `plain`, implicit or password grants, or client secrets.
- **Redirects:**
  - Only to hosts in `oauth.allowed_redirect_hosts` (default `claude.ai`, `localhost`, `127.0.0.1`).
  - Loopback URIs match on any port.
  - Errors about the client or the redirect URI are shown on the page, never redirected.
  - The consent page shows the redirect host.
  - Its CSP `form-action` allows exactly that request's redirect origin.
- **Client ID Metadata Documents are fetched SSRF-safely:** https only, no redirects, 64 KB cap, public IPs only, and the connection is pinned to the checked IP. Keep it that way.
- **Refresh-token reuse revokes the whole grant**, and so does a replayed authorization code. Disabling or deleting an MCP user revokes their tokens.
- **MCP users are not admins.** The OAuth sign-in never accepts the admin password, and MCP users never get access to `setup.php`.

## Installer, admin UI and updates (hard rules)

- **Settings and credentials must survive updates.** The updater writes and deletes only files listed in `ManagedFiles` (src/, vendor/, bin/, public/, migrations/, docs/ and a few root files). It never touches `config/config.php`, `var/`, or anything else in the installation directory. If you add a new top-level path to releases, add it to `ManagedFiles`.
- **No breaking changes in minor/patch releases:**
  - New config keys must have defaults in `Config::fromArray()`, so an old config keeps working.
  - `ConfigFile`/`InstallService::buildConfig()` keep unknown keys.
  - Migrations must be **additive and idempotent** (`CREATE TABLE IF NOT EXISTS`, new nullable columns, no drops or renames), because they run *before* files are replaced and the old code must keep working on the new schema. Migrations are never rolled back.
  - Add every new migration to `schema.sql` too; `InstallTest` checks it.
  - If an update cannot be applied in place, raise `App::UPDATE_FROM_MIN` or bump the major version. The updater then refuses and the release notes must explain the manual steps.
- **Self-update contract.** While `var/update/state.json` is in an active phase, `public/setup.php` and `bin/update.php --continue` run the *staged new* code from `var/update/staging`. The old version only downloads, verifies and extracts, then writes `{contract: 1, phase: "extracted", from_version, to_version, staging}`. Keep these keys and `UpdateJob::CONTRACT` stable, and keep `public/setup.php` self-contained (no project classes before the autoloader is chosen).
- **Releases are signed.**
  - The updater installs only zips whose Ed25519 signature matches `ReleaseKey::PUBLIC_KEY`. Never add a way to skip that check.
  - The secret key exists only as the GitHub Actions secret `RELEASE_SIGNING_KEY`.
  - Release flow: bump `App::VERSION`, commit, tag `vX.Y.Z`, push the tag.
- **Web admin security:**
  - HTTPS only (localhost excepted).
  - CSRF token on every POST; `SameSite=Strict`, `HttpOnly` session cookie stored in `var/admin-sessions`.
  - CSP with a per-request nonce (no inline handlers).
  - Every dynamic value is escaped with `Html::e()`.
  - Failed logins are throttled.
  - The installer and the password reset are gated by `OwnershipProof`: the operator creates `vamsys-setup-<code>.txt` in the installation folder over FTP. Do not rely on the operator reading files PHP created: on many shared hosts PHP runs as a different user, and the FTP account cannot see them.
  - Secrets are never echoed back into forms; an empty secret field means "keep the current one".

## Coding conventions

- PHP 8.4, `declare(strict_types=1);` in every file, PSR-4 under `VamsysMcp\` → `src/`.
- Small `final` classes with constructor injection; readonly properties; typed constants.
- Use PSR interfaces (PSR-18 client, PSR-17 factories, PSR-20 clock) so everything can be tested without network.
- SQL must work on MariaDB 11 (production). The SQLite branches (`RateLimiter`, `TokenStore`, `EncryptedSettings`, `LoginThrottle`) exist only for the test suite.
- Error messages are clear, actionable sentences that name the airline/path/status.

## Running tests and the server locally

```bash
composer install
composer test                       # or: vendor/bin/phpunit
```

Tests never touch the network or a real database.

Local STDIO server (needs `config/config.php` and a reachable DB; set `VAMSYS_MCP_CONFIG` to use another config file):

```bash
npx @modelcontextprotocol/inspector php bin/server-stdio.php
```

The HTTP endpoint can be tried with `php -S localhost:8080 -t public` (add `localhost` to `http.allowed_hosts`).
