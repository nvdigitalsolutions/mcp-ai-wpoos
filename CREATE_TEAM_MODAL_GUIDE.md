# Create AI Team Modal - Visual Guide

## Overview
This feature adds a "Create AI Team" modal button to the AI Assistants list page, positioned next to the existing "Create AI Assistant" button.

## UI Flow

### 1. AI Assistants List Page
```
+-------------------------------------------------------------------+
| AI Assistants                                                     |
+-------------------------------------------------------------------+
| [Create AI Assistant] [Create AI Team] [Add New]                 |
+-------------------------------------------------------------------+
| List of existing assistants...                                    |
+-------------------------------------------------------------------+
```

### 2. Create AI Team Modal (Opens on Click)
```
+-------------------------------------------------------------------+
|  Create AI Team                                              [×]  |
+-------------------------------------------------------------------+
| Team Name *                                                       |
| [                                                            ]    |
| E.g., "Jamaica Business Advisory Team", "International Trade Team"|
|                                                                   |
| Team Members (Professions) *                                     |
| [+-------------------------------------------------------+]       |
| | Tax Advisor                                            |        |
| | Accountant                                             |        |
| | Bookkeeper                                             |        |
| | Lawyer                                                 |        |
| | Customs Broker                                         |        |
| | ... (scrollable list)                                  |        |
| +--------------------------------------------------------+        |
| Select at least 2 professions to form your team.                 |
| Hold Ctrl/Cmd to select multiple.                                |
|                                                                   |
| Team Description                                                  |
| [                                                            ]    |
| [                                                            ]    |
| Optional: Describe the purpose and focus of this team.           |
|                                                                   |
| AI Provider                                                       |
| [-- Use Profession Defaults --    ▼]                            |
|   OpenAI                                                          |
|   Google Gemini                                                   |
|   Anthropic Claude                                                |
|   Ollama (Local)                                                  |
|   LM Studio                                                       |
| Override profession defaults with a single provider for all      |
| team members.                                                     |
|                                                                   |
| Model                                                             |
| [                                                            ]    |
| e.g., gpt-4, gemini-pro                                          |
| Override profession defaults with a single model for all         |
| team members.                                                     |
|                                                                   |
| Temperature                                                       |
| [      ]                                                          |
| 0-2. Lower is more deterministic, higher is more creative.       |
| Leave empty to use profession defaults.                          |
|                                                                   |
+-------------------------------------------------------------------+
|                                    [Cancel] [Create Team]        |
+-------------------------------------------------------------------+
```

### 3. Success Flow
After clicking "Create Team":
1. Button shows "Creating team..." with loading spinner
2. AJAX request validates and creates the team
3. Success message displays: "Team created successfully with X members!"
4. User is redirected to the team edit page

### 4. Error Handling
- Missing team name: "Team name is required."
- Less than 2 professions: "Please select at least 2 professions to create a team."
- Invalid profession IDs: "Invalid profession ID: X"
- Permission denied: "Insufficient permissions."

## Technical Details

### Files Created
1. `/includes/admin/class-wp-mcp-ai-admin-create-team-button.php` - Main PHP class
2. `/assets/js/admin-create-team-modal.js` - JavaScript functionality
3. `/assets/css/admin-create-team-modal.css` - Modal styling
4. `/tests/test-create-team-modal.php` - Unit tests

### Database Structure
When a team is created, the following data is stored:

**Post Type**: `mcp_ai_team`
- `post_title`: Team name
- `post_content`: Team description
- `post_status`: publish

**Post Meta**:
- `_wp_mcp_ai_team_members`: Array of profession post IDs
- `_wp_mcp_ai_team_default_provider`: Optional provider override
- `_wp_mcp_ai_team_default_model`: Optional model override
- `_wp_mcp_ai_team_default_temperature`: Optional temperature override

### Integration with Existing Deployment
Created teams appear in:
- **AI Assistants → Add Team** page
- Can be deployed (creates individual assistants from team members)
- Follows existing team deployment workflow

## Example Use Cases

### Example 1: Jamaica Business Advisory Team
```
Team Name: Jamaica Business Advisory Team
Professions: Tax Advisor, Accountant, Lawyer
Description: Comprehensive business support for Jamaica-based companies
Provider: OpenAI
Model: gpt-4
Temperature: 0.7
```

Creates a team with 3 professions that can be deployed to create 3 assistants:
- Jamaica Business Advisory Team - Tax Advisor
- Jamaica Business Advisory Team - Accountant
- Jamaica Business Advisory Team - Lawyer

### Example 2: International Trade Specialists
```
Team Name: International Trade Specialists
Professions: Customs Broker, Import/Export Specialist, Legal Advisor, Accountant
Description: Expert team for international trade operations
Provider: (Use Profession Defaults)
Model: (Use Profession Defaults)
Temperature: (Use Profession Defaults)
```

Creates a team with 4 professions using each profession's default settings.

## Code Quality
- ✅ Follows WordPress Coding Standards
- ✅ Passes ESLint validation
- ✅ No CodeQL security issues
- ✅ Follows separation of concerns (SOC) principles
- ✅ Unit tests included
- ✅ Input validation and sanitization
- ✅ Proper capability checks
- ✅ Nonce verification for security
