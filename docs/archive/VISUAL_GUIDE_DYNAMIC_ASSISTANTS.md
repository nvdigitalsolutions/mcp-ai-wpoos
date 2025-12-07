# Visual Guide: Dynamic Assistant Creation System

## Before vs After

### Before (Hardcoded)
```
┌─────────────────────────────────┐
│  Create Assistant Button        │
│  ┌───────────────────────────┐  │
│  │ Hardcoded Recipes         │  │
│  │ - Tax Advisor (Jamaica)   │  │
│  │ - Customs Broker (SL)     │  │
│  │ - etc...                  │  │
│  └───────────────────────────┘  │
└─────────────────────────────────┘
        ↓ Limited, requires code changes
```

### After (Dynamic)
```
┌──────────────────────────────────────────┐
│  Professionals (CPT)                     │
│  ┌────────┬────────┬────────┬────────┐  │
│  │Tax     │Customs │SEO     │Content │  │
│  │Advisor │Broker  │Expert  │Writer  │  │
│  └────────┴────────┴────────┴────────┘  │
└──────────────────────────────────────────┘
        ↓ User-editable templates
┌──────────────────────────────────────────┐
│  Teams (CPT)                             │
│  ┌──────────────────────────────────┐   │
│  │ Content Team                     │   │
│  │ ├─ SEO Expert                    │   │
│  │ ├─ Content Writer                │   │
│  │ └─ Social Media Manager          │   │
│  └──────────────────────────────────┘   │
└──────────────────────────────────────────┘
        ↓ Group professionals
┌──────────────────────────────────────────┐
│  Add New / Add Team Pages                │
│  → Template-based Creation               │
│  → One-click Team Deployment             │
└──────────────────────────────────────────┘
```

## User Flow Diagram

```
┌─────────────────────────────────────────────────────────┐
│                    WP Admin Menu                         │
│                                                          │
│  ┌──────────────────────────────────────────────────┐  │
│  │           AI Assistants                          │  │
│  │                                                   │  │
│  │  1. Assistants ──────────► View/Edit Existing    │  │
│  │                                                   │  │
│  │  2. Add New ─────────────► 🆕 Template Selection │  │
│  │     ├─ Grid of Professionals                     │  │
│  │     ├─ Click "Create"                            │  │
│  │     └─ Modal with customization                  │  │
│  │                                                   │  │
│  │  3. Professions ─────────► Manage Templates      │  │
│  │     ├─ Create new professional                   │  │
│  │     ├─ Set role & expertise                      │  │
│  │     ├─ Configure tools                           │  │
│  │     └─ Set AI defaults                           │  │
│  │                                                   │  │
│  │  4. Teams ───────────────► Manage Teams          │  │
│  │     ├─ Create new team                           │  │
│  │     ├─ Select members                            │  │
│  │     └─ Set team defaults                         │  │
│  │                                                   │  │
│  │  5. Add Team ────────────► 🆕 Deploy Teams       │  │
│  │     ├─ View all teams                            │  │
│  │     ├─ Click "Deploy Team"                       │  │
│  │     └─ Creates all members                       │  │
│  └──────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────┘
```

## Data Structure

```
┌─────────────────────────────────────────────────────────┐
│              Professional Template (CPT)                 │
├─────────────────────────────────────────────────────────┤
│  Title: "SEO Analyst"                                   │
│  Category: Technical                                    │
│  Role Description: "Expert in search optimization..."   │
│  Default Tools: [web_search, get_rankmath_seo, ...]    │
│  Expertise: [SEO, Content Strategy, Analytics]          │
│  Knowledge Base: "SEO best practices include..."        │
│  Default Provider: openai                               │
│  Default Model: gpt-4                                   │
│  Default Temperature: 0.7                               │
└─────────────────────────────────────────────────────────┘
        ↓ Referenced by
┌─────────────────────────────────────────────────────────┐
│                     Team (CPT)                          │
├─────────────────────────────────────────────────────────┤
│  Title: "Content Creation Team"                        │
│  Members: [SEO Analyst ID, Content Writer ID, ...]     │
│  Default Provider: gemini (overrides individual)        │
│  Default Model: gemini-pro                              │
│  Default Temperature: 0.8                               │
└─────────────────────────────────────────────────────────┘
        ↓ Deploys to
┌─────────────────────────────────────────────────────────┐
│                  Assistants (CPT)                       │
├─────────────────────────────────────────────────────────┤
│  Title: "Content Team - SEO Analyst"                   │
│  System Prompt: [Role Description + Knowledge Base]     │
│  Tools: [Inherited from Professional]                   │
│  Provider: gemini (from Team)                           │
│  Model: gemini-pro (from Team)                          │
│  Temperature: 0.8 (from Team)                           │
│  Meta:                                                  │
│    _source_profession: Professional ID                  │
│    _source_team: Team ID                                │
└─────────────────────────────────────────────────────────┘
```

## Settings Cascade

```
┌────────────────────────────────────────────────────────┐
│              Settings Priority Flow                     │
└────────────────────────────────────────────────────────┘

1. Create Single Assistant from Template
   ┌──────────────────────────────────────┐
   │ User Overrides (Modal Input)         │ ← Highest Priority
   │  └─ If empty: Professional Defaults  │
   │      └─ Provider, Model, Temperature │
   └──────────────────────────────────────┘

2. Deploy Team
   ┌──────────────────────────────────────┐
   │ Team Defaults                        │ ← Highest Priority
   │  └─ If empty: Professional Defaults  │
   │      └─ Provider, Model, Temperature │
   └──────────────────────────────────────┘

Example Flow:
  Professional: provider=openai, model=gpt-4, temp=0.7
  Team: provider=gemini, model=gemini-pro, temp=0.8
  Result: Uses Team settings (gemini, gemini-pro, 0.8)

  Professional: provider=openai, model=gpt-4, temp=0.7
  Team: provider="" (empty)
  Result: Uses Professional settings (openai, gpt-4, 0.7)
```

## UI Screenshots (Conceptual)

### Add Assistant Page
```
┌─────────────────────────────────────────────────────────────┐
│ Add New Assistant                                           │
├─────────────────────────────────────────────────────────────┤
│ Select a professional template to create a new assistant   │
│                                                             │
│ ┌──────────┐  ┌──────────┐  ┌──────────┐  ┌──────────┐   │
│ │[Photo]   │  │[Photo]   │  │[Photo]   │  │[Photo]   │   │
│ │          │  │          │  │          │  │          │   │
│ │SEO       │  │Content   │  │Social    │  │Tax       │   │
│ │Analyst   │  │Writer    │  │Media Mgr │  │Advisor   │   │
│ │          │  │          │  │          │  │          │   │
│ │Technical │  │Creative  │  │Creative  │  │Financial │   │
│ │          │  │          │  │          │  │          │   │
│ │8 tools   │  │6 tools   │  │5 tools   │  │12 tools  │   │
│ │5 areas   │  │4 areas   │  │3 areas   │  │8 areas   │   │
│ │          │  │          │  │          │  │          │   │
│ │[Create]  │  │[Create]  │  │[Create]  │  │[Create]  │   │
│ │[View]    │  │[View]    │  │[View]    │  │[View]    │   │
│ └──────────┘  └──────────┘  └──────────┘  └──────────┘   │
└─────────────────────────────────────────────────────────────┘
```

### Add Team Page
```
┌─────────────────────────────────────────────────────────────┐
│ Add Team                                                    │
├─────────────────────────────────────────────────────────────┤
│ Deploy a team of AI assistants with one click             │
│                                                             │
│ ┌─────────────────────────────────────────────────────┐   │
│ │ Content Creation Team               [3 members]     │   │
│ │                                                      │   │
│ │ Team Members:                                        │   │
│ │ • SEO Analyst                                        │   │
│ │ • Content Writer                                     │   │
│ │ • Social Media Manager                               │   │
│ │                                                      │   │
│ │ Provider: Gemini   Model: gemini-pro   Temp: 0.8   │   │
│ │                                                      │   │
│ │ [Deploy Team (3 Assistants)]  [Edit Team]          │   │
│ └─────────────────────────────────────────────────────┘   │
│                                                             │
│ ┌─────────────────────────────────────────────────────┐   │
│ │ Marketing Team                      [4 members]     │   │
│ │ ...                                                  │   │
│ └─────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────┘
```

## File Organization

```
wp-mcp-ai/
├── includes/
│   ├── teams/
│   │   ├── class-wp-mcp-ai-team-cpt.php        ← Team CPT
│   │   └── teams-init.php                       ← Init
│   ├── admin/
│   │   ├── class-wp-mcp-ai-add-assistant-page.php  ← Add page
│   │   └── class-wp-mcp-ai-add-team-page.php       ← Add page
│   └── professions/
│       ├── class-wp-mcp-ai-profession-cpt.php   ← Enhanced
│       └── metaboxes/
│           └── class-wp-mcp-ai-profession-metabox-defaults.php
├── assets/
│   ├── css/
│   │   ├── admin-add-assistant.css              ← Styles
│   │   └── admin-add-team.css                   ← Styles
│   └── js/
│       ├── admin-add-assistant.js               ← AJAX
│       └── admin-add-team.js                    ← AJAX
└── mcp-ai-wpoos.php                                ← Main file

Total: 12 files, 2,121 lines of new code
```

## Database Schema

```sql
-- Posts Table (WordPress Core)
-- New post types: mcp_ai_team

-- Postmeta Table (WordPress Core)
-- New profession meta:
_wp_mcp_ai_profession_default_provider   (string)
_wp_mcp_ai_profession_default_model      (string)
_wp_mcp_ai_profession_default_temperature (float)

-- New team meta:
_wp_mcp_ai_team_members                  (array)
_wp_mcp_ai_team_default_provider         (string)
_wp_mcp_ai_team_default_model            (string)
_wp_mcp_ai_team_default_temperature      (float)

-- New assistant meta (for tracking):
_wp_mcp_ai_source_profession             (int - profession ID)
_wp_mcp_ai_source_team                   (int - team ID, optional)
```

## Key Benefits

```
┌────────────────────────────────────────────────────────┐
│                   For Users                            │
├────────────────────────────────────────────────────────┤
│ ✓ No code editing required                            │
│ ✓ Visual template selection                           │
│ ✓ Reusable professional templates                     │
│ ✓ One-click team deployment                           │
│ ✓ Consistent configurations                           │
└────────────────────────────────────────────────────────┘

┌────────────────────────────────────────────────────────┐
│                   For Developers                       │
├────────────────────────────────────────────────────────┤
│ ✓ WordPress standard CPT architecture                 │
│ ✓ Clean separation of concerns                        │
│ ✓ Extensible metabox system                          │
│ ✓ Secure AJAX implementation                          │
│ ✓ Backward compatible                                 │
└────────────────────────────────────────────────────────┘

┌────────────────────────────────────────────────────────┐
│                   For Organizations                    │
├────────────────────────────────────────────────────────┤
│ ✓ Scalable template library                          │
│ ✓ Team-based deployment                               │
│ ✓ Centralized configuration management                │
│ ✓ Easy onboarding of new assistants                   │
│ ✓ Template sharing across sites                       │
└────────────────────────────────────────────────────────┘
```
