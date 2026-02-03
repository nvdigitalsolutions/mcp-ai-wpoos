# ESPN Fantasy Football Integration - Complete Enhancement Guide

## Overview

This document outlines the comprehensive ESPN Fantasy Football integration added to the NV oOS Fantasy Football Toolkit. The integration provides dual-provider support (ESPN + Yahoo) with shared infrastructure, advanced analytics, and WordPress data persistence.

## Architecture

### Dual Provider System

The toolkit now supports both ESPN and Yahoo Fantasy Football platforms with a unified data model:

- **Provider Identification**: `_ff_provider` meta field ('espn' or 'yahoo')
- **Provider-Specific IDs**: Separate meta fields for ESPN and Yahoo league/team IDs
- **Shared CPT Structure**: Both providers use the same `ff_team` and `ff_player` post types
- **Unified API**: Consistent tool interface regardless of provider

## Components

### 1. ESPN Fantasy Client (`class-wp-mcp-ai-espn-fantasy-client.php`)

Core HTTP client for ESPN Fantasy Football v3 API.

**Features:**
- Base endpoint: `https://fantasy.espn.com/apis/v3/games/ffl/`
- Cookie-based authentication (espn_s2, SWID) for private leagues
- Response caching with WordPress transients (15-minute TTL)
- Rate limiting (20 requests/minute with exponential backoff)
- Multi-view parameter support (mTeam, mRoster, mMatchup, mStandings, mBoxscore)

**Methods:**
- `get_league()` - League information and settings
- `get_teams()` - All teams in league
- `get_roster()` - Team roster by week
- `get_standings()` - League standings (sorted)
- `get_matchup()` - Weekly matchup data
- `get_boxscore()` - Detailed boxscore

**Caching Strategy:**
```php
// Cache keys: wp_mcp_ai_espn_{resource}_{league_id}_{season}_{params}
// Duration: 900 seconds (15 minutes)
// Clearable via: $client->clear_cache()
```

**Rate Limiting:**
```php
// Transient-based tracking
// 20 requests per minute per site
// Automatic backoff on limit reached
```

### 2. ESPN Fantasy Tools (6 Tools)

#### Tool 1: `espn_fantasy_get_league`
**Purpose:** Retrieve league configuration and settings  
**Parameters:** league_id, season, espn_s2 (optional), swid (optional)  
**Returns:** League name, size, scoring type, current week, playoff settings, roster configuration

**Example Response:**
```php
array(
    'league_id' => 387659,
    'name' => 'My League',
    'season' => 2024,
    'size' => 10,
    'current_week' => 12,
    'scoring_type' => 'Points Per Reception',
    'roster_settings' => array(
        'roster_size' => 16,
        'positions' => array('QB' => 1, 'RB' => 2, 'WR' => 2...)
    ),
    'playoff_settings' => array(
        'playoff_teams' => 6,
        'playoff_start_week' => 14
    )
)
```

#### Tool 2: `espn_fantasy_get_teams`
**Purpose:** List all teams in league  
**Parameters:** league_id, season  
**Returns:** Team IDs, names, owners, records, points for/against

#### Tool 3: `espn_fantasy_get_standings`
**Purpose:** Current league standings  
**Parameters:** league_id, season  
**Returns:** Sorted standings with win percentage, rankings

#### Tool 4: `espn_fantasy_get_roster`
**Purpose:** Team roster with player details  
**Parameters:** league_id, team_id, season, week (optional)  
**Returns:** Starters, bench, IR, player stats, positions

**Roster Structure:**
```php
array(
    'starters' => array(...),      // Starting lineup players
    'bench' => array(...),          // Bench players
    'injured_reserve' => array(...),// IR players
    'total_starters' => 9,
    'total_bench' => 6,
    'total_points' => 127.45
)
```

#### Tool 5: `espn_fantasy_sync_league`
**Purpose:** Import league data to WordPress  
**Parameters:** league_id, season, sync_rosters (bool), update_existing (bool)  
**Returns:** Sync statistics (created, updated, skipped)

**Sync Process:**
1. Fetches league information
2. Retrieves all teams
3. Optionally fetches rosters
4. Creates/updates `ff_team` posts
5. Sets provider-specific meta fields
6. Tracks last sync timestamp

**WordPress Integration:**
- Creates posts in `ff_team` CPT
- Sets `_ff_provider = 'espn'`
- Stores ESPN league/team IDs
- Saves roster data as JSON in `_ff_roster_data`
- Updates team stats (W-L-T, points, rank)

#### Tool 6: `espn_fantasy_analyze_lineup`
**Purpose:** Calculate optimal lineup based on actual points  
**Parameters:** league_id, team_id, season, week  
**Returns:** Optimal lineup, efficiency %, suggested changes

**Analysis Output:**
```php
array(
    'actual_score' => 112.30,
    'optimal_score' => 139.75,
    'points_left_on_bench' => 27.45,
    'efficiency_percentage' => 80.4,
    'suggested_changes' => array(
        'Start Player X (WR) - scored 18.50 points',
        'Start Player Y (RB) - scored 14.20 points'
    ),
    'total_changes_needed' => 2
)
```

### 3. Enhanced Fantasy Team CPT

**Post Type:** `ff_team`  
**Purpose:** Store fantasy football team data from multiple providers

**New Meta Fields:**
```php
'_ff_provider'           // 'espn' or 'yahoo'
'_ff_espn_league_id'     // ESPN league ID (integer)
'_ff_espn_team_id'       // ESPN team ID (integer)
'_ff_yahoo_league_id'    // Yahoo league ID (integer)
'_ff_yahoo_team_id'      // Yahoo team ID (integer)
```

**Existing Meta Fields (Enhanced):**
```php
'_ff_league_key'         // Legacy league key
'_ff_team_key'           // Legacy team key
'_ff_team_name'          // Team name
'_ff_owner_name'         // Owner name
'_ff_league_name'        // League name
'_ff_season'             // Season year
'_ff_wins'               // Win count
'_ff_losses'             // Loss count
'_ff_ties'               // Tie count
'_ff_points_for'         // Points scored (float)
'_ff_points_against'     // Points allowed (float)
'_ff_rank'               // Current rank/seed
'_ff_logo_url'           // Team logo URL
'_ff_team_color'         // Team color hex
'_ff_roster_data'        // JSON roster data
'_ff_last_sync'          // Last sync datetime
```

**Enhanced Admin Columns:**
- Provider (with icon: ESPN red chart, Yahoo purple star)
- League Name
- Record (W-L-T format)
- Points (For / Against)
- Season
- Last Sync (human-readable time ago)

**Enhanced Meta Box:**
- Team overview with provider badge
- League and season info
- Last sync timestamp
- Provider-specific IDs (read-only)
- Full record display
- Points statistics
- Quick action: "View on ESPN" button

**Helper Methods:**
```php
WP_MCP_AI_Fantasy_Team_CPT::get_team_provider( $post_id )
// Returns: 'espn', 'yahoo', or ''

WP_MCP_AI_Fantasy_Team_CPT::get_espn_ids( $post_id )
// Returns: array('league_id' => int, 'team_id' => int) or false

WP_MCP_AI_Fantasy_Team_CPT::get_yahoo_ids( $post_id )
// Returns: array('league_id' => int, 'team_id' => int) or false
```

### 4. NEW: Fantasy Player CPT

**Post Type:** `ff_player`  
**Purpose:** Track individual NFL players across providers

**Meta Fields:**
```php
'_ff_player_id'          // Generic player ID
'_ff_provider'           // 'espn' or 'yahoo'
'_ff_espn_player_id'     // ESPN player ID
'_ff_yahoo_player_id'    // Yahoo player ID
'_ff_position'           // Player position (QB, RB, WR, TE, K, D/ST)
'_ff_pro_team'           // Pro team name
'_ff_pro_team_abbrev'    // Pro team abbreviation (e.g., 'SF')
'_ff_player_status'      // 'active', 'injured', 'out'
'_ff_injury_status'      // Injury designation (Q, D, IR, O)
'_ff_season'             // Season year
'_ff_total_points'       // Total fantasy points (float)
'_ff_average_points'     // Average points per game (float)
'_ff_games_played'       // Number of games played
'_ff_on_watchlist'       // Boolean: on watchlist?
'_ff_watchlist_notes'    // User notes about player
'_ff_last_sync'          // Last sync datetime
```

**Taxonomy:**
- `ff_position` - Position taxonomy (QB, RB, WR, TE, K, D/ST)
- Automatically created on CPT registration

**Admin UI:**
- Position-based filtering
- Sortable average points column
- Injury status with color coding (red for injured, green for active)
- Watchlist indicator (gold star icon)
- Quick view of team abbreviation

**Watchlist System:**
- Checkbox to add/remove from watchlist
- Notes field for tracking thoughts/strategies
- Visual indicator in admin list
- Can be used for waiver wire targeting

## Settings Integration

### Fantasy Football Settings Page

**Location:** `edit.php?post_type=ff_team&page=fantasy-football-settings`

**Planned ESPN Section:**
- ESPN S2 Cookie (encrypted storage)
- SWID Cookie (encrypted storage)
- Default ESPN League ID
- Auto-sync settings
- Cache duration configuration
- Connection test button

### Tools Configuration

ESPN tools will be added to the Fantasy Football Settings tools list:

```php
'espn_fantasy_get_league' => 'ESPN: Get League Info',
'espn_fantasy_get_teams' => 'ESPN: Get Teams',
'espn_fantasy_get_standings' => 'ESPN: Get Standings',
'espn_fantasy_get_roster' => 'ESPN: Get Roster',
'espn_fantasy_sync_league' => 'ESPN: Sync League',
'espn_fantasy_analyze_lineup' => 'ESPN: Analyze Lineup',
```

## Usage Examples

### Example 1: Get League Information

**AI Chat Request:**
> "Show me information about my ESPN fantasy league 387659 for 2024"

**Tool Execution:**
```php
espn_fantasy_get_league(
    league_id: 387659,
    season: 2024
)
```

**Response:**
> "Retrieved league information for 'My Awesome League' (2024 season). This is a 10-team league using Points Per Reception scoring, currently in week 12 of the regular season. Playoffs start in week 14 with 6 teams."

### Example 2: Sync League to WordPress

**AI Chat Request:**
> "Import my ESPN fantasy league 387659 into WordPress with all rosters"

**Tool Execution:**
```php
espn_fantasy_sync_league(
    league_id: 387659,
    season: 2024,
    sync_rosters: true,
    update_existing: true
)
```

**Response:**
> "Synced league 'My Awesome League'. Created 10 teams. All team rosters have been imported and are available in the Fantasy Teams section."

### Example 3: Analyze Lineup

**AI Chat Request:**
> "How many points did I leave on the bench in week 10? League 387659, team 3"

**Tool Execution:**
```php
espn_fantasy_analyze_lineup(
    league_id: 387659,
    team_id: 3,
    season: 2024,
    week: 10
)
```

**Response:**
> "Lineup analysis for Week 10: You could have scored 23.40 more points with an optimal lineup. Your lineup efficiency was 85.3%. Suggested changes: Start Justin Jefferson (WR) - scored 24.80 points instead of current WR2."

## Security Considerations

### Authentication
- ESPN S2 and SWID cookies stored in WordPress options
- Encrypted storage using WordPress salts
- Never exposed in frontend JavaScript
- Cookies only sent to ESPN API endpoints
- Per-user capability checks before API calls

### Data Validation
- All league/team/player IDs validated as integers
- Season years validated (2000-2100 range)
- Week numbers validated (1-18 range)
- Text fields sanitized with `sanitize_text_field()`
- URLs validated with `esc_url_raw()`

### Capability Checks
```php
// Read operations: 'read' capability
// Sync operations: 'edit_posts' capability
// Settings: 'manage_options' capability
```

### Rate Limiting
- Client-side: 20 requests/minute per site
- Respects ESPN's unofficial limits
- Exponential backoff on repeated failures
- Cache-first strategy to minimize API calls

## Testing Strategy

### Unit Tests (Planned)

**ESPN Client Tests:**
```php
test_get_league_with_valid_id()
test_get_league_with_invalid_id()
test_authentication_with_private_league()
test_rate_limiting_enforcement()
test_cache_functionality()
```

**Tool Tests:**
```php
test_espn_fantasy_get_league_execution()
test_espn_fantasy_sync_league_creates_posts()
test_espn_fantasy_analyze_lineup_calculation()
test_parameter_validation()
test_capability_checks()
```

**CPT Tests:**
```php
test_fantasy_team_cpt_registration()
test_provider_meta_fields()
test_espn_ids_retrieval()
test_admin_columns_display()
```

### Integration Tests (Planned)

- Test full sync workflow with public league
- Verify roster data format and storage
- Test lineup analysis with known dataset
- Validate admin UI rendering
- Check multisite compatibility

## Performance Optimizations

### Caching Strategy

**Transient-Based Caching:**
- League info: 15 minutes
- Team data: 15 minutes
- Roster data: 15 minutes (shorter during game days)
- Standings: 15 minutes

**Cache Keys:**
```php
wp_mcp_ai_espn_league_{league_id}_{season}
wp_mcp_ai_espn_teams_{league_id}_{season}
wp_mcp_ai_espn_roster_{league_id}_{season}_{team_id}_{week}
wp_mcp_ai_espn_standings_{league_id}_{season}
```

**Cache Busting:**
```php
// Manual clear
$client = new WP_MCP_AI_ESPN_Fantasy_Client();
$client->clear_cache();

// Automatic expiration (15 minutes)
// Can be filtered:
add_filter('wp_mcp_ai_espn_cache_duration', function() {
    return 1800; // 30 minutes
});
```

### Database Queries

**Optimized Meta Queries:**
```php
// Finding existing team by ESPN IDs
meta_query with indexed keys:
- _ff_provider
- _ff_espn_league_id
- _ff_espn_team_id
- _ff_season
```

**Bulk Operations:**
- Batch roster imports use single query per team
- Standings retrieved in single API call
- Minimal meta updates during sync

## Troubleshooting

### Common Issues

**Issue: "Authentication failed. This may be a private league"**
- Solution: Add ESPN S2 and SWID cookies in settings or tool parameters

**Issue: "Rate limit exceeded"**
- Solution: Wait 1 minute, or increase cache duration

**Issue: "League not found"**
- Solution: Verify league ID and season year are correct

**Issue: "Fantasy Football Toolkit is not enabled"**
- Solution: Enable in Settings → NV oOS → Enable Fantasy Football

### Debug Mode

Enable WordPress debug logging:
```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

ESPN client logs to WordPress debug.log:
- API request URLs
- Response codes
- Error messages
- Cache hits/misses

## Future Enhancements

### Phase 7: Additional Tools (Proposed)
- `espn_fantasy_get_free_agents` - Available free agents
- `espn_fantasy_get_player_stats` - Individual player statistics
- `espn_fantasy_get_projections` - Player projections (if available)
- `espn_fantasy_compare_teams` - Head-to-head comparison
- `espn_fantasy_trade_analyzer` - Analyze trade proposals

### Phase 8: UI Components (Proposed)
- Shortcode for displaying league standings
- Elementor widget for team roster
- Block editor block for player cards
- Dashboard widget for quick stats

### Phase 9: Automation (Proposed)
- WP-Cron daily sync schedule
- Weekly lineup analysis reports
- Injury report notifications
- Waiver wire suggestions

### Phase 10: Advanced Analytics (Proposed)
- Season-long trend analysis
- Playoff probability calculator
- Strength of schedule calculator
- Trade value charts
- Draft analysis tools

## Conclusion

The ESPN Fantasy Football integration provides a robust, enterprise-ready solution for managing fantasy football data in WordPress. With dual-provider support, comprehensive data storage, advanced analytics, and a focus on security and performance, the toolkit is ready for use by individual fantasy managers, league commissioners, and fantasy sports content creators.

The modular architecture allows for easy expansion, and the consistent API design ensures a smooth user experience whether accessing data via AI chat, REST API, or WordPress admin interface.

## Support Resources

- **GitHub Issues:** https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues
- **Documentation:** `docs/fantasy-football-espn.md` (to be created)
- **Settings:** WP Admin → Fantasy Teams → Settings
- **Research:** WP Admin → Fantasy Teams → Research

---

**Version:** 1.0.0  
**Last Updated:** 2026-02-03  
**Authors:** NV Digital Solutions Team + GitHub Copilot  
**License:** GPLv3
