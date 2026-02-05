# Fantasy Football Settings & Research Tools

## Overview

This document covers the **Settings Page** and **Research & Add** functionality for the Fantasy Football toolkit, completing the full feature set requested in the requirements.

## New Features

### 1. Fantasy Football Settings Page

A dedicated WordPress admin settings page for managing all Fantasy Football toolkit configuration.

**Location:** Assistants → FF Settings

**Features:**
- Yahoo Fantasy Sports API credentials management
- Default preferences configuration
- Team branding defaults
- Report generation preferences
- Quick links to resources

#### Settings Sections

**Yahoo Fantasy Sports API:**
- Yahoo Client ID (Consumer Key)
- Yahoo Client Secret (password field, secured)
- Instructions and link to Yahoo Developer Network

**Default Preferences:**
- Default Season (current year)
- Auto-Sync Teams (daily synchronization toggle)

**Team Branding Defaults:**
- Default Logo Style (modern/classic/minimalist/mascot/emblem)
- Default Team Color (color picker)

**Report Preferences:**
- Include Charts by Default (Chart.js visualizations)
- Include AI Analysis by Default (AI-powered insights)

#### Settings API

**Option Key:** `wp_mcp_ai_fantasy_football_settings`  
**Option Group:** `wp_mcp_ai_fantasy_football`

**Accessing Settings:**
```php
// Get a specific setting
$client_id = WP_MCP_AI_Fantasy_Football_Settings::get_setting( 'yahoo_client_id' );

// Get default logo style
$style = WP_MCP_AI_Fantasy_Football_Settings::get_setting( 'default_logo_style', 'modern' );
```

**Settings Array Structure:**
```php
array(
    'yahoo_client_id'          => string,
    'yahoo_client_secret'      => string,
    'default_season'           => string,
    'auto_sync'                => boolean,
    'default_logo_style'       => string,
    'default_team_color'       => string (hex color),
    'include_charts_default'   => boolean,
    'include_analysis_default' => boolean,
)
```

#### Security

- All inputs sanitized before saving
- Yahoo Client Secret stored as password field
- Capability check: `manage_options` required
- Settings saved via WordPress Settings API
- Hex color validation for team colors

---

### 2. Player Research & Add Tool

A comprehensive tool for researching fantasy football players, comparing statistics, and managing watchlists.

**Tool Slug:** `ff_player_research`  
**Toolkit:** fantasy_football  
**Group:** external-tools

#### Actions

The tool supports three main actions:

##### Action 1: Search Players

Search for players by name, position, team, or availability.

**Parameters:**
```json
{
  "action": "search",
  "query": "Patrick Mahomes",
  "position": "QB",
  "team": "KC",
  "availability": "available",
  "sort_by": "rank",
  "limit": 20,
  "league_key": "nfl.l.123456"
}
```

**Response:**
```json
{
  "action": "search",
  "criteria": {
    "query": "Patrick Mahomes",
    "position": "QB",
    "availability": "available"
  },
  "count": 5,
  "players": [
    {
      "player_key": "nfl.p.31045",
      "name": "Patrick Mahomes",
      "position": "QB",
      "team": "KC",
      "status": "Healthy",
      "fantasy_points": 342.5,
      "rank": 1,
      "percent_owned": 99.8,
      "availability": "taken"
    }
  ]
}
```

**Search Options:**
- `query` - Free text search (player name)
- `position` - Filter by position (QB/RB/WR/TE/K/DEF)
- `team` - Filter by NFL team (e.g., "KC", "SF")
- `availability` - Filter by status (all/available/taken)
- `sort_by` - Sort results (rank/points/name/percent_owned)
- `limit` - Max results (1-50, default 20)
- `league_key` - League-specific data

##### Action 2: Compare Players

Compare statistics between multiple players side-by-side.

**Parameters:**
```json
{
  "action": "compare",
  "player_keys": ["nfl.p.31045", "nfl.p.30123", "nfl.p.30972"],
  "league_key": "nfl.l.123456"
}
```

**Response:**
```json
{
  "action": "compare",
  "player_keys": ["nfl.p.31045", "nfl.p.30123", "nfl.p.30972"],
  "players": [
    {
      "player_key": "nfl.p.31045",
      "name": "Patrick Mahomes",
      "position": "QB",
      "fantasy_points": 342.5,
      "games_played": 16,
      "avg_points": 21.4,
      "touchdowns": 35,
      "yards": 4839
    }
  ],
  "analysis": {
    "highest_scorer": "Patrick Mahomes",
    "most_consistent": "Christian McCaffrey",
    "recommendation": "Patrick Mahomes has the highest fantasy point total..."
  }
}
```

**Comparison Metrics:**
- Total fantasy points
- Games played
- Average points per game
- Position-specific stats (TDs, yards, receptions)
- AI-powered analysis

##### Action 3: Add to Watchlist

Save players to a personal watchlist for tracking.

**Parameters:**
```json
{
  "action": "add_to_watchlist",
  "player_keys": ["nfl.p.31045", "nfl.p.30972"]
}
```

**Response:**
```json
{
  "action": "add_to_watchlist",
  "added": 2,
  "total_watchlist": 8,
  "watchlist": ["nfl.p.31045", "nfl.p.30972", "..."]
}
```

**Watchlist Features:**
- Stored per-user in WordPress user meta
- Persistent across sessions
- No duplicate entries
- Returns updated watchlist count

#### Data Storage

**User Meta Key:** `wp_mcp_ai_ff_watchlist`  
**Data Type:** Array of Yahoo player keys  
**Scope:** Per-user

**Accessing Watchlist:**
```php
// Get user's watchlist
$watchlist = get_user_meta( $user_id, 'wp_mcp_ai_ff_watchlist', true );

// Check if player is on watchlist
$is_watched = in_array( $player_key, $watchlist );
```

#### Search Filters

**Position Filters:**
- QB - Quarterbacks
- RB - Running Backs
- WR - Wide Receivers
- TE - Tight Ends
- K - Kickers
- DEF - Team Defense/Special Teams

**Availability Filters:**
- `all` - All players regardless of roster status
- `available` - Only available free agents
- `taken` - Only rostered players

**Sort Options:**
- `rank` - Expert consensus rankings
- `points` - Fantasy points scored
- `name` - Alphabetical by name
- `percent_owned` - Ownership percentage

#### Integration with Yahoo API

The tool is designed to integrate with Yahoo Fantasy Sports API:
- Player search endpoints
- League-specific availability
- Real-time statistics
- Injury status updates
- Expert rankings

**Note:** Current implementation includes simulated data structure for demonstration. Full Yahoo API integration requires:
1. Yahoo API credentials configured in settings
2. User OAuth authentication
3. Active API requests to Yahoo endpoints

---

## Usage Examples

### Example 1: Configure Settings

**Admin Workflow:**
1. Navigate to **Assistants → FF Settings**
2. Enter Yahoo Client ID and Secret
3. Set default season to 2025
4. Enable auto-sync
5. Choose "modern" logo style
6. Set team color to #1E90FF (blue)
7. Enable charts and analysis by default
8. Save settings

**Result:** All Fantasy Football tools will use these defaults.

---

### Example 2: Search for Available Quarterbacks

**User Request:** "Show me available quarterbacks ranked in the top 20"

**Tool Call:**
```json
{
  "action": "search",
  "position": "QB",
  "availability": "available",
  "sort_by": "rank",
  "limit": 20
}
```

**Result:** List of top 20 available QBs with stats and rankings.

---

### Example 3: Compare Running Backs for Trade

**User Request:** "Compare Christian McCaffrey and Saquon Barkley"

**Workflow:**
1. Search to get player keys
2. Call comparison tool:
```json
{
  "action": "compare",
  "player_keys": ["nfl.p.30123", "nfl.p.31860"]
}
```

**Result:** Side-by-side comparison with AI recommendation.

---

### Example 4: Build Watchlist

**User Request:** "Add Josh Allen and Travis Kelce to my watchlist"

**Workflow:**
1. Search to find player keys
2. Add to watchlist:
```json
{
  "action": "add_to_watchlist",
  "player_keys": ["nfl.p.30971", "nfl.p.30972"]
}
```

**Result:** Players added to personal watchlist, count updated.

---

## Admin Interface

### Settings Page UI

**Page Structure:**
```
Fantasy Football Settings
├── Yahoo Fantasy Sports API
│   ├── Yahoo Client ID [text field]
│   └── Yahoo Client Secret [password field]
├── Default Preferences
│   ├── Default Season [number field]
│   └── Auto-Sync Teams [checkbox]
├── Team Branding Defaults
│   ├── Default Logo Style [dropdown]
│   └── Default Team Color [color picker]
├── Report Preferences
│   ├── Include Charts [checkbox]
│   └── Include AI Analysis [checkbox]
└── Quick Links
    ├── Manage Fantasy Teams
    ├── Yahoo Developer Network
    └── Yahoo Fantasy Sports API Documentation
```

**Visual Design:**
- Clean, WordPress-native styling
- Organized into logical sections
- Help text and descriptions
- External links open in new tabs
- Success messages on save

---

## Best Practices

### For Plugin Administrators

1. **API Credentials**
   - Store securely in settings
   - Never commit credentials to code
   - Use password field for Client Secret
   - Rotate credentials periodically

2. **Default Preferences**
   - Set sensible defaults
   - Enable auto-sync for convenience
   - Choose appropriate branding style
   - Configure report preferences

3. **User Management**
   - Each user authenticates independently
   - Watchlists are per-user
   - Settings apply globally

### For AI Assistants

1. **Research Workflow**
   - Use search to find players
   - Filter by position/team for precision
   - Compare top candidates
   - Add interesting players to watchlist

2. **Watchlist Management**
   - Build watchlists for waiver targets
   - Track injured players
   - Monitor breakout candidates
   - Review before weekly adds

3. **Integration with Other Tools**
   - Use with `yahoo_ff_get_roster` to find needs
   - Combine with `yahoo_ff_trade_analyzer` for trades
   - Generate reports with watchlist players

---

## Technical Details

### Settings Page Class

**File:** `addons/pro/includes/admin/class-wp-mcp-ai-fantasy-football-settings.php`  
**Class:** `WP_MCP_AI_Fantasy_Football_Settings`

**Hooks:**
- `admin_menu` - Register settings page
- `admin_init` - Register settings with WordPress

**Capabilities Required:**
- `manage_options` - Only admins can change settings

**Sanitization:**
- Text fields: `sanitize_text_field()`
- Checkboxes: Boolean conversion
- Colors: `sanitize_hex_color()`

### Research Tool Class

**File:** `addons/pro/includes/tools/class-wp-mcp-ai-tool-ff-player-research.php`  
**Class:** `WP_MCP_AI_Tool_FF_Player_Research`

**Implements:**
- `WP_MCP_AI_Tool_Interface`
- `WP_MCP_AI_Tool_Capability_Flags_Interface`

**Capability Flags:**
- `read-only` - Does not modify league data
- `external-api` - Calls Yahoo Fantasy API
- `requires-credentials` - Needs OAuth tokens
- `requires-capability` - User must have `read` capability
- `network-dependent` - Requires internet

**Toolkit Metadata:**
- Toolkit: `fantasy_football`
- Pattern: `event_driven`
- Professions: `fantasy_sports_manager`, `sports_analyst`
- Risk Level: `info`

---

## Future Enhancements

### Phase 2 Features

1. **Advanced Search**
   - Free agent finder
   - Breakout candidate detection
   - Schedule-based rankings
   - Matchup analysis

2. **Watchlist Features**
   - Automatic alerts
   - Price change tracking
   - News integration
   - Expert commentary

3. **Comparison Tools**
   - Multi-week trends
   - Historical data
   - Projection integration
   - Rest-of-season outlook

4. **Settings Expansion**
   - Notification preferences
   - Custom scoring systems
   - Draft preferences
   - Trade alerts

---

## Troubleshooting

### Settings Page Not Visible

**Solution:** Ensure user has `manage_options` capability (admin role).

### Player Search Returns No Results

**Solutions:**
- Verify Yahoo API credentials configured
- Check user authentication status
- Confirm league_key is valid
- Try broader search criteria

### Watchlist Not Saving

**Solutions:**
- Verify user is logged in
- Check user meta permissions
- Ensure player_keys array is valid
- Clear WordPress cache

### Settings Not Applying

**Solutions:**
- Click "Save Changes" button
- Check for error messages
- Verify capability permissions
- Clear browser cache

---

## API Reference

### Settings Methods

```php
// Get setting value
WP_MCP_AI_Fantasy_Football_Settings::get_setting( $key, $default );

// Examples
$client_id = WP_MCP_AI_Fantasy_Football_Settings::get_setting( 'yahoo_client_id' );
$auto_sync = WP_MCP_AI_Fantasy_Football_Settings::get_setting( 'auto_sync', false );
```

### Watchlist Methods

```php
// Get user's watchlist
$watchlist = get_user_meta( $user_id, 'wp_mcp_ai_ff_watchlist', true );

// Add player to watchlist
$watchlist[] = $player_key;
update_user_meta( $user_id, 'wp_mcp_ai_ff_watchlist', $watchlist );

// Remove player from watchlist
$watchlist = array_diff( $watchlist, array( $player_key ) );
update_user_meta( $user_id, 'wp_mcp_ai_ff_watchlist', $watchlist );
```

---

## Resources

- **Main Toolkit Documentation**: `docs/tools/yahoo-fantasy-football-toolkit.md`
- **Extended Features**: `docs/tools/fantasy-football-extended-features.md`
- **Complete Guide**: `docs/tools/FANTASY_FOOTBALL_COMPLETE_IMPLEMENTATION.md`
- **Yahoo API**: https://developer.yahoo.com/fantasysports/guide/
- **Yahoo OAuth**: https://developer.yahoo.com/oauth2/guide/

---

**Version:** 1.1.0  
**Last Updated:** February 2026  
**Features Added:** Settings Page, Research & Add Tool  
**Status:** ✅ Complete
