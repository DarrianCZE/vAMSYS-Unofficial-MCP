# vAMSYS Unofficial MCP

A self-hosted [Model Context Protocol](https://modelcontextprotocol.io) server, written in PHP, for the **vAMSYS Operations API**. It lets AI assistants (Claude Code, Claude Desktop, claude.ai custom connectors, any MCP client) answer questions about your virtual airline's data, for example "which routes from LKPR have no departure time?". It handles OAuth token caching, rate limiting and multiple airlines for you.

It works for **any vAMSYS virtual airline**, and one install can serve several airlines.

> **Unofficial project.** This is not made, endorsed or supported by vAMSYS. "vAMSYS" is used only to describe what this software connects to. Use it at your own risk and within the vAMSYS terms of service.

**Version 1 is read-only.** It never creates, changes or deletes anything in vAMSYS.

## Contents

- [Requirements](#requirements)
- [Installation on shared hosting](#installation-on-shared-hosting)
- [Updating](#updating)
- [Configuration](#configuration)
- [Database](#database)
- [Connecting MCP clients](#connecting-mcp-clients)
- [Tools](#tools)
- [Security notes](#security-notes)
- [Testing locally](#testing-locally)
- [For maintainers: releases](#for-maintainers-releases)
- [Roadmap](#roadmap)
- [Open questions](#open-questions)

## Requirements

- **PHP 8.4** with the extensions `pdo_mysql`, `openssl`, `fileinfo`, `json` and `sodium` (`curl` recommended). For updates from the web admin you also need `zip`, and `ftp` if PHP can't write its own files.
- **MariaDB 11** (MySQL 8 also works)
- A vAMSYS **Operations API OAuth client** (client ID + secret) for each airline, created in your vAMSYS admin area
- **HTTPS** on a (sub)domain, e.g. `mcp.your-va.com`

Ordinary shared hosting with PHP-FPM is enough. No SSH, Composer, Node.js, Redis or long-running process is needed: release packages already include `vendor/`.

## Installation on shared hosting

1. **Create a database and a user** in your hosting panel. The user needs SELECT, INSERT, UPDATE and DELETE on that database.
2. **Download** `vamsys-mcp-<version>.zip` from the [latest release](https://github.com/DarrianCZE/vAMSYS-Unofficial-MCP/releases/latest). Upload its contents with FTP to a folder **outside the web root**, e.g. `~/vamsys-mcp/`.
3. **Point a (sub)domain at the `public/` folder**, e.g. document root `~/vamsys-mcp/public` for `mcp.your-va.com`. Only `public/` may be reachable from the web.
   - *If you can't choose the document root:* in your web root (e.g. `~/public_html/mcp/`), create `index.php` containing `<?php require '/home/you/vamsys-mcp/public/index.php';` and `setup.php` containing `<?php require '/home/you/vamsys-mcp/public/setup.php';`, and copy `public/.htaccess` there as well. Use one-line stubs rather than copies, so updates keep working.
4. **PHP must be able to create the folder `var/`** next to `public/`. It does this itself on most shared hosts. On some hosts the FTP account can't see folders PHP creates; that's fine.
5. **Open `https://mcp.your-va.com/setup.php`.** To prove you control the server, the installer shows a file name such as `vamsys-setup-1a2b3c4d5e6f.txt`. Create an **empty file with that name** in the installation folder (the one with `public/`, `src/` and `vendor/`) using FTP or the file manager, then click **Continue**. Nobody without access to your files can get past this step.
6. **Fill in the form:**
   - **Database:** host, name, user and password.
   - **DB admin login (optional):** only used once to create the tables, never stored. Leave it empty if your database user may create tables.
   - **Airlines:** key, OAuth client ID and secret, weight unit, rate cap, and whether the Pilots API is enabled.
   - **Host name** of the MCP endpoint.
   - **Admin password** for this admin page.
   - **First MCP user:** the person who signs in when Claude connects. This login is separate from the admin password.
   - **FTP login (optional):** only needed for updates if PHP can't write its own files. It is stored encrypted.

   Submit the form. The installer then:
   - checks the database connection;
   - creates the tables;
   - verifies the vAMSYS credentials (one token request per airline);
   - writes `config/config.php` (outside the web root, `chmod 600`);
   - creates the MCP user.
7. **Check:** `curl -i https://mcp.your-va.com/mcp` should answer `401` with a `WWW-Authenticate: Bearer resource_metadata=…` header. That means the server is running and asks clients to sign in.

`https://mcp.your-va.com/setup.php` is also your admin page from now on:
- the dashboard;
- settings: airlines, secrets, admin password;
- MCP users and active connections: add, disable, revoke;
- FTP credentials;
- updates.

You can switch it off with `'admin' => ['web_enabled' => false]` in config.php.

**With SSH**, you can do the same on the command line: `php bin/install.php` asks the same questions interactively. Run it again any time to change settings; pressing Enter keeps the current value.

`public/.htaccess` passes the `Authorization` header through Apache/PHP-FPM, which many hosts otherwise drop. On nginx, make sure the `Authorization` header reaches PHP and all requests except `setup.php` go to `index.php`.

## Updating

Open **Updates** in the admin page, click **Check for updates**, then **Install update**. Or, with SSH: `php bin/update.php`.

What happens:
1. The release zip is downloaded from GitHub and its **Ed25519 signature is verified**. Unsigned or tampered packages are refused.
2. Compatibility is checked. Downgrades, new major versions (breaking changes) and releases that need a newer PHP are refused with an explanation.
3. **Database changes:** if the release changes the database, you're asked for a DB admin login. It is used once and not stored. Database changes are always additive, so nothing is lost.
4. **Backup:** every file that will be replaced or removed is copied to `var/update/backup-<old version>/`.
5. **Maintenance and file replacement:** the MCP endpoint answers 503 for the duration. The work runs in short steps, so slow FTP doesn't hit PHP time limits.
6. **Writing the files:** PHP writes them directly if it can. Otherwise the updater uses the **FTP login**, either the one saved (encrypted) under *FTP* or one you enter at update time.
7. **If something fails while files are being replaced**, click **Roll back** to restore the previous version from the backup.

**What updates never touch:** `config/config.php`, everything in `var/`, your database data, and any files of your own. Settings and credentials therefore survive every update.

Installations from a git checkout aren't updated automatically; use `git pull && composer install --no-dev` there.

## Configuration

The installer writes `config/config.php` for you. `config/config.example.php` documents every option if you prefer to write it by hand. A hand-written config (without the installer's marker line) is never overwritten by the installer or the admin page. Keep config.php outside the web root and readable only by your PHP user (`chmod 600`). To store it elsewhere, set the environment variable `VAMSYS_MCP_CONFIG` to its path.

Per airline:

| Key | Meaning |
|---|---|
| array key (e.g. `'swv'`) | Short airline key used by MCP clients |
| `name` | Display name |
| `client_id`, `client_secret` | vAMSYS Operations API OAuth client |
| `weight_unit` | `'kg'` or `'lbs'`, sent on every `/routes` call |
| `rate_cap` | Requests per minute this server may use (default 60, max 100) |
| `pilots_api` | `false` by default. Set `true` only if you want pilot endpoints reachable |

In a hand-written config, secrets can come from environment variables, for example `'client_secret' => getenv('VAMSYS_SWV_CLIENT_SECRET') ?: ''`.

Other settings, all documented in the example file:
- **Paging:** page size and maximum pages per tool call.
- **Rate limiting:** how long to wait for the rate limit.
- **Tokens:** the token refresh margin.
- **Audit log:** on or off.
- **HTTP endpoint:** `http.allowed_hosts`, `http.public_url` (optional) and `http.session_dir`.
- **OAuth sign-in:** `oauth.allowed_redirect_hosts` and the token lifetimes.
- **Admin page:** `admin.password_hash`, `admin.web_enabled`.
- **Encryption:** `app_key`.
- **Updates:** `update.repository`.

**Forgot the admin password?** Click **Forgot the password?** on the login page and prove you control the server the same way as during installation (create the empty file it names). Then set a new password.

## Database

What's stored where:

| Data | Location |
|---|---|
| Cached vAMSYS access tokens, rate-limit counters, login-throttle counters, optional audit log | Database |
| MCP users (password hashes), OAuth clients, codes and tokens (SHA-256 hashes only) | Database |
| Caches of vAMSYS data: `airport_cache` (airport ID → ICAO/IATA/name) and `route_cache` (a compact copy of each airline's route list; internal remarks are never stored) | Database |
| FTP login for updates | Database, **encrypted**. The key (`app_key`) is in config.php, so neither a DB dump nor config.php alone reveals it. |
| vAMSYS client secrets, DB password, admin password hash | config.php only. Never in the database. |

The installer and updater create and change tables from `migrations/`. Because the application user only needs SELECT, INSERT, UPDATE and DELETE, they ask for a DB admin login when tables need creating or changing, and never store it. For a fully manual setup, import `schema.sql` as an admin:

```sql
CREATE DATABASE vamsys_mcp CHARACTER SET utf8mb4 COLLATE utf8mb4_bin;
CREATE USER 'vamsys_mcp'@'localhost' IDENTIFIED BY 'a-long-random-password';
GRANT SELECT, INSERT, UPDATE, DELETE ON vamsys_mcp.* TO 'vamsys_mcp'@'localhost';
-- then import schema.sql into vamsys_mcp
```

### Migration notes

| Version | Migration | What it adds |
|---|---|---|
| 0.1.0 | `0001_initial.sql`, `0002_admin.sql` | vAMSYS token cache, rate-limit windows, call log, encrypted settings, admin login throttle |
| 0.2.0 | `0003_oauth.sql` | `mcp_user`, `mcp_oauth_client`, `mcp_oauth_code`, `mcp_oauth_token` (OAuth sign-in) |
| 0.3.0 | `0004_caches.sql` | `airport_cache`, `route_cache` |

The updater and Settings → Save (with a DB admin login) apply pending migrations automatically. To apply one by hand, run its file from `migrations/` as a DB admin, then record it so the installer knows it's done, e.g. `INSERT INTO schema_migration (version, applied_at) VALUES ('0004', UTC_TIMESTAMP());`. Every migration only adds tables (`IF NOT EXISTS`), so running one twice is harmless.

## Connecting MCP clients

### Claude Code (local, STDIO)

```bash
claude mcp add vamsys -- php /path/to/vamsys-mcp/bin/server-stdio.php
# with a config file elsewhere:
claude mcp add vamsys -e VAMSYS_MCP_CONFIG=/path/to/config.php -- php /path/to/vamsys-mcp/bin/server-stdio.php
```

### Claude Desktop (local, STDIO)

In `claude_desktop_config.json`:

```json
{
  "mcpServers": {
    "vamsys": {
      "command": "php",
      "args": ["C:\\path\\to\\vamsys-mcp\\bin\\server-stdio.php"]
    }
  }
}
```

The STDIO server runs on your computer, so that computer needs PHP 8.4 and access to the database. For a database on your host, use the remote option below instead.

### Remote over HTTP with OAuth sign-in (your hosted endpoint)

The MCP server URL is **`https://mcp.your-va.com/mcp`**. Clients connect with OAuth. When you first connect, a sign-in page on your server opens; log in with an **MCP user** (created in the admin page or with `php bin/mcp-user.php add <name>`) and click **Allow**. The connection then stays signed in, renewing its tokens automatically, until you revoke it under *MCP users*.

- **claude.ai / Claude Desktop / Claude mobile:**
  1. Go to Customize → Connectors → **Add custom connector**.
  2. Paste the URL.
  3. Keep the default authentication (**Sign in now**) and OAuth client (**Use Claude's published identity**). **Register automatically** works too.
  4. Click **Connect** and sign in.
- **Claude Code:**
  ```bash
  claude mcp add --transport http vamsys https://mcp.your-va.com/mcp
  ```
  Then run `/mcp` in Claude Code and choose *Authenticate*. A browser window opens for the sign-in.
- **MCP Inspector:** `npx @modelcontextprotocol/inspector`, choose "Streamable HTTP", enter the URL and use the OAuth flow.

Other clients only work if their redirect host is allowed in `oauth.allowed_redirect_hosts`. By default that's `claude.ai`, plus `localhost` for Claude Code and the Inspector.

## Tools

| Tool | What it does |
|---|---|
| `list_airlines` | Lists the configured airline keys (plus name and weight unit). Call this first. |
| `find_routes` | Finds routes and returns **compact summaries**, never the whole route set. Departure and arrival come back as ICAO codes (`dep`/`arr`, plus `dep_iata`/`arr_iata`). |
| `count_routes` | Counts routes matching the same filters without listing them, for questions like "how many routes are missing a departure time?". Optional `breakdown`: `tag` (top 15 tags) or `visibility` (hidden vs visible). |

**Common arguments** (`find_routes` and `count_routes`):
- `airline` (required)
- `dep` / `arr`: ICAO or IATA code
- `tag`: case-sensitive; comma-separated means the route must have all of them
- `missing_field`: the field is empty, e.g. `departure_time`, `flight_distance`, `remarks`, `fleet_ids`
- `refresh`: re-read the routes from vAMSYS instead of using the cache
- `limit` (`find_routes` only): 1–50, default 20

**What the result fields mean:**

| Field | Meaning |
|---|---|
| `complete` | `true` when every relevant route was examined. Then `matched` is exact. |
| `scanned` | How many routes were examined. |
| `total_routes` | Size of the airline's whole route set. Only present when the full set was read. |
| `source` | Where the routes came from: `cache` (a recently read full set), `scan` (the full set read now) or `live` (a `find_routes` search that pages through vAMSYS and stops as soon as it has `limit` matches). |

If an airport ID can't be resolved, the output shows `dep_id`/`arr_id` with `dep_unresolved`/`arr_unresolved: true`.

**Caching:**
- **Airports:** the airline's airport list is cached (ID → ICAO/IATA/name) for `vamsys.airport_cache_ttl` (default 24 h). It is reloaded early when a route refers to an unknown airport, at most once every 5 minutes. Airport filters are resolved from the same cache, so they cost no extra API calls.
- **Routes:** `count_routes` (and `find_routes` with `refresh: true`) read the airline's **full route set** once and reuse it for `vamsys.route_cache_ttl` (default 10 min).
- **Large route networks:** a full read stops at the per-airline request cap (it never waits and never goes over) or at `vamsys.scan_time_budget_seconds` (default 20 s, to stay below PHP's time limit). It then returns a partial result with `complete: false`, and the next call continues where it stopped.

All tools are marked read-only. Outputs are kept small on purpose, because every byte ends up in the AI's context.

## Security notes

- **The rate limit is shared.** vAMSYS allows 100 requests/minute **per airline**, shared by **every** OAuth client of that airline, including your other scripts, bots and integrations. The default cap here is 60/min, which leaves headroom for them. Lower `rate_cap` if your other tools are busy. When the cap is reached, the server waits briefly or returns a clear error. It never exceeds the cap.
- **The Pilots API is off by default.** Pilot endpoints (`/pilots`, `/hubs/{id}/pilots`, `/statistics/pilot/{id}`, …) can't be reached unless you set `'pilots_api' => true` for an airline. Leave it off unless you need it: pilot data is personal data.
- **Secrets stay outside the web root.** Client secrets live only in `config/config.php` (or environment variables), outside the web root, `chmod 600`. They are never in the database, logs or error messages. The one exception is the optional FTP login for updates: it is stored encrypted in the database, and the key lives in config.php.
- **The admin page is locked down:**
  - It only works over HTTPS.
  - The installer and the password reset require proof that you control the server's files: an empty file with a random, per-session name that you create over FTP.
  - Failed logins are throttled.
  - Every form is CSRF-protected and secrets are never shown again.
  - If you don't need it, disable it with `'admin' => ['web_enabled' => false]`.
- **A stored FTP login gives write access to your site.** Store it only if PHP can't write its own files (the *FTP* page tells you), and prefer an FTP account restricted to the installation folder.
- **Updates are signed.** The updater installs only packages signed with the project's release key, so a tampered download or a compromised download link can't push code to your server.
- **OAuth sign-in protects the HTTP endpoint:**
  - Only people with an **MCP user** can connect a client, and MCP users get read-only data access, not the admin page.
  - The flow is OAuth 2.1 with mandatory PKCE.
  - Access tokens live 1 hour. Refresh tokens rotate on every use, and a reused refresh token revokes the whole connection.
  - Codes and tokens are stored only as SHA-256 hashes.
  - Disabling a user or revoking a connection takes effect immediately.
- **Sign-in redirects are restricted.** After sign-in, browsers are only sent back to hosts in `oauth.allowed_redirect_hosts` (default `claude.ai` and `localhost`), and the sign-in page shows the target host. A registered "client" pointing at someone else's site can't phish an approval.
- **Unknown host names are rejected.** Requests for host names not in `http.allowed_hosts` are refused (DNS-rebinding protection).
- **Read-only.** Version 1 never sends POST/PUT/DELETE to the vAMSYS API.

## Testing locally

```bash
composer install
composer test                                              # unit tests (no network, no DB needed)
npx @modelcontextprotocol/inspector php bin/server-stdio.php   # interactive testing, needs config + DB
```

The Inspector opens in your browser. Click **Connect**, then **Tools → List Tools**, and try `list_airlines` and `find_routes`.

## For maintainers: releases

Releases are built and signed by GitHub Actions (`.github/workflows/release.yml`).

One-time setup:
1. Run `php bin/release-keygen.php`.
2. Paste the **public key** into `src/Update/ReleaseKey.php` and commit it.
3. Store the **secret key** as the repository secret `RELEASE_SIGNING_KEY` (Settings → Secrets and variables → Actions). Keep an offline backup and never commit it.

Until a public key is committed, installations refuse automatic updates.

Each release:
1. Bump `App::VERSION` (and `App::UPDATE_FROM_MIN` if older versions can't update in place).
2. Commit, then push a tag `vX.Y.Z`.

The workflow then:
- runs the tests;
- checks that the tag matches `App::VERSION`;
- builds `vamsys-mcp-X.Y.Z.zip` with production `vendor/`, `release.json` and `manifest.json`;
- signs it (`.zip.sig`);
- publishes the GitHub release.

## Roadmap

- More question-oriented read tools: PIREP review queues, fleet/aircraft summaries, airport lookups, statistics.
- **Write tools with a dry-run/diff step.** A write would first return exactly what would change (a diff against the current vAMSYS data) and apply it only after explicit confirmation. Writes will respect vAMSYS semantics such as "explicit `null` clears a value, omitting a field keeps it".
- Optional cache of airport ID ↔ ICAO codes to enrich route output.

## Open questions

1. **Other MCP clients.** The built-in OAuth server is tested against Claude's requirements (claude.ai, Desktop, Claude Code) and the MCP Inspector. Other clients need their redirect host added to `oauth.allowed_redirect_hosts` and may differ in details.
2. **vAMSYS token lifetime.** The OpenAPI spec says tokens last 24 hours (`expires_in` 86400); operators report 7 days (604800). The server simply trusts `expires_in` from each response.
3. **Maximum `page[size]`.** It isn't documented. The default here is 100; lower `vamsys.page_size` if vAMSYS rejects it.
4. **Does `/oauth/token` count against the 100/min limit?** This server counts it, to be safe.
5. **Protocol versions.** The server accepts both the stateless `2026-07-28` protocol and the older session-based one, so it works with any current client. Once all clients use the new protocol, the session directory can go.
6. **Airport codes in route output.** `/routes` returns airport IDs. `find_routes` shows ICAO codes for the airports you filtered by, and IDs for the rest.
7. **Shared hosts without `ext-ftp`.** If PHP can't write its own files *and* the host has no FTP extension, the web updater can't apply updates; upload the new release by FTP manually. An SFTP or cURL-FTP writer could cover more hosts.

## License

Apache License 2.0, see [LICENSE](LICENSE).
