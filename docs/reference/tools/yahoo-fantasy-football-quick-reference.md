# Yahoo Fantasy Football Toolkit - Quick Reference

## Quick Start (3 Steps)

1. **Configure Yahoo API Credentials**
   - Get Client ID and Secret from https://developer.yahoo.com/apps/
   - Add to WordPress: Settings → NV oOS → Integrations

2. **Authenticate User**
   - Call `yahoo_ff_auth` with action "get_auth_url"
   - User visits URL to grant permission
   - Tokens stored automatically

3. **Use Fantasy Tools**
   - All 6 tools now available for that user
   - Tokens auto-refresh when needed

---

## Tools at a Glance

| Tool | Purpose | Key Parameters |
|------|---------|----------------|
| **yahoo_ff_auth** | Authenticate user | action (get_auth_url/get_status/revoke) |
| **yahoo_ff_get_leagues** | List user's leagues | season (optional) |
| **yahoo_ff_get_roster** | Get team roster | league_key, week (optional) |
| **yahoo_ff_get_player_stats** | Player statistics | league_key, player_key, week (optional) |
| **yahoo_ff_trade_analyzer** | Analyze trades | league_key, team_a_players[], team_b_players[] |
| **yahoo_ff_league_standings** | League standings | league_key, chart_type (bar/radar) |

---

## Common Workflows

### Get League Standings
```json
1. yahoo_ff_get_leagues → get league_key
2. yahoo_ff_league_standings: { "league_key": "nfl.l.123456", "chart_type": "bar" }
```

### Analyze a Trade
```json
1. yahoo_ff_get_leagues → get league_key  
2. Search players → get player_keys
3. yahoo_ff_trade_analyzer: {
     "league_key": "nfl.l.123456",
     "team_a_players": ["nfl.p.31045"],
     "team_b_players": ["nfl.p.30972", "nfl.p.30123"]
   }
```

### Check Weekly Roster
```json
1. yahoo_ff_get_leagues → get league_key
2. yahoo_ff_get_roster: { "league_key": "nfl.l.123456", "week": 7 }
```

---

## Chart.js Visualizations

### Trade Analyzer
- **Bar Chart**: Side-by-side comparison of total fantasy points
- **Colors**: Red (Team A giving), Blue (Team B receiving)
- **Responsive**: Auto-scales to container

### League Standings
- **Bar Chart**: Points For vs Points Against for all teams
- **Radar Chart**: Multi-metric analysis (top 5 teams only)
- **Interactive**: Hover for exact values

---

## Error Handling

| Error | Solution |
|-------|----------|
| "not authenticated" | Call `yahoo_ff_auth` first |
| "token expired" | Call `yahoo_ff_get_leagues` to auto-refresh |
| "credentials not configured" | Admin must add Yahoo API keys |
| "league key required" | Get from `yahoo_ff_get_leagues` first |

---

## Best Practices

### For Assistants
✅ Check auth status before operations  
✅ Get leagues list first for context  
✅ Use visualizations for comparisons  
✅ Explain trade recommendations  
✅ Cache league_key during conversation  

### For Developers
✅ Store tokens per-user securely  
✅ Auto-refresh tokens when expired  
✅ Handle rate limits gracefully  
✅ Validate all user inputs  
✅ Never expose Client Secret  

---

## Configuration Requirements

**WordPress Settings:**
- Yahoo Client ID (Consumer Key)
- Yahoo Client Secret  
Location: Settings → NV oOS → Integrations

**User Authentication:**
- Per-user OAuth tokens (automatic)
- Stored in user meta (secure)
- Auto-refresh on expiration

**Optional:**
- Chart.js library (included)
- Modern browser for visualizations

---

## Industry Standards Implemented

Based on research of FantasyPros, Fantalyst, and FantasyLife:

✅ OAuth 2.0 authentication  
✅ League synchronization  
✅ Multi-league support  
✅ Trade value analysis  
✅ Visual data representations  
✅ Player statistics  
✅ League standings  

---

## Technical Details

**Toolkit ID**: `fantasy_football`  
**Tool Group**: `external-tools`  
**Capabilities**: read-only, external-api, requires-credentials  
**API Provider**: Yahoo Fantasy Sports  
**Chart Library**: Chart.js 4.4.7  

---

## Quick Links

- **Full Documentation**: docs/tools/yahoo-fantasy-football-toolkit.md
- **Yahoo API Docs**: https://developer.yahoo.com/fantasysports/guide/
- **Yahoo OAuth Guide**: https://developer.yahoo.com/oauth2/guide/
- **Chart.js Docs**: https://www.chartjs.org/

---

**Version**: 1.0.0  
**Last Updated**: February 2026
