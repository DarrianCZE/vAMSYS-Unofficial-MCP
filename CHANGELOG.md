# Changelog

Every release of vAMSYS Unofficial MCP, newest first. **Read the "Breaking changes" section of every version between yours and the one you install.** Each release has that section, even when it says "None".

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow [Semantic Versioning](https://semver.org/). While the version is below 1.0, a minor release (0.x.0) may contain breaking changes; patch releases (0.x.y) never do. From 1.0 on, breaking changes come only in major releases, and the updater refuses to install a new major version automatically.

## What counts as a breaking change

Anything that can stop a working installation, client or script from working after an update, or that needs a manual step:

- **Operators:** a config key removed, renamed or with a new meaning; a manual step during or after the update; a newer PHP or database requirement; a removed CLI script; anything that makes the updater refuse the update (`App::UPDATE_FROM_MIN`).
- **Connected clients:** a change to the MCP URL or to how clients sign in, so they must reconnect.
- **Tool users** (prompts, scripts or integrations that call the tools): a tool removed or renamed; an argument removed, renamed, made required or given a different meaning; a result field removed, renamed or given a different meaning.

New tools, new optional arguments, new result fields and changed defaults are not breaking. They are listed under Added or Changed.

## [Unreleased]

## [0.4.3] - 2026-10-27

### Breaking changes

None.

### Added

- `CHANGELOG.md`: this file, with the breaking changes of every release. GitHub release notes are taken from it.
- **Check for updates** (admin page and `php bin/update.php`) reads `CHANGELOG.md` of the new release from GitHub and shows every version between yours and it, with breaking changes flagged. If GitHub can't serve the changelog, the latest release notes are shown as before. This shows up from the update *after* this release, because the version you're running performs the check.

## [0.4.2] - 2026-10-27

### Breaking changes

None.

### Added

- A test that `tools/list` publishes exactly the arguments, required list and description of each tool handler.

### Notes

- If claude.ai still shows the tool arguments of 0.4.0 after updating, the connector cached them. Disconnect and reconnect the connector.

## [0.4.1] - 2026-10-27

### Breaking changes

None. Migration `0005_route_scan.sql` asks for a DB admin login during the update (see [Migration notes](README.md#migration-notes)).

### Added

- Tools `get_route` (details of one route) and `find_duplicates` (by schedule or by flight number).
- Filters `flight_number`, `callsign`, `hidden` and `both_directions` in `find_routes` and `count_routes`.
- `find_routes`: paging with `offset` and `next_offset`.
- `count_routes`: `breakdown` values `dep` and `arr`, and `by_direction` with `both_directions`.
- `airline` may be left out when only one airline is configured.
- Paused results report `resumable`, `retry_after_seconds` and `progress`.
- Table `route_scan` (migration 0005): every route set a question reads is cached, the full set and each filtered set. Filtered reads pause and resume like full ones.
- Parallel calls that need the same route set share one read instead of both reading it.

### Changed

- More filters are sent to vAMSYS (`tag`, `hidden`, `flight_number`, `callsign`); results are still re-checked locally.
- `refresh=true` continues an unfinished read instead of starting over.
- Default `vamsys.scan_time_budget_seconds` lowered from 20 to 15.
- The `route_cache` table from 0.3.0 is no longer used. It stays in the database and can be dropped by hand.

## [0.4.0] - 2026-10-27

### Breaking changes

None.

### Changed

- `count_routes` with `dep`/`arr` reads only the matching routes from vAMSYS instead of the airline's whole route set.
- `find_routes` with `refresh=true` and `dep`/`arr` searches live instead of re-reading the whole route set.
- Airport filter codes are looked up with one filtered `/airports` request when they're not in the cache; a filter never reloads the whole airport list.
- Paused reads report `retry_after_seconds`.

## [0.3.0] - 2026-10-27

### Breaking changes

- **`find_routes` result field renamed:** `routes_scanned` is now `scanned`. Prompts or scripts that read `routes_scanned` must use `scanned`.

Migration `0004_caches.sql` asks for a DB admin login during the update.

### Added

- Tool `count_routes`, with the same filters as `find_routes` and an optional `breakdown` (`tag` or `visibility`).
- Airport cache (`airport_cache`): airport codes in routes and in `dep`/`arr` filters come from the cache instead of per-call `/airports` requests. Routes also show `dep_iata`/`arr_iata`.
- Route cache (`route_cache`) with reads that pause at the rate cap or time budget and resume on the next call.
- `find_routes` result fields `source`, `complete`, `total_routes` and `matched`.
- Config keys `vamsys.airport_cache_ttl`, `vamsys.route_cache_ttl` and `vamsys.scan_time_budget_seconds`, all with defaults.

## [0.2.0] - 2026-10-27

### Breaking changes

- **Static access tokens are removed; the HTTP endpoint uses OAuth sign-in only.** After updating:
  1. Create an MCP user in the admin page (*MCP users*) or with `php bin/mcp-user.php add <name>`.
  2. Reconnect every client to `https://<your host>/mcp` and sign in with that user (see [Connecting MCP clients](README.md#connecting-mcp-clients)).
- The config key `http.bearer_token_sha256` is no longer used; the installer removes it.
- `bin/generate-token.php` is removed.

Migration `0003_oauth.sql` asks for a DB admin login during the update.

### Added

- Built-in OAuth 2.1 authorization server with mandatory PKCE, rotating refresh tokens, Dynamic Client Registration and Client ID Metadata Documents.
- MCP users, managed in the admin page or with `bin/mcp-user.php`; connections can be listed and revoked.
- Config keys `oauth.allowed_redirect_hosts` and the token lifetimes, with defaults.

## [0.1.1] - 2026-10-27

### Breaking changes

None.

### Changed

- The installer and the new "Forgot the password?" flow prove server ownership with an empty file you create over FTP, instead of a key PHP writes. This works on hosts where FTP can't see files PHP created.

## [0.1.0] - 2026-10-27

First release: read-only MCP server with `list_airlines` and `find_routes`, web and CLI installer, and signed self-updates.

[Unreleased]: https://github.com/DarrianCZE/vAMSYS-Unofficial-MCP/compare/v0.4.3...HEAD
[0.4.3]: https://github.com/DarrianCZE/vAMSYS-Unofficial-MCP/compare/v0.4.2...v0.4.3
[0.4.2]: https://github.com/DarrianCZE/vAMSYS-Unofficial-MCP/compare/v0.4.1...v0.4.2
[0.4.1]: https://github.com/DarrianCZE/vAMSYS-Unofficial-MCP/compare/v0.4.0...v0.4.1
[0.4.0]: https://github.com/DarrianCZE/vAMSYS-Unofficial-MCP/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/DarrianCZE/vAMSYS-Unofficial-MCP/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/DarrianCZE/vAMSYS-Unofficial-MCP/compare/v0.1.1...v0.2.0
[0.1.1]: https://github.com/DarrianCZE/vAMSYS-Unofficial-MCP/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/DarrianCZE/vAMSYS-Unofficial-MCP/releases/tag/v0.1.0
