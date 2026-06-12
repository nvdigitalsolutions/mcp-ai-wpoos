# 🍓 DietPi Pro Toolkit — Full Implementation Proposal

> **Status:** Proposal  
> **Author:** NV oOS Coding Agent  
> **Created:** 2026-06-12  
> **Target Hardware:** Raspberry Pi B+ & B running DietPi  
> **Managed Applications:** Media Center (Plex / Jellyfin), Transmission, Jackett, Sonarr, Radarr  
> **Setting Key:** `enable_dietpi_toolkit` (new)  
> **Architecture Pattern:** Service / Integration Toolkit (Pattern 2 — no CPTs)  
> **Branch:** `proposal/dietpi-pro-toolkit`

---

## 1. Research Summary — Industry Standards

### 1.1 DietPi as a Platform

DietPi is a lightweight Debian-based Linux distribution optimized for single-board computers. Its key management interfaces:

| Interface               | Protocol  | Authentication                                  | Use                                                                          |
|-------------------------|-----------|-------------------------------------------------|------------------------------------------------------------------------------|
| **SSH**                 | TCP 22    | Key-based (Ed25519 preferred) or password       | System commands, `dietpi-software`, `dietpi-services`, `dietpi-drive_manager` |
| **dietpi-services**     | CLI (SSH) | Same as SSH                                     | `start` / `stop` / `restart` / `status` for all managed services             |
| **dietpi-backup**       | CLI (SSH) | Same as SSH                                     | System and app-data backup                                                   |
| **dietpi-update**       | CLI (SSH) | Same as SSH                                     | OS and software updates                                                      |

Key DietPi-specific commands:

- `/boot/dietpi/dietpi-services status` — list all services and their running states
- `/boot/dietpi/dietpi-services restart sonarr radarr transmission-daemon jackett` — control services
- `dietpi-software list | grep installed` — list installed software
- `dietpi-update` — check and apply updates
- `cpu` (built-in alias) — show CPU temp, freq, governor

### 1.2 Application API Surfaces

#### Transmission (BitTorrent Client)

- **API:** JSON-RPC 2.0 over HTTP  
- **Default URL:** `http://<host>:9091/transmission/rpc/`  
- **Auth:** `X-Transmission-Session-Id` header (CSRF token via 409 handshake) + optional HTTP Basic Auth  
- **Key Methods (RPC v18 / Transmission 4.x):**

| Method                   | Purpose                                                     |
|--------------------------|-------------------------------------------------------------|
| `torrent-get`            | List torrents with field selection                          |
| `torrent-add`            | Add by URL, magnet, or base64 `.torrent`                    |
| `torrent-start`          | Start (now or queued)                                       |
| `torrent-stop`           | Pause                                                       |
| `torrent-verify`         | Verify local data                                           |
| `torrent-reannounce`     | Ask tracker for more peers                                  |
| `torrent-remove`         | Remove with optional `delete-local-data`                    |
| `torrent-set`            | Set speed limits, seed ratio, labels, tracker, file priority|
| `torrent-set-location`   | Move torrent data                                           |
| `session-get` / `session-set` | Global config (speed limits, alt-speed, queue)         |
| `session-stats`          | Cumulative and current transfer stats                       |
| `free-space`             | Check free space in a download path                         |
| `blocklist-update`       | Update blocklist                                            |

**[Reference:](https://github.com/transmission/transmission/blob/main/docs/rpc-spec.md)**

#### Jackett (Torrent Indexer Aggregator)

- **API:** REST JSON / Torznab (Newznab superset)  
- **Default URL:** `http://<host>:9117/api/v2.0/`  
- **Auth:** API key as query param `?apikey=<key>`  

| Endpoint                                                | Purpose                           |
|---------------------------------------------------------|-----------------------------------|
| `GET /api/v2.0/indexers/all/results/torznab/api`        | Search ALL indexers simultaneously|
| `GET /api/v2.0/indexers/{name}/results/torznab/api`     | Search a specific indexer         |
| `GET /api/v2.0/indexers?configured=true`                | List configured indexers          |
| Torznab params: `t` (search type), `q`, `cat`, `limit`, `offset`, `season`, `ep`, `imdbid`, `tmdbid`, `tvdbid`, `rid` |

**[Reference:](https://github.com/Jackett/Jackett)**

#### Sonarr (TV Series Automation — Servarr Stack)

- **API:** REST JSON v3  
- **Default URL:** `http://<host>:8989/api/v3/`  
- **Auth:** `X-Api-Key` header or `?apikey=` query param  

| Endpoint                   | Purpose                                |
|----------------------------|----------------------------------------|
| `GET /series`              | List all series                        |
| `GET /series/lookup`       | Search TheTVDB                         |
| `POST /series`             | Add new series                         |
| `PUT /series/{id}`         | Update series                          |
| `DELETE /series/{id}`      | Delete series                          |
| `GET /episode?seriesId=`   | List episodes for series               |
| `GET /queue`               | Download queue status                  |
| `DELETE /queue/{id}`       | Remove from queue                      |
| `GET /calendar`            | Upcoming episodes                      |
| `GET /history`             | Grab / import / failure history        |
| `POST /command`            | Refresh, Rescan, EpisodeSearch, etc.   |
| `GET /system/status`       | Health and version                     |
| `GET /wanted/missing`      | Episodes missing from disk             |
| `GET /indexer`             | List configured indexers               |
| `GET /downloadclient`      | List configured download clients       |

**[Reference:](https://sonarr.tv/docs/api/)**

#### Radarr (Movie Automation — Servarr Stack)

- **API:** REST JSON v3 (mirrors Sonarr structure)  
- **Default URL:** `http://<host>:7878/api/v3/`  
- **Auth:** `X-Api-Key` header or `?apikey=` query param  

| Endpoint                       | Purpose                                |
|--------------------------------|----------------------------------------|
| `GET /movie`                   | List all movies                        |
| `GET /movie/lookup`            | Search by term                         |
| `GET /movie/lookup/tmdb`       | Lookup by TMDB ID                      |
| `GET /movie/lookup/imdb`       | Lookup by IMDb ID                      |
| `POST /movie`                  | Add new movie                          |
| `PUT /movie/{id}`              | Update movie                           |
| `DELETE /movie/{id}`           | Delete movie                           |
| `GET /queue`                   | Download queue status                  |
| `GET /calendar`                | Upcoming releases                      |
| `GET /history`                 | Grab / import / failure history        |
| `POST /command`                | RefreshMovie, MoviesSearch, RescanMovie|
| `GET /system/status`           | Health and version                     |
| `GET /diskspace`               | Disk space information                 |
| `GET /importlist`              | List import lists                      |
| `GET /collection`              | List collections                       |

**[Reference:](https://radarr.video/docs/api/)**

#### Media Center (Plex / Jellyfin)

DietPi supports both. The toolkit targets both:

| App        | API        | Default Port | Auth                                    |
|------------|------------|--------------|-----------------------------------------|
| **Plex**   | REST JSON  | 32400        | `X-Plex-Token`                          |
| **Jellyfin**| REST JSON | 8096         | API key in `X-Emby-Authorization` header|

Key Plex endpoints: `GET /library/sections`, `GET /library/sections/{id}/all`, `GET /status/sessions`, `POST /library/sections/{id}/refresh`  

Key Jellyfin endpoints: `GET /Items`, `GET /Sessions`, `POST /Library/Refresh`, `POST /Sessions/{id}/Playing`

### 1.3 SSH as the Unifying Protocol

Since DietPi is a headless Linux distro, **SSH is the universal management channel**:

- Execute `dietpi-services` for service lifecycle
- Read system stats (`cpu`, `free -m`, `df -h`, `vcgencmd` for Pi-specific metrics)
- Manage packages (`apt`, `dietpi-software`)
- Read logs (`journalctl`, application log files)
- Backup management (`dietpi-backup`)

### 1.4 Security Best Practices

Per the `wp-security-secrets` skill and OWASP guidelines:

- **SSH keys stored encrypted** in `wp_mcp_ai_dietpi_settings` (Pro Vault integration)
- **API keys** for Sonarr / Radarr / Jackett redacted in logs / UI exports
- **`hash_equals`** for any token comparison
- **Never echo credentials** back in tool responses — return `redacted: true`
- **Least privilege:** All state-changing tools require `manage_options`; read-only tools require `edit_posts`
- **Capability flags:** Every tool declares `external-api`, `pro`, `requires-credentials`
- **Rate limiting** on SSH commands to prevent abuse
- **Timeout** enforced for all HTTP requests (default 30 s, configurable)

---

## 2. Target Architecture

### 2.1 Folder Layout (new)

```
addons/pro/includes/
├── dietpi-toolkit-init.php                                     # Conditional loader + admin page wiring
├── dietpi/                                                      # Toolkit-private classes
│   ├── README.md                                                # Folder README
│   ├── class-wp-mcp-ai-dietpi-ssh-client.php                    # SSH2 client (exec, connect, disconnect, error→WP_Error)
│   ├── class-wp-mcp-ai-dietpi-app-client.php                    # Abstract HTTP API client for arr apps
│   ├── class-wp-mcp-ai-dietpi-helpers.php                       # Toolkit-enabled check, capability flags, shared schema fragments
│   └── class-wp-mcp-ai-dietpi-service-catalogue.php             # Registry of known DietPi services (port / API info)
└── tools/
    └── dietpi/                                                  # One class per tool
        ├── README.md                                            # Tool catalogue + status
        ├── class-wp-mcp-ai-tool-dietpi-base.php                 # Abstract base (client accessor, is_available, flags, envelope)
        ├── class-wp-mcp-ai-tool-dietpi-send-ssh-command.php     # Phase 1
        ├── class-wp-mcp-ai-tool-dietpi-list-services.php        # Phase 1
        ├── class-wp-mcp-ai-tool-dietpi-control-service.php      # Phase 1
        ├── class-wp-mcp-ai-tool-dietpi-system-info.php          # Phase 1
        ├── class-wp-mcp-ai-tool-dietpi-system-stats.php         # Phase 1
        ├── class-wp-mcp-ai-tool-dietpi-list-transmission.php    # Phase 1
        ├── class-wp-mcp-ai-tool-dietpi-add-transmission.php     # Phase 1
        ├── class-wp-mcp-ai-tool-dietpi-control-transmission.php # Phase 1
        ├── class-wp-mcp-ai-tool-dietpi-search-jackett.php       # Phase 1
        ├── class-wp-mcp-ai-tool-dietpi-list-jackett-indexers.php# Phase 1
        ├── class-wp-mcp-ai-tool-dietpi-list-sonarr-series.php   # Phase 1
        ├── class-wp-mcp-ai-tool-dietpi-add-sonarr-series.php    # Phase 1
        ├── class-wp-mcp-ai-tool-dietpi-manage-sonarr.php        # Phase 1
        ├── class-wp-mcp-ai-tool-dietpi-list-radarr-movies.php   # Phase 1
        ├── class-wp-mcp-ai-tool-dietpi-add-radarr-movie.php     # Phase 1
        ├── class-wp-mcp-ai-tool-dietpi-manage-radarr.php        # Phase 1
        ├── class-wp-mcp-ai-tool-dietpi-media-center.php         # Phase 1
        ├── class-wp-mcp-ai-tool-dietpi-health-check.php         # Phase 1
        ├── class-wp-mcp-ai-tool-dietpi-backup-system.php        # Phase 2
        ├── class-wp-mcp-ai-tool-dietpi-update-system.php        # Phase 2
        ├── class-wp-mcp-ai-tool-dietpi-manage-storage.php       # Phase 2
        ├── class-wp-mcp-ai-tool-dietpi-cross-app-workflow.php   # Phase 2
        ├── class-wp-mcp-ai-tool-dietpi-dashboard-summary.php    # Phase 2
        └── class-wp-mcp-ai-tool-dietpi-provision-new-app.php    # Phase 3

addons/pro/includes/admin/
└── class-wp-mcp-ai-dietpi-settings-page.php                     # Dashboard (extends toolkit settings base)

addons/pro/assets/css/
└── admin-dietpi-toolkit.css                                     # Admin styles

addons/pro/tests/dietpi/                                         # PHPUnit tests
├── test-dietpi-ssh-client.php
├── test-dietpi-tool-list-services.php
├── test-dietpi-tool-system-info.php
├── test-dietpi-tool-transmission.php
├── test-dietpi-tool-jackett.php
├── test-dietpi-tool-sonarr.php
├── test-dietpi-tool-radarr.php
├── test-dietpi-tool-media-center.php
├── test-dietpi-toolkit-gating.php
└── test-dietpi-app-client.php
```

### 2.2 Registration Wiring

Mirroring the Cloudways + E-commerce pattern exactly:

**Loader** — in `wp_mcp_ai_pro_init()` (`addons/pro/mcp-ai-wpoos-pro.php`):

```php
// Load DietPi Pro Toolkit if enabled.
if ( ! empty( $settings['enable_dietpi_toolkit'] ) ) {
    require_once WP_MCP_AI_PRO_PATH . 'includes/dietpi-toolkit-init.php';
}
```

**Tool map** — in `wp_mcp_ai_pro_register_tools()`:

```php
if ( ! empty( $settings['enable_dietpi_toolkit'] ) ) {
    $dietpi_toolkit_tools = array(
        'WP_MCP_AI_Tool_DietPi_Send_SSH_Command'    => WP_MCP_AI_PRO_PATH . 'includes/tools/dietpi/class-wp-mcp-ai-tool-dietpi-send-ssh-command.php',
        // … Phase‑1 tools …
    );
    $pro_tools = array_merge( $pro_tools, $dietpi_toolkit_tools );
}
```

**Tool group map** — in `wp_mcp_ai_pro_tool_group_map()`:

```php
'dietpi_send_ssh_command'      => 'system',
'dietpi_list_services'         => 'system',
'dietpi_control_service'       => 'system',
'dietpi_system_info'           => 'system',
'dietpi_system_stats'          => 'system',
'dietpi_list_transmission'     => 'dietpi-apps',
'dietpi_add_transmission'      => 'dietpi-apps',
'dietpi_control_transmission'  => 'dietpi-apps',
'dietpi_search_jackett'        => 'dietpi-apps',
'dietpi_list_jackett_indexers' => 'dietpi-apps',
'dietpi_list_sonarr_series'    => 'dietpi-apps',
'dietpi_add_sonarr_series'     => 'dietpi-apps',
'dietpi_manage_sonarr'         => 'dietpi-apps',
'dietpi_list_radarr_movies'    => 'dietpi-apps',
'dietpi_add_radarr_movie'      => 'dietpi-apps',
'dietpi_manage_radarr'         => 'dietpi-apps',
'dietpi_media_center'          => 'dietpi-apps',
'dietpi_health_check'          => 'dietpi-apps',
```

**Settings toggle** — in `WP_MCP_AI_Pro_Settings::get_individual_toolkit_status()`:

```php
'enable_dietpi_toolkit' => __( 'DietPi Toolkit', 'mcp-ai-wpoos-pro' ),
```

---

## 3. Components Detail

### 3.1 SSH Client — `WP_MCP_AI_DietPi_SSH_Client`

Handles all system-level interaction with the Pi. Falls back gracefully — if `ssh2` extension unavailable, attempts `proc_open` with the `ssh` CLI.

```php
class WP_MCP_AI_DietPi_SSH_Client {
    const DEFAULT_PORT    = 22;
    const COMMAND_TIMEOUT = 30; // seconds

    // Singleton
    public static function instance();

    // Connection management
    public function is_configured(): bool;            // host + key or password present
    public function test_connection(): true|WP_Error;
    public function connect(): true|WP_Error;
    public function disconnect(): void;

    // Command execution — the core primitive
    public function exec( string $command, int $timeout = self::COMMAND_TIMEOUT ): array|WP_Error;
    // Returns: { stdout: string, stderr: string, exit_code: int, duration_ms: int }

    // Specialized helpers
    public function dietpi_services( string $action, string|array $services ): array|WP_Error;
    public function system_stats(): array|WP_Error;
    public function raspberry_pi_info(): array|WP_Error;
}
```

SSH Auth Method Preference:

1. SSH key (Ed25519 or RSA) — stored encrypted in settings
2. Password — fallback
3. Key passphrase — supported for encrypted private keys

### 3.2 App API Client — `WP_MCP_AI_DietPi_App_Client`

Abstract client for the Servarr stack apps plus Jackett / Transmission.

```php
class WP_MCP_AI_DietPi_App_Client {
    public function for_app( string $app_slug ): array;
    public function get( string $app_slug, string $path, array $query = array() ): array|WP_Error;
    public function post( string $app_slug, string $path, array $body = array() ): array|WP_Error;
    public function put( string $app_slug, string $path, array $body = array() ): array|WP_Error;
    public function delete( string $app_slug, string $path ): array|WP_Error;
    public function is_app_configured( string $app_slug ): bool;
}
```

### 3.3 Service Catalogue — `WP_MCP_AI_DietPi_Service_Catalogue`

Registry of known DietPi services with metadata (filter-extensible).

```php
class WP_MCP_AI_DietPi_Service_Catalogue {
    const SERVICES = array(
        'transmission-daemon' => array( 'name' => 'Transmission', 'port' => 9091, 'api_type' => 'json-rpc', 'dietpi_id' => 44  ),
        'jackett'             => array( 'name' => 'Jackett',      'port' => 9117, 'api_type' => 'rest',     'dietpi_id' => 135 ),
        'sonarr'              => array( 'name' => 'Sonarr',        'port' => 8989, 'api_type' => 'rest-v3',  'dietpi_id' => 144 ),
        'radarr'              => array( 'name' => 'Radarr',        'port' => 7878, 'api_type' => 'rest-v3',  'dietpi_id' => 145 ),
        'plexmediaserver'     => array( 'name' => 'Plex',          'port' => 32400,'api_type' => 'rest',     'dietpi_id' => 42  ),
        'jellyfin'            => array( 'name' => 'Jellyfin',      'port' => 8096, 'api_type' => 'rest',     'dietpi_id' => 169 ),
    );

    public static function get_all(): array;
    public static function get( string $service_name ): ?array;
    public static function get_managed_apps(): array;
}
```

### 3.4 Abstract Tool Base — `WP_MCP_AI_Tool_DietPi_Base`

```php
abstract class WP_MCP_AI_Tool_DietPi_Base
    implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

    // Shared by all DietPi tools:
    // - is_available(): checks enable_dietpi_toolkit + not base + SSH configured
    // - get_capability_flags(): returns ['pro', 'external-api', 'requires-credentials']
    // - resolve_app_settings(): helper to pull per-app config
    // - build_envelope(): standard success/error response shape
    // - ssh(): shortcut to SSH client singleton
    // - app_client(): shortcut to App client singleton
}
```

### 3.5 Settings Page — `WP_MCP_AI_DietPi_Settings_Page`

Admin dashboard at **Settings → NV oOS → DietPi Toolkit** with tabs:

1. **Connection** — Pi hostname / IP, SSH port, key upload / paste, test connection button
2. **Apps** — Per-app config: toggle + URL + API key fields for each of the 6 managed apps
3. **Status** — Live dashboard: service status, system metrics, recent activity
4. **Logs** — SSH command log + API call log for debugging

---

## 4. Phase 1 Tool Inventory (19 tools)

| #  | Tool Slug                      | Description                                                                                      | R / W  |
|----|--------------------------------|--------------------------------------------------------------------------------------------------|--------|
| 1  | `dietpi_send_ssh_command`      | Execute arbitrary shell command on the Pi (admin-gated, allowlist mode)                          | Write  |
| 2  | `dietpi_list_services`         | List all DietPi-managed services and their running states                                        | Read   |
| 3  | `dietpi_control_service`       | Start / stop / restart a DietPi service                                                          | Write  |
| 4  | `dietpi_system_info`           | Get Pi model, OS version, kernel, uptime                                                         | Read   |
| 5  | `dietpi_system_stats`          | Live CPU temp / freq, RAM usage, disk space, throttling flags                                    | Read   |
| 6  | `dietpi_list_transmission`     | List all torrents with filtering by status / label                                                | Read   |
| 7  | `dietpi_add_transmission`      | Add torrent by URL / magnet, optional download-dir + paused flag                                  | Write  |
| 8  | `dietpi_control_transmission`  | Start / stop / remove torrents, set speed limits, move data, set labels                           | Write  |
| 9  | `dietpi_search_jackett`        | Search all configured Jackett indexers with category / type filters                               | Read   |
| 10 | `dietpi_list_jackett_indexers` | List configured Jackett indexers and their capabilities                                           | Read   |
| 11 | `dietpi_list_sonarr_series`    | List all series in Sonarr with monitoring status and episode counts                               | Read   |
| 12 | `dietpi_add_sonarr_series`     | Add a new series to Sonarr (by TVDb ID, title lookup, or IMDb ID)                                 | Write  |
| 13 | `dietpi_manage_sonarr`         | Trigger refresh, rescan, episode search, monitor / unmonitor                                      | Write  |
| 14 | `dietpi_list_radarr_movies`    | List all movies in Radarr with status, quality, availability                                      | Read   |
| 15 | `dietpi_add_radarr_movie`      | Add a new movie to Radarr (by TMDB / IMDb ID or title lookup)                                     | Write  |
| 16 | `dietpi_manage_radarr`         | Trigger refresh, rescan, movie search, manage quality profiles                                    | Write  |
| 17 | `dietpi_media_center`          | Plex / Jellyfin: list libraries, recently added, active streams, trigger scan                     | Read + Write |
| 18 | `dietpi_health_check`          | Comprehensive health scan: all services reachable, disk OK, temps safe, queue health              | Read   |
| 19 | `dietpi_media_request_flow`    | 🔑 End-to-end workflow: search Jackett → find best release → add to Transmission → monitor in *arr | Write  |

---

## 5. Settings Option Schema (`wp_mcp_ai_dietpi_settings`)

```php
array(
    // ---- Connection ----
    'host'              => '',
    'ssh_port'          => 22,
    'ssh_user'          => 'root',
    'ssh_auth_method'   => 'key',        // 'key' | 'password'
    'ssh_private_key'   => '',           // Redacted — stored in vault when available
    'ssh_key_passphrase'=> '',           // Optional, redacted
    'ssh_password'      => '',           // Fallback, redacted

    // ---- Per-App Configuration ----
    'apps'              => array(
        'transmission'  => array( 'enabled' => false, 'url' => 'http://<host>:9091/transmission/rpc', 'username' => '', 'password' => '' ),
        'jackett'       => array( 'enabled' => false, 'url' => 'http://<host>:9117',                  'api_key'  => '' ),
        'sonarr'        => array( 'enabled' => false, 'url' => 'http://<host>:8989',                  'api_key'  => '' ),
        'radarr'        => array( 'enabled' => false, 'url' => 'http://<host>:7878',                  'api_key'  => '' ),
        'plex'          => array( 'enabled' => false, 'url' => 'http://<host>:32400',                 'token'    => '' ),
        'jellyfin'      => array( 'enabled' => false, 'url' => 'http://<host>:8096',                  'api_key'  => '' ),
    ),

    // ---- Preferences ----
    'default_download_dir' => '/mnt/dietpi_userdata/downloads',
    'command_timeout'      => 30,
    'cache_ttl_seconds'    => 60,
    'log_ssh_commands'     => false,
);
```

---

## 6. Phased Roadmap

### Phase 0 — Foundations (build this first)

- [ ] Folder structure + `dietpi-toolkit-init.php`
- [ ] `WP_MCP_AI_DietPi_SSH_Client` — SSH2 + `proc_open` fallback
- [ ] `WP_MCP_AI_DietPi_App_Client` — shared HTTP client
- [ ] `WP_MCP_AI_DietPi_Helpers` — `is_available()` gate + schema fragments
- [ ] `WP_MCP_AI_DietPi_Service_Catalogue` — static registry
- [ ] `WP_MCP_AI_Tool_DietPi_Base` — abstract base class
- [ ] Settings page scaffold (Connection tab)
- [ ] Registration wiring in `mcp-ai-wpoos-pro.php` (loader + tool map + group map)
- [ ] Settings toggle in `class-wp-mcp-ai-pro-settings.php`
- [ ] `enable_dietpi_toolkit` option registration
- [ ] Unit tests: SSH client mock, gating logic

### Phase 1 — Read + Write Tools + Core Infrastructure (19 tools)

- [ ] Tools 1–5: SSH command, services, system info / stats
- [ ] Tools 6, 9–11, 14: Read-only app queries
- [ ] Tools 7–8, 12–13, 15–16: Write operations for Transmission, Sonarr, Radarr
- [ ] Tools 17–19: Media center, health check, cross-app workflow
- [ ] Settings page: Apps tab + Status tab
- [ ] Companion workflow presets
- [ ] Unit tests: one per tool class

### Phase 2 — Advanced Operations

- [ ] Backup, update, storage management tools
- [ ] Dashboard summary tool
- [ ] Admin dashboard live-status widget
- [ ] DietPi cron-based health monitoring with alerts

### Phase 3 — Advanced + Assistant Blueprints

- [ ] `dietpi_provision_new_app` — install & configure a new DietPi software package
- [ ] Assistant blueprints: `media-server-admin.json`, `torrent-overseer.json`
- [ ] Node.js SSH proxy service (for hosts where neither `ssh2` nor `proc_open` is available)
- [ ] Integration tests with a real Pi

---

## 7. Security Model

| Concern                           | Mitigation                                                                                       |
|-----------------------------------|--------------------------------------------------------------------------------------------------|
| SSH credentials at rest           | Encrypted in `wp_mcp_ai_dietpi_settings`; Vault integration for key material                     |
| API keys in transit               | HTTPS enforced for the WP admin; Pi communication may be local-network HTTP                      |
| Credentials in tool responses     | All password / key / token fields automatically redacted; response schema excludes them           |
| Command injection                 | `escapeshellarg()` on all user-supplied command args; allowlist mode for `dietpi_send_ssh_command`|
| Privilege escalation              | `manage_options` for writes, `edit_posts` for reads; per-app capability filter                   |
| Rate limiting                     | SSH commands 10 / min; API calls 60 / min per app                                                |
| Audit trail                       | Every state-changing action logs to `WP_MCP_AI_Logger`                                           |
| Exposed services                  | Toolkit only connects to user-configured hosts; no automatic discovery that could leak to public |

---

## 8. Testing Strategy

```bash
vendor/bin/phpunit addons/pro/tests/dietpi/
```

- **Unit:** SSH client mock — test command parsing, error handling, timeouts
- **Unit:** Each tool class — parameter validation, capability gates, response envelopes
- **Integration:** Gating — toolkit disabled = all tools unavailable, base version = all unavailable
- **Integration:** App client — mock HTTP responses for each app
- **Manual:** Real Pi testing (Phase 3)

---

## 9. File-by-File Manifest (Phase 0 + 1)

| #  | File                                                    | Type      | Est. Lines |
|----|---------------------------------------------------------|-----------|------------|
| 1  | `dietpi-toolkit-init.php`                               | Bootstrap | ~80        |
| 2  | `dietpi/README.md`                                      | Docs      | ~60        |
| 3  | `dietpi/class-wp-mcp-ai-dietpi-ssh-client.php`          | Service   | ~350       |
| 4  | `dietpi/class-wp-mcp-ai-dietpi-app-client.php`          | Service   | ~200       |
| 5  | `dietpi/class-wp-mcp-ai-dietpi-helpers.php`             | Utility   | ~80        |
| 6  | `dietpi/class-wp-mcp-ai-dietpi-service-catalogue.php`   | Data      | ~120       |
| 7  | `tools/dietpi/README.md`                                | Docs      | ~80        |
| 8  | `tools/dietpi/class-wp-mcp-ai-tool-dietpi-base.php`     | Abstract  | ~60        |
| 9–27| `tools/dietpi/class-wp-mcp-ai-tool-dietpi-*.php` × 19   | Tools     | ~150–300 ea|
| 28 | `admin/class-wp-mcp-ai-dietpi-settings-page.php`        | Admin     | ~400       |
| 29 | `assets/css/admin-dietpi-toolkit.css`                   | Styles    | ~50        |
| 30–39| `tests/dietpi/test-dietpi-*.php` × 10                   | Tests     | ~100–200 ea|

**Total estimated Phase 1 LOC:** ~6,000–8,000 across ~40 files.

---

## 10. Companion Tool — Example Schema

Representative tool schema (`dietpi_add_transmission`):

```php
class WP_MCP_AI_Tool_DietPi_Add_Transmission extends WP_MCP_AI_Tool_DietPi_Base {

    public function get_slug()        { return 'dietpi_add_transmission'; }
    public function get_name()        { return __( 'Add Transmission Torrent', 'mcp-ai-wpoos-pro' ); }
    public function get_description() {
        return __( 'Add a new torrent to Transmission by URL, magnet link, or base64-encoded .torrent file. Optionally specify a download directory and whether to start paused.', 'mcp-ai-wpoos-pro' );
    }

    public function get_parameters_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'source'       => array(
                    'type'        => 'string',
                    'description' => __( 'URL, magnet link, or base64-encoded .torrent file content.', 'mcp-ai-wpoos-pro' ),
                ),
                'download_dir' => array(
                    'type'        => 'string',
                    'description' => __( 'Custom download directory. Uses Transmission default if omitted.', 'mcp-ai-wpoos-pro' ),
                ),
                'paused'       => array(
                    'type'        => 'boolean',
                    'description' => __( 'Add torrent in paused state. Default: false.', 'mcp-ai-wpoos-pro' ),
                    'default'     => false,
                ),
                'label'        => array(
                    'type'        => 'string',
                    'description' => __( 'Label / tag to assign to the torrent.', 'mcp-ai-wpoos-pro' ),
                ),
            ),
            'required'   => array( 'source' ),
        );
    }

    public function get_required_capability() { return 'manage_options'; }

    public function get_capability_flags() {
        return array( 'pro', 'external-api', 'requires-credentials', 'write', 'state-changing', 'reversible' );
    }
}
```

---

## 11. Open Questions & Risks

| Risk                                                  | Mitigation                                                                                   |
|-------------------------------------------------------|----------------------------------------------------------------------------------------------|
| **SSH PHP extension availability**                    | Fallback chain: `ssh2` → `proc_open(ssh)` → companion Node.js service (Phase 3)               |
| **Pi on local network only**                          | Toolkit assumes HTTP for LAN apps; Pi must be reachable from the WordPress host              |
| **Sonarr / Radarr API key discovery**                 | Users must manually enter API keys from each app's Settings → General page                   |
| **Transmission CSRF token handling**                  | Client must implement the 409 → retry-with-Session-Id loop per Transmission RPC spec          |
| **Transmission RPC IDs as JSON numbers → JS precision**| Tool responses cast IDs to string for JSON safety                                            |
| **DietPi version drift**                              | `dietpi-services` CLI is stable; service names in catalogue, filter-extensible                |
| **Multiple Pi support**                               | Single Pi per WP install in Phase 1; multi-Pi enhancement in Phase 3                         |

---

## 12. Key Patterns Borrowed from Existing Toolkits

| From                  | Pattern                                                            | Applied as                                                    |
|-----------------------|--------------------------------------------------------------------|---------------------------------------------------------------|
| **Healthcare**        | Shared engine + codes registry + audit + capabilities + phased roadmap | SSH client + service catalogue + audit log + phased roadmap   |
| **Cloudways**         | External API client → abstract base → per-tool classes → settings dashboard | App client → DietPi base → per-app tools → settings dashboard |
| **CRM**               | Mirror healthcare architecture with domain-specific engine / codes | `WP_MCP_AI_DietPi_Service_Catalogue` mirrors `WP_MCP_AI_Healthcare_Codes` |
| **Remote Connections**| Connection manager → list → test → operate pattern                 | SSH `test_connection` → `list_services` → `control_service`   |
| **E-commerce**        | Service toolkit (no CPTs), conditional loading, settings gating    | Pattern 2 — no CPTs, `enable_dietpi_toolkit` gate              |

---

*End of proposal.*
