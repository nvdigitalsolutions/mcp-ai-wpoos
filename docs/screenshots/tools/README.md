# Tools Screenshots

This directory contains screenshots of the Tools Manager and tool-specific functionality.

## Screenshots Needed

### Tools Management
1. **tools-manager.png** - Main Tools Manager page
   - Location: Settings → NV oOS → Tools (tab or submenu)
   - Should show:
     - Complete tool list (127+ base tools)
     - Tool categories (Content & Knowledge, Media Generation, Research, etc.)
     - Status labels (STA, BET, DEV, EXP, BUG, DEP)
     - Enable/disable toggles for each tool
     - Search and filter functionality
   - Resolution: 1920x1080 minimum
   - Priority: HIGH
   - **Note**: May need to capture as full-page screenshot due to long list

2. **tool-status-labels.png** - Tool status labels close-up
   - Location: Tools Manager page (zoomed in on status labels)
   - Should show:
     - STA (stable) label
     - BET (beta) label
     - DEV (development) label
     - EXP (experimental) label
     - BUG (buggy - auto-disabled) label
     - DEP (deprecated) label
   - Resolution: 800x600 minimum (can be smaller, focused screenshot)
   - Priority: MEDIUM
   - **Note**: Capture multiple tools showing different status labels

3. **tool-dependencies.png** - Tool dependency warnings
   - Location: Tools Manager page (showing tools that require third-party plugins)
   - Should show:
     - Tools marked as requiring WooCommerce
     - Tools marked as requiring JetEngine
     - Tools marked as requiring Elementor
     - Informational notices explaining unavailable tools
     - Visual indicators for missing dependencies
   - Resolution: 1920x1080 minimum
   - Priority: HIGH
   - **Note**: Best captured when third-party plugins are NOT installed

4. **tool-categories.png** - Tool organization by category
   - Location: Tools Manager page (showing category groupings)
   - Should show:
     - Content & Knowledge workflows
     - Media generation & transcription
     - Research & situational awareness
     - Commerce & finance operations
     - Marketing & analytics insights
     - Publishing & outreach
     - Integrations & scheduling
     - Operations & diagnostics
   - Resolution: 1920x1080 minimum
   - Priority: MEDIUM

### Tool Configuration
5. **tool-orchestration.png** - Tool Orchestration settings (if available)
   - Location: Tools Manager → Orchestration settings
   - Should show: Tool execution order, dependencies, chaining
   - Resolution: 1280x720 minimum
   - Priority: LOW

6. **tool-filter-bar.png** - Tool filter bar in action
   - Location: Tools Manager (with filters active)
   - Should show: Search box, category filter, status filter, dependency filter
   - Resolution: 1920x1080 minimum
   - Priority: LOW

### Pro Tools
7. **pro-tools-list.png** - Pro addon tools
   - Location: Tools Manager (with Pro addon active)
   - Should show:
     - Pro-only tools marked distinctly
     - Full tool count (193 total with Pro)
     - Pro tool categories
   - Resolution: 1920x1080 minimum
   - Priority: MEDIUM
   - **Note**: Requires Pro addon to be installed

8. **exec-based-tools.png** - Exec-based tools section
   - Location: Tools Manager (Pro tools section)
   - Should show:
     - FFmpeg tools
     - Python rembg tools
     - WP-CLI tools
     - Meta AI Jukebox tools
   - Resolution: 1280x720 minimum
   - Priority: LOW
   - **Note**: Requires Pro addon

## Screenshot Guidelines

### For Tools Manager Full-Page
- Use a full-page screenshot tool (browser extension)
- Capture from top of page to bottom of tool list
- Ensure all tool categories are visible
- Show both enabled and disabled tools

### For Tool Status Labels
- Zoom browser to 150-200% for clarity
- Capture 5-10 tools showing different status labels
- Ensure labels are clearly readable
- Include tool names for context

### For Dependency Warnings
- Best captured with WooCommerce, JetEngine, and Elementor NOT installed
- Show the informational admin notices
- Highlight which tools are unavailable and why
- Capture the visual styling of dependency warnings

### File Optimization
- Tools Manager screenshots tend to be large due to long lists
- Use PNG compression aggressively
- Consider splitting into multiple screenshots by category if file size exceeds 500KB

### Naming Convention
- Use kebab-case as shown above
- Include descriptive names that indicate what's shown
- For category-specific screenshots, use format: `tools-category-[category-name].png`
