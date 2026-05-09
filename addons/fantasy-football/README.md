# NV oOS Fantasy Football Addon

Standalone addon for the NV oOS (Open Operator System) WordPress plugin that provides ESPN and Yahoo Fantasy Sports API integration.

## Features

- **ESPN Fantasy Football** — League info, team management, roster analysis, standings, lineup optimization, and league sync
- **Yahoo Fantasy Sports** — OAuth authentication, league management, roster analysis, player statistics, trade analysis, and standings
- **AI-Powered Tools** — Team logo generation, league reports, and player research powered by AI

## Requirements

- WordPress 6.0+
- PHP 7.4+
- NV oOS base plugin (installed and activated)

## Installation

1. Upload the `nvoos-fantasy-football` folder to `/wp-content/plugins/`
2. Activate through the WordPress Plugins page
3. Configure API credentials in **Settings → Fantasy Football**

## Tools (15)

### ESPN Fantasy Football (6 tools)
- `espn_fantasy_get_league` — Get ESPN league information
- `espn_fantasy_get_teams` — List teams in an ESPN league
- `espn_fantasy_get_roster` — Get a team's roster
- `espn_fantasy_get_standings` — Get league standings
- `espn_fantasy_analyze_lineup` — AI-powered lineup analysis
- `espn_fantasy_sync_league` — Sync ESPN league data locally

### Yahoo Fantasy Football (6 tools)
- `yahoo_ff_auth` — OAuth authentication with Yahoo
- `yahoo_ff_get_leagues` — List user's Yahoo leagues
- `yahoo_ff_get_roster` — Get a team's roster
- `yahoo_ff_get_player_stats` — Get player statistics
- `yahoo_ff_league_standings` — Get league standings
- `yahoo_ff_trade_analyzer` — Analyze trade proposals

### AI-Powered Tools (3 tools)
- `ff_player_research` — AI-powered player research and analysis
- `ff_create_league_report` — Generate comprehensive league reports
- `ff_generate_team_logo` — AI-generated team logos

## License

Proprietary — © 2025-2026 NV Digital Solutions. All rights reserved. See `CREDITS.md` at the repository root for full attribution.

## Credits

This addon talks to two third-party APIs that remain owned by their respective providers and are governed by their own Terms of Service:

- **ESPN Fantasy Football API** — © The Walt Disney Company / ESPN, Inc.
- **Yahoo Fantasy Sports API** — © Yahoo / Apollo Global Management

No proprietary code from either provider is bundled with this addon. For the full repo-wide attribution index, see [`CREDITS.md`](../../CREDITS.md) at the repository root.
