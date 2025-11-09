# Gutenberg Blocks Reference

**Last Updated:** November 9, 2025  
**Total Blocks:** 21  
**Implementation Files:** 3 PHP classes, 3 JavaScript files

This document provides a comprehensive reference for all Gutenberg blocks available in the WP Open Operator System (WP oOS) plugin.

## Overview

WP oOS provides **21 Gutenberg blocks** that offer complete feature parity with Elementor widgets, allowing users to build AI-powered interfaces using either the Gutenberg block editor or Elementor page builder.

All blocks are server-side rendered for consistency, security, and optimal SEO performance, with proper capability checks, sanitization, and escaping throughout.

### Implementation Architecture

- **Server-Rendered**: All blocks use server-side rendering for performance and SEO
- **JavaScript Enhanced**: Interactive features enhanced with JavaScript where needed
- **Automatic Registration**: Blocks register automatically when plugin is activated
- **Namespace**: All blocks use the `wp-mcp-ai` namespace
- **PHP Classes**: 3 main block class files in `includes/blocks/`
- **JavaScript Files**: 3 registration files in `assets/js/`

### Block Categories

1. **Chat Interface Blocks** (4 blocks) - User-facing chat surfaces
2. **Assistant Configuration Blocks** (7 blocks) - Assistant setup and management
3. **Dashboard & Operations Blocks** (5 blocks) - Administrative dashboards
4. **Performance Monitoring Blocks** (5 blocks) - System performance metrics (previously 6, consolidated to 5)

## Block Categories

### Chat Interface Blocks (4 blocks)

These blocks provide performance monitoring and testing capabilities.

#### 1. Performance Test Runner
- **Block Name:** `wp-mcp-ai/performance-test-runner`
- **Description:** Run performance tests for stress, security, speed, and optimization
- **Attributes:**
  - `title` (string): Block title (default: "Performance Test Runner")
  - `enabledTests` (array): Array of test types to enable
- **Required Capability:** `manage_options`

#### 2. Performance Metrics
- **Block Name:** `wp-mcp-ai/performance-metrics`
- **Description:** Display performance metrics for specific components
- **Attributes:**
  - `title` (string): Block title (default: "Performance Metrics")
  - `component` (string): Component to monitor
  - `timePeriod` (string): Time period for metrics (default: "-24 hours")
- **Required Capability:** `manage_options`

#### 3. System Health Status
- **Block Name:** `wp-mcp-ai/system-health-status`
- **Description:** Display system health status
- **Attributes:**
  - `title` (string): Block title (default: "System Health Status")
  - `showBreakdown` (boolean): Show detailed breakdown (default: true)
- **Required Capability:** `manage_options`

#### 4. Test Results Table
- **Block Name:** `wp-mcp-ai/test-results-table`
- **Description:** Display performance test results in a table
- **Attributes:**
  - `title` (string): Block title (default: "Test Results")
  - `testType` (string): Filter by test type
  - `limit` (number): Maximum results to display (default: 10)
- **Required Capability:** `manage_options`

#### 5. Performance Recommendations
- **Block Name:** `wp-mcp-ai/performance-recommendations`
- **Description:** Display AI-generated performance recommendations
- **Attributes:**
  - `title` (string): Block title (default: "Performance Recommendations")
  - `severity` (string): Filter by severity (default: "all")
  - `limit` (number): Maximum recommendations (default: 5)
- **Required Capability:** `manage_options`

#### 6. Performance Trends
- **Block Name:** `wp-mcp-ai/performance-trends`
- **Description:** Display performance trends chart
- **Attributes:**
  - `title` (string): Block title (default: "Performance Trends")
  - `component` (string): Component to monitor (default: "rest_api")
  - `timePeriod` (string): Time period (default: "-7 days")
- **Required Capability:** `manage_options`

### Chat Blocks (4 blocks)

These blocks provide chat interface functionality.

#### 7. Chat (Main Widget)
- **Block Name:** `wp-mcp-ai/chat`
- **Description:** Main chat interface for interacting with AI assistants
- **Attributes:**
  - `assistant` (string): Assistant ID to use
  - `allowGuests` (boolean): Allow guest access (default: false)
  - `saveTranscript` (boolean): Save transcripts to JetEngine (default: true)
  - `enableStreaming` (boolean): Enable SSE streaming (default: false)
- **Required Capability:** Determined by assistant settings or `edit_posts`

#### 8. Chat Intro
- **Block Name:** `wp-mcp-ai/chat-intro`
- **Description:** Display introductory content above the chat interface
- **Attributes:**
  - `title` (string): Block title (default: "Welcome to WP oOS Chat")
  - `description` (string): Introductory text
  - `buttonText` (string): Call-to-action button text (default: "Open Chat")
  - `buttonUrl` (string): Button URL
- **Required Capability:** None (public)

#### 9. Chat FAQ
- **Block Name:** `wp-mcp-ai/chat-faq`
- **Description:** Display frequently asked questions about the chat
- **Attributes:**
  - `title` (string): Block title (default: "How the chat works")
- **Required Capability:** None (public)
- **Note:** FAQ items are hardcoded in the block for simplicity

#### 10. Chat Usage Timer
- **Block Name:** `wp-mcp-ai/chat-usage-timer`
- **Description:** Display chat usage statistics and timer
- **Attributes:**
  - `title` (string): Block title (default: "Chat Usage Timer")
  - `assistantId` (string): Assistant ID to track
- **Required Capability:** `manage_options`

### Assistant Blocks (4 blocks)

These blocks display information about AI assistants.

#### 11. Assistant Defaults
- **Block Name:** `wp-mcp-ai/assistant-defaults`
- **Description:** Display assistant's default model settings
- **Attributes:**
  - `title` (string): Block title (default: "Assistant model defaults")
  - `assistantId` (string): Assistant ID
  - `showSystemPrompt` (boolean): Show system prompt (default: true)
- **Required Capability:** None when assistant is selected
- **Displays:** Provider, model, temperature, system prompt

#### 12. Assistant Base Knowledge
- **Block Name:** `wp-mcp-ai/assistant-base-knowledge`
- **Description:** Display files and vector stores in the assistant's knowledge base
- **Attributes:**
  - `title` (string): Block title (default: "Assistant knowledge base")
  - `assistantId` (string): Assistant ID
  - `showSizes` (boolean): Show file sizes (default: true)
- **Required Capability:** None when assistant is selected
- **Displays:** Media files, file sizes, vector store ID

#### 13. Assistant Prompt Shortcuts
- **Block Name:** `wp-mcp-ai/assistant-prompt-shortcuts`
- **Description:** Display saved prompt shortcuts for the assistant
- **Attributes:**
  - `title` (string): Block title (default: "Assistant prompt shortcuts")
  - `assistantId` (string): Assistant ID
  - `showDescriptions` (boolean): Show shortcut descriptions (default: true)
  - `showPrompt` (boolean): Show prompt payload (default: false)
- **Required Capability:** None when assistant is selected
- **Displays:** Shortcut labels, descriptions, payloads

#### 14. Assistant Tools
- **Block Name:** `wp-mcp-ai/assistant-tools`
- **Description:** Display tools assigned to the assistant
- **Attributes:**
  - `title` (string): Block title (default: "Available assistant tools")
  - `assistantId` (string): Assistant ID
  - `showDescriptions` (boolean): Show tool descriptions (default: true)
- **Required Capability:** None when assistant is selected
- **Displays:** Tool names, descriptions, missing registrations

### Dashboard Blocks (7 blocks)

These blocks provide dashboard and management functionality.

#### 15. Dashboard Tool Matrix
- **Block Name:** `wp-mcp-ai/dashboard-tool-matrix`
- **Description:** Display tool capability matrix
- **Attributes:**
  - `title` (string): Block title (default: "Tool Matrix")
- **Required Capability:** `manage_options`

#### 16. Dashboard User Capability
- **Block Name:** `wp-mcp-ai/dashboard-user-capability`
- **Description:** Display user capability information
- **Attributes:**
  - `title` (string): Block title (default: "User Capabilities")
- **Required Capability:** `manage_options`

#### 17. Dashboard User Files
- **Block Name:** `wp-mcp-ai/dashboard-user-files`
- **Description:** Display user's uploaded files
- **Attributes:**
  - `title` (string): Block title (default: "User Files")
  - `limit` (number): Maximum files to display (default: 10)
- **Required Capability:** Must be logged in

#### 18. Dashboard User Chats
- **Block Name:** `wp-mcp-ai/dashboard-user-chats`
- **Description:** Display recent chat transcripts
- **Attributes:**
  - `title` (string): Block title (default: "Recent Chats")
  - `limit` (number): Maximum chats to display (default: 5)
- **Required Capability:** Must be logged in

#### 19. Dashboard Theme Preview
- **Block Name:** `wp-mcp-ai/dashboard-theme-preview`
- **Description:** Preview theme color settings
- **Attributes:**
  - `title` (string): Block title (default: "Theme Preview")
- **Required Capability:** `manage_options`

#### 20. Dashboard Provider Links
- **Block Name:** `wp-mcp-ai/dashboard-provider-links`
- **Description:** Display links to AI provider platforms
- **Attributes:**
  - `title` (string): Block title (default: "AI Provider Links")
- **Required Capability:** None (public)
- **Links:** OpenAI Platform, Google Cloud Console, Ollama

#### 21. Dashboard Activity Feed
- **Block Name:** `wp-mcp-ai/dashboard-activity-feed`
- **Description:** Display recent activity log
- **Attributes:**
  - `title` (string): Block title (default: "Recent Activity")
  - `limit` (number): Maximum activities to display (default: 10)
- **Required Capability:** `manage_options`

## Block Registration

All blocks are registered in the following files:

- **Performance Blocks:** `/includes/blocks/class-wp-mcp-ai-performance-blocks.php`
- **Chat Blocks:** `/includes/blocks/class-wp-mcp-ai-chat-blocks.php`
- **Assistant Blocks:** `/includes/blocks/class-wp-mcp-ai-assistant-blocks.php`
- **Dashboard Blocks:** `/includes/blocks/class-wp-mcp-ai-dashboard-blocks.php`

All blocks are loaded via `/includes/blocks-init.php` which is included in the main plugin file.

## JavaScript Integration

Each category has a corresponding JavaScript file for block editor integration:

- `/assets/js/performance-blocks.js`
- `/assets/js/chat-blocks.js`
- `/assets/js/assistant-blocks.js`
- `/assets/js/dashboard-blocks.js`

These files register the blocks in the Gutenberg editor with proper icons, categories, and placeholder content.

## Usage Examples

### Adding a Chat Block

```html
<!-- wp:wp-mcp-ai/chat {"assistant":"123","allowGuests":true} /-->
```

### Adding Assistant Defaults Block

```html
<!-- wp:wp-mcp-ai/assistant-defaults {"assistantId":"123","showSystemPrompt":true} /-->
```

### Adding Performance Metrics Block

```html
<!-- wp:wp-mcp-ai/performance-metrics {"component":"rest_api","timePeriod":"-24 hours"} /-->
```

## Comparison with Elementor Widgets

All 21 Elementor widgets now have corresponding Gutenberg blocks:

| Elementor Widget | Gutenberg Block |
|-----------------|----------------|
| WP oOS Chat | wp-mcp-ai/chat |
| WP oOS Chat Intro | wp-mcp-ai/chat-intro |
| WP oOS Chat FAQ | wp-mcp-ai/chat-faq |
| Chat Usage Timer | wp-mcp-ai/chat-usage-timer |
| Assistant Defaults | wp-mcp-ai/assistant-defaults |
| Assistant Base Knowledge | wp-mcp-ai/assistant-base-knowledge |
| Assistant Prompt Shortcuts | wp-mcp-ai/assistant-prompt-shortcuts |
| Assistant Tools | wp-mcp-ai/assistant-tools |
| Dashboard Tool Matrix | wp-mcp-ai/dashboard-tool-matrix |
| Dashboard User Capability | wp-mcp-ai/dashboard-user-capability |
| Dashboard User Files | wp-mcp-ai/dashboard-user-files |
| Dashboard User Chats | wp-mcp-ai/dashboard-user-chats |
| Dashboard Theme Preview | wp-mcp-ai/dashboard-theme-preview |
| Dashboard Provider Links | wp-mcp-ai/dashboard-provider-links |
| Dashboard Activity Feed | wp-mcp-ai/dashboard-activity-feed |
| Performance Test Runner | wp-mcp-ai/performance-test-runner |
| Performance Metrics | wp-mcp-ai/performance-metrics |
| System Health Status | wp-mcp-ai/system-health-status |
| Test Results Table | wp-mcp-ai/test-results-table |
| Performance Recommendations | wp-mcp-ai/performance-recommendations |
| Performance Trends | wp-mcp-ai/performance-trends |

## Security Considerations

- All blocks implement proper capability checks
- User input is sanitized using WordPress sanitization functions
- Output is escaped using appropriate escaping functions (esc_html, esc_url, wp_kses_post)
- Server-side rendering prevents client-side injection attacks
- Guest access is controlled via the allowGuests attribute on the chat block
- Dashboard blocks require appropriate capabilities (manage_options or logged-in user)

## Testing

Block registration is tested in `/tests/test-blocks-registration.php` which verifies:
- All blocks are registered correctly
- Blocks have proper render callbacks
- Blocks have expected attributes
- Total block count matches expectations

## Future Enhancements

Potential future improvements:
1. Add InspectorControls for block settings in the editor
2. Implement block previews in the editor
3. Add block variations for common configurations
4. Create block patterns combining multiple blocks
5. Add block transforms between related blocks
6. Implement dynamic attribute fetching (e.g., assistant list from API)
