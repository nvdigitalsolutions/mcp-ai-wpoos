# Test Profession and Team Pages - User Guide

## Overview

Two new admin pages have been added to test AI professions and teams directly from the WordPress admin dashboard, similar to the existing Test Assistant page.

## How to Access

### Test Profession Page
Navigate to: **Professions → Test Profession**
URL: `/wp-admin/edit.php?post_type=mcp_ai_profession&page=wp-mcp-ai-test-profession`

### Test Team Page
Navigate to: **Teams → Test Team**
URL: `/wp-admin/edit.php?post_type=mcp_ai_team&page=wp-mcp-ai-test-team`

## Using the Test Profession Page

1. **View All Professions**
   - See a table listing all published professions
   - Columns show: Name, Category, Expertise Areas, Default Tools

2. **Test a Profession**
   - Click the "Test" button next to any profession
   - A modal opens with a chat interface
   - The system creates a temporary assistant based on the profession
   - Alternatively, if an associated assistant is configured, it uses that assistant's configuration
   - Chat with the profession to validate its behavior

3. **What Gets Tested**
   - Role description and instructions
   - Expertise areas
   - Default tools availability
   - Knowledge base content
   - Memory files integration
   - Provider and model configuration (if set)
   - Associated assistant configuration (if set)

4. **Configuring Test Assistant Association** (New Feature)
   - Edit any profession
   - In the "Default AI Settings" metabox (sidebar), find "Test Assistant" dropdown
   - Select an existing assistant to use for testing this profession
   - When selected, testing will use that assistant's full configuration
   - When empty, falls back to creating a temporary assistant from profession settings
   - Useful for:
     - Testing professions with production assistant configurations
     - Validating assistant-profession compatibility
     - Ensuring consistent testing environments
     - Building the correct test "package" with all assistant features

## Using the Test Team Page

1. **View All Teams**
   - See a table listing all published teams
   - Columns show: Name, Members Count, Provider, Model
   - Team members are listed below each team name

2. **Test a Team**
   - Click the "Test" button next to any team
   - A modal opens showing all team members
   - Select a team member to chat with
   - The system creates a temporary assistant for that member

3. **Member Selection**
   - Grid view of all team members
   - Shows member name, category, and icon
   - Click any member to start chatting
   - Switch between members without closing the modal

4. **What Gets Tested**
   - Each profession's individual behavior within the team context
   - Team-wide settings (provider, model, temperature)
   - Member-specific tools and expertise
   - Knowledge base for each profession

## Technical Implementation

### Architecture (Separation of Concerns)

**Admin Classes**
- `WP_MCP_AI_Admin_Test_Profession` - Profession test page UI
- `WP_MCP_AI_Admin_Test_Team` - Team test page UI

**REST API**
- `WP_MCP_AI_REST_Teams_Controller` - Team data endpoint
- Endpoint: `GET /wp-json/mcp-ai/v1/teams/{id}/members`

**JavaScript**
- `admin-test-profession.js` - Profession modal handler
- `admin-test-team.js` - Team modal and member selection
- Both reuse `chat.js` for chat functionality

**Styling**
- `admin-test-profession.css` - Profession-specific styles
- `admin-test-team.css` - Team and member selector styles
- Both reuse `admin-test-assistant.css` for base modal styles

### Security

All pages implement:
- Capability checks (`manage_options` required)
- WordPress nonce verification
- Input sanitization
- Output escaping
- REST API authentication

### Permissions

**Who can access:**
- Administrators (users with `manage_options` capability)

**What they can test:**
- Published professions only
- Published teams only
- Team members must be published professions

## Differences from Test Assistant Page

### Test Profession
- **Single profession focus**: Tests one profession at a time
- **Temporary assistant**: Creates on-the-fly, not saved
- **Configuration inheritance**: Uses profession's default settings

### Test Team
- **Member selection**: Choose which team member to test
- **Team context**: Tests profession within team configuration
- **Team settings**: Inherits team's provider/model/temperature defaults
- **Multiple chats**: Can test multiple members without closing modal

### Test Assistant (existing)
- **Permanent assistants**: Tests saved assistants
- **Full configuration**: Assistant has its own complete setup
- **Independent**: Not tied to professions or teams

## Use Cases

### Testing a New Profession
1. Create a profession with role, expertise, tools
2. Go to Test Profession page
3. Click "Test" on your new profession
4. Validate the AI behaves as expected
5. Adjust profession settings if needed
6. Test again to confirm improvements

### Testing a Team Configuration
1. Create a team with multiple professionals
2. Set team-wide provider/model settings
3. Go to Test Team page
4. Click "Test" on your team
5. Chat with different team members
6. Verify team settings apply correctly
7. Confirm each member's unique expertise works

### Quality Assurance
- Test professions before deploying to production
- Validate team configurations before client delivery
- Ensure knowledge bases load correctly
- Verify tool access is properly configured
- Check that warnings/disclaimers appear

## Troubleshooting

### "No professions found"
- Create at least one published profession
- Check profession post status is "Publish"

### "This team has no members configured"
- Edit the team and add profession members
- Ensure selected professions are published

### "Chat interface failed to load"
- Check browser console for JavaScript errors
- Ensure chat.js is loaded properly
- Refresh the page and try again

### Member doesn't appear in team selector
- Verify the profession is published
- Check it's added to the team members meta
- Ensure profession post type is correct

## Developer Notes

### Adding Custom Profession Fields
When adding custom meta to professions, they will automatically be available during testing as the system uses the live profession data.

### Extending Team Testing
The REST endpoint `/teams/{id}/members` can be extended to include additional metadata if needed for testing purposes.

### Chat Configuration
Both test pages use the same chat configuration as the main Test Assistant page, ensuring consistent behavior across all test interfaces.

### Temporary Assistants
Test sessions create temporary assistant configurations that are not saved to the database, allowing safe testing without cluttering the assistants list.

## Related Documentation

- `docs/tool-reference.md` - Available tools for professions
- `docs/rest-api.md` - REST API endpoints
- `includes/admin/class-wp-mcp-ai-admin-test-assistant.php` - Reference implementation
