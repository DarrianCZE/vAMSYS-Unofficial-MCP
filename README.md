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
4. **Make `var/` writable by PHP.** Create the folder `~/vamsys-mcp/var/` if it doesn't exist. On most shared hosts it already is writable.
5. **Open `https://mcp.your-va.com/setup.php`.** The installer first asks for a **setup key**. It has just written that key to `~/vamsys-mcp/var/setup-key.txt`: open the file with FTP or the file manager and paste its content. This proves you control the server, so nobody else can run your installer.
6. **Fill in the form:**
   - **Database:** host, name, user and password.
   - **DB admin login (optional):** only used once to create the tables, never stored. Leave it empty if your database user may create tables.
   - **Airlines:** key, OAuth client ID and secret, weight unit, rate cap, and whether the Pilots API is enabled.
   - **Host name** of the MCP endpoint.
   - **Admin password** for this admin page.
   - **FTP login (optional):** only needed for updates if PHP can't write its own files. It is stored encrypted.

   Submit the form. The installer then:
   - checks the database connection;
   - creates the tables;
   - verifies the vAMSYS credentials (one token request per airline);
   - writes `config/config.php` (outside the web root, `chmod 600`);
   - shows your **MCP access token once**. Copy it.
7. **Check:** `curl -i https://mcp.your-va.com/` should answer `401 Missing or invalid bearer token`. That means the server is running and protected.

`https://mcp.your-va.com/setup.php` is also your admin page from now on:
- the dashboard;
- settings: airlines, secrets, admin password;
- access tokens: create and revoke;
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
- **HTTP endpoint:** `http.allowed_hosts`, `http.bearer_token_sha256` and `http.session_dir`.
- **Admin page:** `admin.password_hash`, `admin.web_enabled`.
- **Encryption:** `app_key`.
- **Updates:** `update.repository`.

**Forgot the admin password?** Delete the `password_hash` line in the `admin` section of config.php via FTP. The admin page then lets you set a new one after entering a fresh setup key.

## Database

What's stored where:

| Data | Location |
|---|---|
| Cached access tokens, rate-limit counters, login-throttle counters, optional audit log | Database |
| FTP login for updates | Database, **encrypted**. The key (`app_key`) is in config.php, so neither a DB dump nor config.php alone reveals it. |
| vAMSYS client secrets, DB password, admin password hash | config.php only. Never in the database. |

The installer and updater create and change tables from `migrations/`. Because the application user only needs SELECT, INSERT, UPDATE and DELETE, they ask for a DB admin login when tables need creating or changing, and never store it. For a fully manual setup, import `schema.sql` as an admin:

```sql
CREATE DATABASE vamsys_mcp CHARACTER SET utf8mb4 COLLATE utf8mb4_bin;
CREATE USER 'vamsys_mcp'@'localhost' IDENTIFIED BY 'a-long-random-password';
GRANT SELECT, INSERT, UPDATE, DELETE ON vamsys_mcp.* TO 'vamsys_mcp'@'localhost';
-- then import schema.sql into vamsys_mcp
```

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

### Remote over HTTP (your hosted endpoint)

The endpoint URL is your domain, e.g. `https://mcp.your-va.com/`. Clients must send `Authorization: Bearer <token>`.

- **Claude Code:**
  ```bash
  claude mcp add --transport http vamsys https://mcp.your-va.com/ --header "Authorization: Bearer YOUR_TOKEN"
  ```
- **claude.ai / Claude Desktop custom connector:** Customize → Connectors → Add custom connector, enter the URL, choose **No sign-in**, and under **Request headers** add `Authorization` with the value `Bearer YOUR_TOKEN`. Anthropic currently lists request-header authentication as a **beta available to a limited set of organisations**. If you don't see "Request headers", claude.ai can't use this endpoint yet; see [Open questions](#open-questions).
- **MCP Inspector:** `npx @modelcontextprotocol/inspector`, choose "Streamable HTTP", enter the URL and add the Authorization header.

## Tools

| Tool | What it does |
|---|---|
| `list_airlines` | Lists the configured airline keys (plus name and weight unit). Call this first. |
| `find_routes` | Finds routes and returns a **compact summary**, never the whole route set. Arguments: `airline` (required), `dep` / `arr` (ICAO or IATA code), `tag` (case-sensitive; comma-separated = must have all), `missing_field` (e.g. `departure_time`, `flight_distance`, `remarks`, `fleet_ids`), `limit` (1–50, default 20). The answer includes `has_more`, and a note if it stopped at the page limit. |

All tools are marked read-only. Outputs are kept small on purpose, because every byte ends up in the AI's context.

## Security notes

- **The rate limit is shared.** vAMSYS allows 100 requests/minute **per airline**, shared by **every** OAuth client of that airline, including your other scripts, bots and integrations. The default cap here is 60/min, which leaves headroom for them. Lower `rate_cap` if your other tools are busy. When the cap is reached, the server waits briefly or returns a clear error. It never exceeds the cap.
- **The Pilots API is off by default.** Pilot endpoints (`/pilots`, `/hubs/{id}/pilots`, `/statistics/pilot/{id}`, …) can't be reached unless you set `'pilots_api' => true` for an airline. Leave it off unless you need it: pilot data is personal data.
- **Secrets stay outside the web root.** Client secrets live only in `config/config.php` (or environment variables), outside the web root, `chmod 600`. They are never in the database, logs or error messages. The one exception is the optional FTP login for updates: it is stored encrypted in the database, and the key lives in config.php.
- **The admin page is locked down:**
  - It only works over HTTPS.
  - The installer and the password reset need a one-time setup key that is readable only through your server's files.
  - Failed logins are throttled.
  - Every form is CSRF-protected and secrets are never shown again.
  - If you don't need it, disable it with `'admin' => ['web_enabled' => false]`.
- **A stored FTP login gives write access to your site.** Store it only if PHP can't write its own files (the *FTP* page tells you), and prefer an FTP account restricted to the installation folder.
- **Updates are signed.** The updater installs only packages signed with the project's release key, so a tampered download or a compromised download link can't push code to your server.
- **The HTTP endpoint fails closed.** Without a configured token hash it refuses all requests. Tokens are stored only as SHA-256 hashes. Requests for host names not in `http.allowed_hosts` are rejected (DNS-rebinding protection).
- **Treat bearer tokens like passwords.** Anyone with the token can read your airline data through the server. Rotate it by generating a new one and removing the old hash.
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
- OAuth for the HTTP endpoint (for claude.ai without the request-header beta).
- Optional cache of airport ID ↔ ICAO codes to enrich route output.

## Open questions

1. **HTTP authentication.** v1 uses a static bearer token. It works with Claude Code, Claude Desktop config, MCP Inspector, and claude.ai **only where the "Request headers" beta is available**. Broad claude.ai support needs OAuth: either Dynamic Client Registration or Anthropic's Client ID Metadata Document, with an authorization server. Is a built-in minimal OAuth server worth it for shared hosting, or should operators put an external OAuth provider in front?
2. **Token lifetime.** The OpenAPI spec says tokens last 24 hours (`expires_in` 86400); operators report 7 days (604800). The server simply trusts `expires_in` from each response.
3. **Maximum `page[size]`.** It isn't documented. The default here is 100; lower `vamsys.page_size` if vAMSYS rejects it.
4. **Does `/oauth/token` count against the 100/min limit?** This server counts it, to be safe.
5. **Protocol versions.** The server accepts both the stateless `2026-07-28` protocol and the older session-based one, so it works with any current client. Once all clients use the new protocol, the session directory can go.
6. **Airport codes in route output.** `/routes` returns airport IDs. `find_routes` shows ICAO codes for the airports you filtered by, and IDs for the rest.
7. **Shared hosts without `ext-ftp`.** If PHP can't write its own files *and* the host has no FTP extension, the web updater can't apply updates; upload the new release by FTP manually. An SFTP or cURL-FTP writer could cover more hosts.

## License

Apache License 2.0, see [LICENSE](LICENSE).
