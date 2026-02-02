# Fantasy Football Toolkit - Extended Features

## Overview

This document covers the extended features added to the Fantasy Football toolkit, including Custom Post Types, team branding, and document generation capabilities.

## New Features

### 1. Fantasy Team Custom Post Type (CPT)

A dedicated WordPress CPT for storing and managing fantasy football team data.

**Post Type Slug:** `ff_team`

**Features:**
- Store team information (league key, team key, owner)
- Track statistics (wins, losses, points for/against, rank)
- Team branding (logo URL, team color)
- Roster data storage
- Last sync timestamp

**Admin Interface:**
- Located under Assistants menu → Fantasy Teams
- Custom meta boxes for team info, statistics, and branding
- Custom admin columns showing league, season, record, rank, and points
- WordPress media library integration for team logos

**Meta Fields:**
- `_ff_league_key` - Yahoo league key
- `_ff_team_key` - Yahoo team key
- `_ff_team_name` - Team name
- `_ff_owner_name` - Owner/manager name
- `_ff_league_name` - League name
- `_ff_season` - Season year
- `_ff_wins` - Win count
- `_ff_losses` - Loss count
- `_ff_ties` - Tie count
- `_ff_points_for` - Points scored
- `_ff_points_against` - Points allowed
- `_ff_rank` - Current rank
- `_ff_logo_url` - Team logo URL
- `_ff_team_color` - Team primary color (hex)
- `_ff_roster_data` - Serialized roster data
- `_ff_last_sync` - Last sync timestamp

### 2. Team Logo Generator (`ff_generate_team_logo`)

AI-powered tool for creating custom fantasy football team logos.

**Parameters:**
- `team_name` (string, required) - Fantasy team name
- `style` (string) - Logo style: modern, classic, minimalist, mascot, emblem
- `colors` (string) - Preferred color scheme
- `theme` (string) - Theme or motif (e.g., "lions", "warriors")
- `provider` (string) - AI provider: openai or gemini
- `size` (string) - Image size (1024x1024, 1024x1792, 1792x1024)
- `save_to_team` (boolean) - Save logo to fantasy team post
- `team_post_id` (integer) - Team post ID to save logo to

**Features:**
- Uses DALL-E 3 or Gemini for high-quality logo generation
- Supports 5 distinct logo styles
- Custom color schemes and themes
- Automatic prompt engineering for sports-themed designs
- Option to save directly to fantasy team CPT
- Returns image URL and generation details

**Usage Example:**
```json
{
  "team_name": "Thunder Warriors",
  "style": "modern",
  "colors": "electric blue and silver",
  "theme": "lightning bolt",
  "provider": "openai",
  "size": "1024x1024",
  "save_to_team": true,
  "team_post_id": 123
}
```

**Styles Explained:**
- **Modern**: Sleek, contemporary design with bold lines
- **Classic**: Traditional sports team aesthetic with vintage elements
- **Minimalist**: Clean lines and simple shapes
- **Mascot**: Character-based logo with action pose
- **Emblem**: Shield or crest style with heraldic elements

### 3. League Report Generator (`ff_create_league_report`)

Creates comprehensive league reports with standings, statistics, and AI-powered analysis.

**Parameters:**
- `league_key` (string, required) - Yahoo league key
- `report_type` (string) - Type: weekly, season, standings
- `week` (integer) - Week number for weekly reports
- `include_charts` (boolean) - Include Chart.js visualizations
- `include_analysis` (boolean) - Include AI-generated insights
- `format` (string) - Output format: html or json

**Features:**
- Professional HTML report generation
- League standings table with formatted data
- AI-powered league analysis and insights
- Identifies highest scoring team
- Calculates league averages
- Responsive design for all devices
- Can return raw JSON data for custom processing

**Report Types:**
- **Weekly**: Recap of specific week's results
- **Season**: Full season summary with cumulative stats
- **Standings**: Current league standings snapshot

**AI Analysis Includes:**
- Highest scoring team identification
- Leader analysis (best record)
- League average calculations
- Performance insights
- Trend identification

**Usage Example:**
```json
{
  "league_key": "nfl.l.123456",
  "report_type": "standings",
  "include_charts": true,
  "include_analysis": true,
  "format": "html"
}
```

**HTML Report Features:**
- Clean, professional styling
- Responsive layout
- Sortable tables
- Analysis section with insights
- Print-friendly format
- Embeddable in WordPress posts/pages

## Integration with Existing Tools

### Works With:
- `yahoo_ff_auth` - Authentication required for API access
- `yahoo_ff_get_leagues` - Get league keys for reports
- `yahoo_ff_league_standings` - Source data for reports
- `generate_openai_image` - Used by logo generator
- `generate_gemini_image` - Alternative logo generation

### Data Flow:
1. User authenticates with `yahoo_ff_auth`
2. Get leagues with `yahoo_ff_get_leagues`
3. Create team CPT posts manually or via API
4. Generate logos with `ff_generate_team_logo`
5. Create reports with `ff_create_league_report`
6. View/manage teams in WordPress admin

## Setup Instructions

### 1. Enable Fantasy Football Module

The module is automatically loaded with the plugin. No additional configuration needed.

### 2. Create Fantasy Team Posts

**Via WordPress Admin:**
1. Go to Assistants → Fantasy Teams
2. Click "Add New"
3. Enter team information:
   - Title: Team display name
   - League Key: Yahoo league key
   - Team Key: Yahoo team key (optional)
   - Season, stats, branding info
4. Publish

**Via API/Tools:**
Fantasy teams can be created programmatically using WordPress REST API or custom tools.

### 3. Generate Team Logos

Use the `ff_generate_team_logo` tool:
```json
{
  "team_name": "My Team Name",
  "style": "modern",
  "colors": "blue and gold",
  "save_to_team": true,
  "team_post_id": 123
}
```

### 4. Create League Reports

Use the `ff_create_league_report` tool:
```json
{
  "league_key": "nfl.l.123456",
  "report_type": "standings",
  "include_charts": true,
  "include_analysis": true
}
```

## WordPress Integration

### Admin Menu Structure
```
Assistants (existing)
  ├── All Assistants
  ├── Add New
  ├── Categories
  ├── Tags
  └── Fantasy Teams ← NEW
       ├── All Teams
       └── Add New
```

### Custom Columns
The Fantasy Teams list view shows:
- Checkbox (bulk actions)
- Title (team name)
- League
- Season
- Record (W-L-T)
- Rank
- Points (PF / PA)
- Date

### Meta Boxes
Team edit screen includes:
- Team Information (main)
- Team Statistics (sidebar)
- Team Branding (sidebar)
- Featured Image (WordPress default, used for logos)

## Best Practices

### For Plugin Developers

1. **CPT Data Storage**
   - Use meta fields for structured data
   - Store roster data as serialized array
   - Update `_ff_last_sync` on data refresh
   - Use WordPress media library for logos

2. **Logo Generation**
   - Test both OpenAI and Gemini providers
   - Use specific themes for better results
   - Save logos to media library
   - Associate with team CPT via meta field

3. **Report Generation**
   - Cache standings data when possible
   - Generate reports on-demand
   - Provide both HTML and JSON formats
   - Include analysis for better insights

### For AI Assistants

1. **Team Management**
   - Create CPT posts for user's teams
   - Sync data regularly from Yahoo API
   - Generate logos for branding
   - Track season statistics

2. **Report Creation**
   - Generate weekly recaps automatically
   - Create season summaries at end
   - Include analysis for context
   - Share reports with league members

3. **Workflow Example**
   ```
   1. User authenticates with Yahoo
   2. Get user's leagues
   3. Create CPT post for each team
   4. Generate team logo
   5. Weekly: Create report and share
   6. Season end: Generate final summary
   ```

## Technical Architecture

### File Structure
```
includes/
└── fantasy-football/
    ├── fantasy-football-init.php         # Module initialization
    └── class-wp-mcp-ai-fantasy-team-cpt.php  # Team CPT class

includes/tools/
├── class-wp-mcp-ai-tool-yahoo-ff-*.php   # Yahoo API tools (6 files)
├── class-wp-mcp-ai-tool-ff-generate-team-logo.php
└── class-wp-mcp-ai-tool-ff-create-league-report.php
```

### Dependencies
- WordPress 6.0+
- PHP 7.4+
- Yahoo Fantasy Sports API credentials (for data sync)
- OpenAI or Gemini API key (for logo generation)

### Hooks & Filters
- `init` - Register CPT and meta
- `add_meta_boxes` - Add custom meta boxes
- `save_post_ff_team` - Save team meta data
- `manage_ff_team_posts_columns` - Customize admin columns
- `manage_ff_team_posts_custom_column` - Render column content

## Future Enhancements

### Phase 2 Additions (Planned)

1. **Draft Guide Generator**
   - Create PDF draft guides
   - Player rankings and tiers
   - Position scarcity analysis
   - Mock draft scenarios

2. **Player Research Tool**
   - Search players across leagues
   - Compare statistics
   - Injury reports integration
   - Expert rankings aggregation

3. **Social Media Graphics**
   - Matchup preview cards
   - Weekly results graphics
   - Championship celebration images
   - Trash talk templates

4. **Advanced Reports**
   - Power rankings
   - Playoff projections
   - Trade value charts
   - Waiver wire priorities

5. **Settings Page**
   - Dedicated Fantasy Football settings tab
   - Default preferences
   - Branding defaults
   - Report templates

## Troubleshooting

### CPT Not Showing
**Solution:** Flush permalinks (Settings → Permalinks → Save Changes)

### Logo Generation Fails
**Solutions:**
- Verify OpenAI/Gemini API key is configured
- Check user has `upload_files` capability
- Ensure provider is available (not rate limited)
- Try alternative provider

### Reports Missing Data
**Solutions:**
- Verify Yahoo authentication is valid
- Check league_key is correct
- Ensure standings data is available
- Try refreshing token with `yahoo_ff_get_leagues`

### Cannot Save to Team Post
**Solutions:**
- Verify team_post_id exists
- Check post type is `ff_team`
- Ensure user has `edit_post` capability
- Verify post is not in trash

## Resources

- **Main Documentation**: `docs/tools/yahoo-fantasy-football-toolkit.md`
- **Quick Reference**: `docs/tools/yahoo-fantasy-football-quick-reference.md`
- **Implementation Summary**: `docs/tools/FANTASY_FOOTBALL_IMPLEMENTATION_SUMMARY.md`
- **Yahoo API Docs**: https://developer.yahoo.com/fantasysports/guide/

## Support

For issues or questions:
1. Check troubleshooting section above
2. Review main Fantasy Football documentation
3. Consult Yahoo Developer Network docs
4. GitHub Issues: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues

---

**Version:** 1.0.0  
**Last Updated:** February 2026  
**Module:** Fantasy Football Extended Features
