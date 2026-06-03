# Yahoo Fantasy Football Toolkit Documentation

## Overview

The Yahoo Fantasy Football Toolkit provides comprehensive integration with Yahoo Fantasy Sports API, enabling AI assistants to help users manage their fantasy football leagues, analyze player performance, evaluate trades, and visualize league data.

## Features

### Industry-Standard Capabilities

Based on research of leading fantasy football platforms (FantasyPros, Fantalyst, FantasyLife), the toolkit implements:

1. **League Synchronization** - Direct integration with Yahoo Fantasy Sports API
2. **OAuth 2.0 Authentication** - Secure user authorization
3. **Roster Management** - View and analyze team rosters
4. **Player Statistics** - Real-time player performance data
5. **Trade Analysis** - Visual trade comparison with recommendations
6. **League Standings** - Interactive charts showing team rankings
7. **Multi-league Support** - Manage multiple leagues simultaneously

### Visual Analytics (Chart.js Integration)

The toolkit leverages Chart.js to provide rich visualizations:
- Trade comparison bar charts
- League standings bar and radar charts
- Player performance trend analysis
- Responsive HTML charts that can be embedded or exported

## Tools

### 1. yahoo_ff_auth - Yahoo Fantasy Sports Authentication

Manages OAuth 2.0 authentication flow for Yahoo Fantasy Sports API.

**Parameters:**
- `action` (string) - Action to perform: "get_auth_url", "get_status", or "revoke"
- `callback_url` (string, optional) - OAuth callback URL

**Returns:**
- Authorization URL for user consent
- Authentication status
- Token expiration information

**Example Usage:**
```json
{
  "action": "get_auth_url",
  "callback_url": "https://yoursite.com/callback"
}
```

**Configuration Required:**
- Yahoo Client ID (Consumer Key)
- Yahoo Client Secret

Set these in: **Settings → NV oOS → Integrations**

---

### 2. yahoo_ff_get_leagues - Get Fantasy Football Leagues

Retrieves all fantasy football leagues for the authenticated user.

**Parameters:**
- `season` (integer, optional) - NFL season year (defaults to current year)
- `game_key` (string, optional) - Yahoo game key (defaults to "nfl")

**Returns:**
- Array of leagues with details:
  - League ID and key
  - League name
  - Season year
  - Scoring type (Standard, PPR, etc.)
  - Number of teams
  - Current week
  - League URL

**Example Usage:**
```json
{
  "season": 2025,
  "game_key": "nfl"
}
```

---

### 3. yahoo_ff_get_roster - Get Team Roster

Retrieves detailed roster information for a fantasy team.

**Parameters:**
- `league_key` (string, required) - Yahoo league key (e.g., "nfl.l.123456")
- `team_key` (string, optional) - Yahoo team key (defaults to user's team)
- `week` (integer, optional) - Week number (defaults to current week)

**Returns:**
- Team name and key
- Array of players with:
  - Player name and ID
  - Position and eligible positions
  - NFL team
  - Bye week
  - Injury status
  - Selected position (starting/bench)

**Example Usage:**
```json
{
  "league_key": "nfl.l.123456",
  "week": 5
}
```

---

### 4. yahoo_ff_get_player_stats - Get Player Statistics

Retrieves fantasy statistics for a specific player.

**Parameters:**
- `league_key` (string, required) - Yahoo league key
- `player_key` (string, required) - Yahoo player key (e.g., "nfl.p.12345")
- `week` (integer, optional) - Week number (omit for season stats)

**Returns:**
- Player name, position, NFL team
- Fantasy points
- Detailed statistics:
  - Passing yards/TDs
  - Rushing yards/TDs
  - Receptions/receiving yards/TDs
  - Other scoring categories

**Example Usage:**
```json
{
  "league_key": "nfl.l.123456",
  "player_key": "nfl.p.31045",
  "week": 5
}
```

---

### 5. yahoo_ff_trade_analyzer - Trade Analysis with Visualizations

Analyzes trade proposals by comparing player fantasy points and generates visual comparison charts.

**Parameters:**
- `league_key` (string, required) - Yahoo league key
- `team_a_players` (array, required) - Player keys Team A is offering
- `team_b_players` (array, required) - Player keys Team B is offering
- `weeks_to_analyze` (integer, optional) - Number of past weeks to analyze (default: 4)
- `include_chart` (boolean, optional) - Generate Chart.js visualization (default: true)
- `chart_type` (string, optional) - "bar" for comparison or "line" for trends

**Returns:**
- Trade analysis with:
  - Total fantasy points for each side
  - Point difference
  - Percentage advantage/disadvantage
  - Recommendation (favorable/fair/unfavorable)
  - Player-by-player breakdown
- Interactive HTML chart (if requested)

**Example Usage:**
```json
{
  "league_key": "nfl.l.123456",
  "team_a_players": ["nfl.p.31045", "nfl.p.30123"],
  "team_b_players": ["nfl.p.30972"],
  "include_chart": true,
  "chart_type": "bar"
}
```

**Trade Recommendation Logic:**
- **Fair Trade**: Point difference < 10%
- **Favorable**: Receiving side has >10% more value
- **Unfavorable**: Giving up >10% more value

---

### 6. yahoo_ff_league_standings - League Standings Visualizer

Retrieves league standings and generates interactive visualizations.

**Parameters:**
- `league_key` (string, required) - Yahoo league key
- `include_chart` (boolean, optional) - Generate visualization (default: true)
- `chart_type` (string, optional) - "bar" for points comparison or "radar" for top 5 analysis

**Returns:**
- Array of teams with:
  - Rank
  - Team name
  - Wins/Losses/Ties
  - Points For/Against
- Interactive bar or radar chart (if requested)

**Example Usage:**
```json
{
  "league_key": "nfl.l.123456",
  "include_chart": true,
  "chart_type": "radar"
}
```

**Chart Types:**
- **Bar Chart**: Compares Points For and Points Against for all teams
- **Radar Chart**: Multi-metric analysis for top 5 teams (wins, points for, points against)

---

## Setup Guide

### Prerequisites

1. **Yahoo Developer Account**
   - Visit: https://developer.yahoo.com/apps/
   - Create an application
   - Note your Client ID (Consumer Key) and Client Secret

2. **WordPress Site Requirements**
   - WordPress 6.0+
   - PHP 7.4+
   - NV oOS plugin installed and activated

### Configuration Steps

#### Step 1: Register Yahoo Application

1. Go to Yahoo Developer Network: https://developer.yahoo.com/apps/
2. Click "Create an App"
3. Fill in application details:
   - **Application Name**: Your Site Name Fantasy Assistant
   - **Description**: Fantasy Football management assistant
   - **Permissions**: Fantasy Sports (Read)
4. Set Redirect URI (OAuth callback):
   - Format: `https://yoursite.com/wp-admin/admin.php?page=wp-mcp-ai-yahoo-callback`
5. Save and note your Client ID and Client Secret

#### Step 2: Configure Plugin

1. In WordPress admin, go to **Settings → NV oOS → Integrations**
2. Add a new integration section or use existing external APIs section
3. Add:
   - **Yahoo Client ID**: Your Consumer Key
   - **Yahoo Client Secret**: Your Client Secret
4. Save settings

#### Step 3: Authenticate User

Users must authenticate before using fantasy football tools:

1. Call the `yahoo_ff_auth` tool with action "get_auth_url"
2. Direct user to the returned authorization URL
3. User grants permission on Yahoo
4. Yahoo redirects to callback URL with authorization code
5. Plugin exchanges code for access and refresh tokens
6. Tokens are stored securely per user

**Example Assistant Prompt:**
```
To access your Yahoo Fantasy Football leagues, I need your permission. 
Please visit this URL to authorize access: [authorization_url]

After authorization, you'll be redirected back and I'll be able to help 
you manage your fantasy teams!
```

---

## Usage Examples

### Example 1: Check League Standings

**User:** "Show me the current standings in my fantasy football league"

**Assistant Tool Calls:**
1. `yahoo_ff_get_leagues` (to find league_key)
2. `yahoo_ff_league_standings` with the league_key and `chart_type: "bar"`

**Result:** League standings table + interactive bar chart showing Points For vs Points Against

---

### Example 2: Evaluate a Trade

**User:** "Should I trade Patrick Mahomes for Tyreek Hill and Travis Kelce?"

**Assistant Tool Calls:**
1. `yahoo_ff_get_leagues` (to get league context)
2. Search for player keys
3. `yahoo_ff_trade_analyzer` with:
   - `team_a_players`: [Mahomes player key]
   - `team_b_players`: [Hill player key, Kelce player key]
   - `include_chart`: true

**Result:** 
- Point-by-point comparison
- Trade recommendation with percentage analysis
- Visual bar chart comparing total fantasy points
- "This trade is FAVORABLE for you - you would gain approximately 15.3% more value"

---

### Example 3: Review Weekly Lineup

**User:** "Show me my starting lineup for week 7"

**Assistant Tool Calls:**
1. `yahoo_ff_get_leagues` (to find league_key)
2. `yahoo_ff_get_roster` with `week: 7`

**Result:** Complete roster with starting/bench designations, bye weeks, and injury statuses

---

### Example 4: Player Performance Analysis

**User:** "How many fantasy points did Josh Allen score last week?"

**Assistant Tool Calls:**
1. Get league context
2. Get player key for Josh Allen
3. `yahoo_ff_get_player_stats` with the player_key and current week

**Result:** Fantasy points total + detailed stats breakdown

---

## Best Practices

### For Plugin Developers

1. **Token Management**
   - Access tokens expire after ~1 hour
   - Refresh tokens automatically using the stored refresh_token
   - The `yahoo_ff_get_leagues` tool includes auto-refresh logic
   - Other tools rely on valid tokens - recommend calling get_leagues first

2. **Error Handling**
   - Check for authentication errors (token expired/invalid)
   - Direct users to re-authenticate when necessary
   - Validate league_key and player_key formats

3. **Rate Limiting**
   - Yahoo API has rate limits
   - Cache league and roster data when possible
   - Don't make excessive requests for the same data

4. **Security**
   - Store tokens per-user in user meta
   - Never expose Client Secret to frontend
   - Use WordPress nonces for callback handling

### For AI Assistants

1. **Authentication First**
   - Always check authentication status before other operations
   - Guide users through OAuth flow when not authenticated
   - Provide clear instructions with authorization URL

2. **Context Gathering**
   - Get leagues list first to understand user's fantasy context
   - Cache league_key for subsequent operations in conversation
   - Ask which league if user has multiple

3. **Visual Data**
   - Use Chart.js visualizations for trade analysis and standings
   - Bar charts for direct comparisons
   - Radar charts for multi-metric team analysis
   - Explain what the charts show

4. **Trade Analysis**
   - Consider analyzing 4+ weeks of data for trends
   - Explain the recommendation reasoning
   - Note any caveats (injuries, bye weeks, schedule strength)

---

## API Reference

### Yahoo Fantasy Sports API

The toolkit integrates with these Yahoo API endpoints:

- **OAuth**: `https://api.login.yahoo.com/oauth2/`
- **Fantasy API Base**: `https://fantasysports.yahooapis.com/fantasy/v2/`

### Endpoints Used

1. `/users;use_login=1/games/leagues` - List user's leagues
2. `/league/{league_key}/teams` - Get teams in league
3. `/team/{team_key}/roster` - Get team roster
4. `/league/{league_key}/players;player_keys={keys}/stats` - Get player stats
5. `/league/{league_key}/standings` - Get league standings

### Response Format

Yahoo API returns JSON responses with nested structure. The toolkit parses and flattens this into clean arrays.

---

## Troubleshooting

### "You must authenticate with Yahoo Fantasy Sports first"

**Solution:** User needs to authenticate via `yahoo_ff_auth` tool with action "get_auth_url"

### "Yahoo API credentials are not configured"

**Solution:** Admin needs to add Yahoo Client ID and Client Secret in plugin settings

### "Access token expired"

**Solution:** Call `yahoo_ff_get_leagues` tool first - it will auto-refresh the token

### "League key is required"

**Solution:** Get league_key from `yahoo_ff_get_leagues` response first

### Charts not displaying

**Solution:** Ensure Chart.js is loaded: `assets/js/vendor/chart.min.js` must exist

---

## Technical Details

### Token Storage

Tokens are stored in WordPress user meta:
- `wp_mcp_ai_yahoo_access_token` - Short-lived access token
- `wp_mcp_ai_yahoo_refresh_token` - Long-lived refresh token
- `wp_mcp_ai_yahoo_token_expires` - Unix timestamp of expiration
- `wp_mcp_ai_yahoo_oauth_state` - CSRF protection state token

### Chart.js Integration

Charts are generated as standalone HTML documents with embedded Chart.js:
- Include Chart.js library from `assets/js/vendor/chart.min.js`
- Generate responsive charts with proper sizing
- Support multiple chart types (bar, line, radar)
- Can be embedded in iframes or saved as HTML files

### Capability Flags

All tools implement these flags:
- `read-only` - Tools only read data, no modifications
- `external-api` - Makes calls to Yahoo Fantasy Sports API
- `requires-credentials` - Requires OAuth tokens
- `requires-capability` - Requires WordPress user with 'read' capability
- `network-dependent` - Requires internet connectivity

### Toolkit Metadata

All tools are grouped under the `fantasy_football` toolkit with:
- **Pattern Compatibility**: `event_driven`
- **Profession Tags**: `fantasy_sports_manager`, `sports_analyst`
- **Risk Level**: `info`

---

## Future Enhancements

Potential additions for version 2.0:

1. **Waiver Wire Recommendations** - AI-powered waiver wire targets
2. **Start/Sit Advisor** - Weekly lineup optimization
3. **Player Projections** - Week-ahead fantasy point projections
4. **Draft Assistant** - Live draft recommendations
5. **Matchup Analysis** - Weekly opponent analysis
6. **Historical Trends** - Multi-season player performance
7. **League Insights** - AI-powered league insights and patterns
8. **Mobile Notifications** - Alert users about injuries, transactions

---

## Resources

- **Yahoo Fantasy Sports API**: https://developer.yahoo.com/fantasysports/guide/
- **Yahoo OAuth Guide**: https://developer.yahoo.com/oauth2/guide/
- **Chart.js Documentation**: https://www.chartjs.org/docs/
- **Fantasy Football Standards**: Research from FantasyPros, Fantalyst, FantasyLife

---

## Support

For issues or questions:
1. Check the troubleshooting section above
2. Review Yahoo Developer Network documentation
3. Consult the main NV oOS documentation
4. GitHub Issues: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues

---

**Last Updated:** February 2026  
**Version:** 1.0.0  
**Toolkit:** fantasy_football
