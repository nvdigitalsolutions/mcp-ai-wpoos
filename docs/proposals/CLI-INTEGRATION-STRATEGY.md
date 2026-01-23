# CLI Integration Strategy for NV oOS Pro

**Status:** Revised Proposal  
**Date:** 2026-01-22  
**Version:** 2.0.0

## New Requirement: Codex + Claude Code CLI Integration

### Question: Should we add both Codex and Claude Code CLI to Pro?

**Answer: YES - As Unified "AI Development Assistant" Pro Feature** ✅

## Why CLI Integration Makes More Sense Now

After reviewing your requirements, I see you want the **full Ralph feature set**, which fundamentally requires CLI-level integration for autonomous development loops. This changes the recommendation significantly.

### What You Really Want

You're not just looking for task planning - you want **autonomous code development** capabilities:

1. ✅ Multi-file code editing across WordPress codebase
2. ✅ Plugin/theme scaffolding and development
3. ✅ External project management (non-WordPress code)
4. ✅ Server configuration and optimization
5. ✅ Continuous autonomous development cycles

**This requires CLI-level access that web-based tools can't provide.**

## Revised Architecture: AI Development Assistant Suite

### Three-Tier Implementation

```
┌────────────────────────────────────────────────────────────────┐
│                    NV oOS Pro Addon                             │
├────────────────────────────────────────────────────────────────┤
│  Tier 1: Web-Based Tools (Base + Pro)                          │
│  ✅ Task planning, content creation, WordPress operations       │
│  ✅ Works everywhere (shared hosting compatible)               │
└────────────────────────────────────────────────────────────────┘
                              ↓
┌────────────────────────────────────────────────────────────────┐
│  Tier 2: AI Development Assistant (Pro - VPS/Dedicated)        │
│  ┌──────────────────────────────────────────────────────────┐ │
│  │  Claude Code CLI Integration                              │ │
│  │  - Autonomous development loops                           │ │
│  │  - Multi-file editing                                     │ │
│  │  - Plugin/theme development                               │ │
│  │  - Ralph pattern implementation                           │ │
│  └──────────────────────────────────────────────────────────┘ │
│  ┌──────────────────────────────────────────────────────────┐ │
│  │  GitHub Copilot CLI / Codex Integration                   │ │
│  │  - Code completion assistance                             │ │
│  │  - Refactoring suggestions                                │ │
│  │  - Bug detection and fixes                                │ │
│  │  - Unit test generation                                   │ │
│  └──────────────────────────────────────────────────────────┘ │
└────────────────────────────────────────────────────────────────┘
                              ↓
┌────────────────────────────────────────────────────────────────┐
│  Tier 3: Shell Access Bridge (Pro Ultra - Advanced)            │
│  - Direct terminal integration                                  │
│  - tmux session management                                      │
│  - Real-time monitoring dashboards                              │
│  - Multi-project orchestration                                  │
└────────────────────────────────────────────────────────────────┘
```

## Detailed Integration Plan

### 1. Claude Code CLI Integration (Priority 1)

**What It Provides:**
- Autonomous multi-file editing
- Context-aware code generation
- External project support
- Full Ralph pattern capabilities

**Implementation:**

```php
/**
 * Claude Code CLI Manager
 * Handles all Claude CLI interactions
 */
class WP_MCP_AI_Pro_Claude_CLI_Manager {
    
    /**
     * Check if Claude CLI is installed and accessible
     */
    public function is_available() {
        // Check for claude executable
        $result = $this->exec_safe( 'which claude' );
        return ! empty( $result );
    }
    
    /**
     * Get Claude CLI version
     */
    public function get_version() {
        $output = $this->exec_safe( 'claude --version' );
        return $this->parse_version( $output );
    }
    
    /**
     * Start autonomous development session
     */
    public function start_autonomous_session( $args ) {
        $session_id = wp_generate_uuid4();
        
        // Build ralph-style command
        $command = $this->build_ralph_command( array(
            'prompt'         => $args['prompt'],
            'output_format'  => 'json',
            'allowed_tools'  => $args['allowed_tools'] ?? array(),
            'max_calls'      => $args['max_iterations'] ?? 100,
            'timeout'        => $args['timeout'] ?? 1800, // 30 min
            'continue'       => $args['continue'] ?? true,
        ) );
        
        // Execute in background with session tracking
        $this->start_background_process( $command, $session_id );
        
        // Store session metadata
        $this->store_session( $session_id, array(
            'user_id'     => get_current_user_id(),
            'command'     => $command,
            'status'      => 'running',
            'started_at'  => current_time( 'mysql' ),
            'workspace'   => $args['workspace'],
            'plan_id'     => $args['plan_id'] ?? null,
        ) );
        
        return $session_id;
    }
    
    /**
     * Monitor session with tmux integration
     */
    public function monitor_session( $session_id ) {
        $session = $this->get_session( $session_id );
        
        if ( ! $session ) {
            return new WP_Error( 'session_not_found', 'Session not found' );
        }
        
        // Get real-time output from tmux session
        $tmux_output = $this->get_tmux_output( $session_id );
        
        // Parse for status updates
        $status = $this->parse_session_status( $tmux_output );
        
        return array(
            'session_id'   => $session_id,
            'status'       => $status['status'],
            'iteration'    => $status['iteration'],
            'last_action'  => $status['last_action'],
            'completion'   => $status['completion_percentage'],
            'health'       => $status['health'],
            'output'       => $tmux_output,
        );
    }
    
    /**
     * Build Ralph-compatible command
     */
    private function build_ralph_command( $args ) {
        $cmd_parts = array( 'claude' );
        
        // Add prompt
        if ( ! empty( $args['prompt'] ) ) {
            $prompt_file = $this->write_prompt_file( $args['prompt'] );
            $cmd_parts[] = sprintf( '-p "%s"', $prompt_file );
        }
        
        // Output format
        if ( ! empty( $args['output_format'] ) ) {
            $cmd_parts[] = sprintf( '--output-format %s', $args['output_format'] );
        }
        
        // Allowed tools
        if ( ! empty( $args['allowed_tools'] ) ) {
            $tools = implode( ',', $args['allowed_tools'] );
            $cmd_parts[] = sprintf( '--allowed-tools %s', escapeshellarg( $tools ) );
        }
        
        // Continue flag
        if ( $args['continue'] ) {
            $cmd_parts[] = '--continue';
        }
        
        return implode( ' ', $cmd_parts );
    }
    
    /**
     * Safe command execution wrapper
     */
    private function exec_safe( $command, $timeout = 30 ) {
        // Use Symfony Process for safety
        $process = new \Symfony\Component\Process\Process(
            explode( ' ', $command ),
            null,
            null,
            null,
            $timeout
        );
        
        $process->run();
        
        if ( ! $process->isSuccessful() ) {
            $this->log_error( $process->getErrorOutput() );
            return false;
        }
        
        return $process->getOutput();
    }
}
```

### 2. GitHub Copilot CLI / Codex Integration (Priority 2)

**What It Provides:**
- Real-time code suggestions
- Refactoring assistance
- Bug detection
- Test generation

**Implementation:**

```php
/**
 * GitHub Copilot CLI Manager
 */
class WP_MCP_AI_Pro_Copilot_CLI_Manager {
    
    /**
     * Initialize Copilot CLI connection
     */
    public function is_available() {
        // Check for gh copilot extension
        $result = $this->exec_safe( 'gh copilot --version' );
        return ! empty( $result );
    }
    
    /**
     * Get code suggestions
     */
    public function suggest( $args ) {
        $command = sprintf(
            'gh copilot suggest "%s"',
            escapeshellarg( $args['prompt'] )
        );
        
        if ( ! empty( $args['language'] ) ) {
            $command .= sprintf( ' --language %s', $args['language'] );
        }
        
        $output = $this->exec_safe( $command );
        
        return $this->parse_suggestion( $output );
    }
    
    /**
     * Explain code
     */
    public function explain( $code, $language = 'php' ) {
        $code_file = $this->write_temp_file( $code );
        
        $command = sprintf(
            'gh copilot explain --file %s',
            $code_file
        );
        
        $output = $this->exec_safe( $command );
        
        unlink( $code_file );
        
        return $this->parse_explanation( $output );
    }
    
    /**
     * Generate tests
     */
    public function generate_tests( $code, $framework = 'phpunit' ) {
        // Implementation for test generation
    }
}
```

### 3. Unified AI Development Assistant Tool

**Single Tool Interface for Both CLIs:**

```php
/**
 * Unified AI Development Assistant Tool
 * Automatically routes to best CLI based on task
 */
class WP_MCP_AI_Pro_Tool_AI_Dev_Assistant {
    
    public function get_slug() {
        return 'ai_dev_assistant';
    }
    
    public function get_definition() {
        return array(
            'name'        => 'AI Development Assistant',
            'description' => 'Autonomous code development using Claude Code and GitHub Copilot',
            'category'    => 'development',
            'required_capability' => 'manage_options',
            'arguments'   => array(
                'task_type' => array(
                    'type'        => 'string',
                    'description' => 'Type of development task',
                    'enum'        => array(
                        'autonomous_dev',    // Claude Code
                        'code_suggest',      // Copilot
                        'code_explain',      // Copilot
                        'refactor',          // Claude Code
                        'test_generate',     // Copilot
                        'bug_fix',           // Claude Code
                        'scaffold',          // Claude Code
                    ),
                    'required'    => true,
                ),
                'workspace' => array(
                    'type'        => 'string',
                    'description' => 'Working directory path',
                    'required'    => true,
                ),
                'prompt' => array(
                    'type'        => 'string',
                    'description' => 'Development task description',
                    'required'    => true,
                ),
                'files' => array(
                    'type'        => 'array',
                    'description' => 'Specific files to work on',
                    'required'    => false,
                ),
                'autonomous' => array(
                    'type'        => 'boolean',
                    'description' => 'Enable autonomous mode',
                    'default'     => false,
                ),
                'max_iterations' => array(
                    'type'        => 'integer',
                    'description' => 'Max autonomous iterations',
                    'default'     => 20,
                ),
            ),
        );
    }
    
    public function execute( $arguments, $context ) {
        // Route to appropriate CLI based on task_type
        switch ( $arguments['task_type'] ) {
            case 'autonomous_dev':
            case 'refactor':
            case 'bug_fix':
            case 'scaffold':
                return $this->execute_claude_task( $arguments );
                
            case 'code_suggest':
            case 'code_explain':
            case 'test_generate':
                return $this->execute_copilot_task( $arguments );
                
            default:
                return $this->error_response( 'Unknown task type' );
        }
    }
    
    private function execute_claude_task( $args ) {
        $claude_manager = new WP_MCP_AI_Pro_Claude_CLI_Manager();
        
        if ( ! $claude_manager->is_available() ) {
            return $this->error_response( 'Claude CLI not available. Please install: npm install -g @anthropic-ai/claude-cli' );
        }
        
        // Validate workspace is safe
        if ( ! $this->is_safe_workspace( $args['workspace'] ) ) {
            return $this->error_response( 'Workspace directory not allowed' );
        }
        
        if ( $args['autonomous'] ) {
            // Start autonomous session
            $session_id = $claude_manager->start_autonomous_session( array(
                'prompt'         => $args['prompt'],
                'workspace'      => $args['workspace'],
                'max_iterations' => $args['max_iterations'],
                'plan_id'        => $args['plan_id'] ?? null,
            ) );
            
            return array(
                'success'    => true,
                'session_id' => $session_id,
                'status'     => 'started',
                'message'    => 'Autonomous development session started. Monitor progress with get_session_status tool.',
            );
        } else {
            // Single execution
            $result = $claude_manager->execute_single( $args );
            
            return array(
                'success' => true,
                'result'  => $result,
            );
        }
    }
    
    private function execute_copilot_task( $args ) {
        $copilot_manager = new WP_MCP_AI_Pro_Copilot_CLI_Manager();
        
        if ( ! $copilot_manager->is_available() ) {
            return $this->error_response( 'GitHub Copilot CLI not available. Please install: gh extension install github/gh-copilot' );
        }
        
        switch ( $args['task_type'] ) {
            case 'code_suggest':
                return $copilot_manager->suggest( $args );
                
            case 'code_explain':
                return $copilot_manager->explain( $args['code'] );
                
            case 'test_generate':
                return $copilot_manager->generate_tests( $args['code'] );
        }
    }
}
```

## Implementing All Ralph Features

Now let me address your specific Ralph features list:

### Ralph Features Mapping to NV oOS Pro

| Ralph Feature | Implementation | Priority |
|--------------|----------------|----------|
| **Autonomous development loops** | Claude CLI Manager with session tracking | P1 |
| **Dual-condition exit gate** | `check_exit_conditions` tool + CLI parsing | P1 |
| **Rate limiting (100 calls/hour)** | Enhanced Rate Limit Manager | P1 |
| **Circuit breaker** | `analyze_loop_health` tool + CLI monitoring | P1 |
| **Response analyzer with semantic understanding** | `detect_completion_indicators` tool | P1 |
| **JSON output format support** | CLI Manager with --output-format flag | P1 |
| **Session continuity (--continue)** | Session Manager with --continue flag | P1 |
| **Session expiration (24h)** | `manage_autonomous_session` tool | P1 |
| **Modern CLI flags** | CLI Manager wrapper methods | P1 |
| **Multi-line error matching** | Enhanced error detection in health analyzer | P1 |
| **5-hour API limit handling** | Rate Limit Manager with user prompts | P2 |
| **tmux integration** | tmux session wrapper in CLI Manager | P2 |
| **PRD import functionality** | `create_task_plan` from uploaded docs | P2 |

### Implementation Structure

```php
addons/mcp-ai-wpoos-pro/
├── includes/
│   ├── cli/  (NEW - CLI Integration Layer)
│   │   ├── class-wp-mcp-ai-pro-cli-manager.php (base)
│   │   ├── class-wp-mcp-ai-pro-claude-cli-manager.php
│   │   ├── class-wp-mcp-ai-pro-copilot-cli-manager.php
│   │   ├── class-wp-mcp-ai-pro-tmux-manager.php
│   │   └── class-wp-mcp-ai-pro-session-monitor.php
│   │
│   ├── orchestration/  (NEW - Ralph Pattern Implementation)
│   │   ├── tools/  (13 tools now, not 8)
│   │   │   ├── class-wp-mcp-ai-pro-tool-create-task-plan.php
│   │   │   ├── class-wp-mcp-ai-pro-tool-update-task-plan.php
│   │   │   ├── class-wp-mcp-ai-pro-tool-get-task-plan.php
│   │   │   ├── class-wp-mcp-ai-pro-tool-create-execution-prompt.php
│   │   │   ├── class-wp-mcp-ai-pro-tool-detect-completion-indicators.php
│   │   │   ├── class-wp-mcp-ai-pro-tool-check-exit-conditions.php
│   │   │   ├── class-wp-mcp-ai-pro-tool-manage-autonomous-session.php
│   │   │   ├── class-wp-mcp-ai-pro-tool-analyze-loop-health.php
│   │   │   ├── class-wp-mcp-ai-pro-tool-ai-dev-assistant.php (NEW)
│   │   │   ├── class-wp-mcp-ai-pro-tool-get-session-status.php (NEW)
│   │   │   ├── class-wp-mcp-ai-pro-tool-control-session.php (NEW)
│   │   │   ├── class-wp-mcp-ai-pro-tool-import-prd.php (NEW)
│   │   │   └── class-wp-mcp-ai-pro-tool-monitor-tmux.php (NEW)
│   │   │
│   │   ├── class-wp-mcp-ai-pro-ralph-orchestrator.php (NEW)
│   │   ├── class-wp-mcp-ai-pro-exit-gate-analyzer.php (NEW)
│   │   ├── class-wp-mcp-ai-pro-circuit-breaker.php (NEW)
│   │   └── class-wp-mcp-ai-pro-response-analyzer.php (NEW)
│   │
│   └── admin/
│       └── pages/
│           ├── class-wp-mcp-ai-pro-dev-assistant-dashboard.php (NEW)
│           └── class-wp-mcp-ai-pro-session-monitor-page.php (NEW)
```

## Security & Safety Considerations

### Workspace Whitelisting

```php
/**
 * Safe workspace configuration
 */
class WP_MCP_AI_Pro_Workspace_Manager {
    
    /**
     * Default allowed workspaces
     */
    private function get_default_workspaces() {
        return array(
            // WordPress core areas (read-only unless explicitly enabled)
            WP_CONTENT_DIR . '/uploads/ai-workspace/',
            WP_CONTENT_DIR . '/plugins/' . basename( WP_MCP_AI_PLUGIN_DIR ) . '/',
            
            // Pro-managed directories
            WP_CONTENT_DIR . '/ai-projects/',
        );
    }
    
    /**
     * Check if directory is safe for CLI operations
     */
    public function is_safe_workspace( $path ) {
        $real_path = realpath( $path );
        
        if ( ! $real_path ) {
            return false;
        }
        
        // Check against whitelist
        $allowed = $this->get_allowed_workspaces();
        
        foreach ( $allowed as $allowed_dir ) {
            if ( strpos( $real_path, realpath( $allowed_dir ) ) === 0 ) {
                return true;
            }
        }
        
        // Check user-defined safe zones
        $custom_workspaces = get_option( 'wp_mcp_ai_pro_custom_workspaces', array() );
        foreach ( $custom_workspaces as $workspace ) {
            if ( $this->path_matches( $real_path, $workspace['path'] ) ) {
                // Verify user has permission
                if ( current_user_can( $workspace['required_capability'] ) ) {
                    return true;
                }
            }
        }
        
        return false;
    }
}
```

### Rate Limiting for CLI Operations

```php
/**
 * Enhanced rate limiting for CLI operations
 */
class WP_MCP_AI_Pro_CLI_Rate_Limiter {
    
    /**
     * Check if user can start new CLI session
     */
    public function can_start_session( $user_id ) {
        $limits = array(
            'max_concurrent_sessions'  => 3,     // Max 3 simultaneous sessions
            'max_hourly_calls'         => 100,   // Ralph default
            'max_daily_token_budget'   => 500000, // 500K tokens/day
            'session_cooldown_minutes' => 5,     // 5 min between sessions
        );
        
        $current = $this->get_user_usage( $user_id );
        
        // Check concurrent sessions
        if ( $current['active_sessions'] >= $limits['max_concurrent_sessions'] ) {
            return new WP_Error(
                'max_sessions',
                sprintf( 'Maximum %d concurrent sessions allowed', $limits['max_concurrent_sessions'] )
            );
        }
        
        // Check hourly calls
        if ( $current['hourly_calls'] >= $limits['max_hourly_calls'] ) {
            $reset_time = $current['hourly_reset_at'];
            return new WP_Error(
                'rate_limit',
                sprintf( 'Hourly limit reached. Resets in %d minutes', $reset_time )
            );
        }
        
        // Check daily budget
        if ( $current['daily_tokens'] >= $limits['max_daily_token_budget'] ) {
            return new WP_Error(
                'budget_exceeded',
                'Daily token budget exhausted'
            );
        }
        
        // Check cooldown
        if ( $current['last_session_ended_at'] ) {
            $cooldown_remaining = $this->get_cooldown_remaining( $current['last_session_ended_at'] );
            if ( $cooldown_remaining > 0 ) {
                return new WP_Error(
                    'cooldown',
                    sprintf( 'Wait %d seconds before starting new session', $cooldown_remaining )
                );
            }
        }
        
        return true;
    }
}
```

### Emergency Stop Controls

```php
/**
 * Emergency stop and safety controls
 */
class WP_MCP_AI_Pro_Emergency_Controls {
    
    /**
     * Kill switch - stops all CLI sessions immediately
     */
    public function emergency_stop_all() {
        $active_sessions = $this->get_active_sessions();
        
        foreach ( $active_sessions as $session ) {
            $this->force_stop_session( $session['id'], 'emergency_stop' );
        }
        
        // Notify admins
        $this->notify_admins_emergency_stop( count( $active_sessions ) );
        
        // Log event
        $this->log_security_event( 'emergency_stop_all', array(
            'stopped_count' => count( $active_sessions ),
            'triggered_by'  => get_current_user_id(),
            'timestamp'     => current_time( 'mysql' ),
        ) );
    }
    
    /**
     * Force stop specific session
     */
    public function force_stop_session( $session_id, $reason = 'manual' ) {
        $session = $this->get_session( $session_id );
        
        if ( ! $session ) {
            return false;
        }
        
        // Kill tmux session if exists
        if ( $session['tmux_session'] ) {
            exec( sprintf( 'tmux kill-session -t %s', escapeshellarg( $session['tmux_session'] ) ) );
        }
        
        // Kill process if running
        if ( $session['pid'] ) {
            posix_kill( $session['pid'], SIGTERM );
            
            // Wait 5 seconds then SIGKILL if still running
            sleep( 5 );
            if ( posix_kill( $session['pid'], 0 ) ) { // Check if process exists
                posix_kill( $session['pid'], SIGKILL );
            }
        }
        
        // Update database
        $this->update_session_status( $session_id, 'stopped', $reason );
        
        return true;
    }
}
```

## Admin UI: Development Assistant Dashboard

### Main Dashboard Features

```
WordPress Admin → NV oOS Pro → AI Development

┌─────────────────────────────────────────────────────────────┐
│ AI Development Assistant Dashboard                          │
├─────────────────────────────────────────────────────────────┤
│                                                              │
│  📊 Overview                                                 │
│  ┌──────────┬──────────┬──────────┬───────────┐            │
│  │ Active   │ Queued   │ Complete │ Failed    │            │
│  │ Sessions │ Tasks    │ Today    │ Today     │            │
│  │    3     │    2     │    12    │     1     │            │
│  └──────────┴──────────┴──────────┴───────────┘            │
│                                                              │
│  🔴 Active Sessions                                          │
│  ┌────────────────────────────────────────────────────┐    │
│  │ Session #abc123                           [Stop]   │    │
│  │ Task: Refactor authentication system               │    │
│  │ Workspace: /wp-content/plugins/my-plugin/          │    │
│  │ Status: ⚡ Running (Iteration 12/50)               │    │
│  │ Progress: ████████░░░░ 37% (3/8 tasks)             │    │
│  │ Health: ✅ Healthy                                  │    │
│  │ Time: 18 minutes elapsed, 22h remaining            │    │
│  │ Budget: 6,500/10,000 tokens (65%)                  │    │
│  │ [View Live Output] [Pause] [Emergency Stop]        │    │
│  └────────────────────────────────────────────────────┘    │
│                                                              │
│  📋 Task Plans                                              │
│  ┌────────────────────────────────────────────────────┐    │
│  │ • Contact Form Plugin Development (75% complete)    │    │
│  │ • WooCommerce Integration Refactor (50% complete)  │    │
│  │ • Security Audit Fixes (30% complete)              │    │
│  │ [New Task Plan] [Import PRD]                        │    │
│  └────────────────────────────────────────────────────┘    │
│                                                              │
│  ⚙️ CLI Status                                               │
│  ┌────────────────────────────────────────────────────┐    │
│  │ Claude Code CLI: ✅ v2.1.0 installed                │    │
│  │ GitHub Copilot:  ✅ v1.3.2 installed                │    │
│  │ tmux:            ✅ v3.3a installed                  │    │
│  │ Rate Limit:      42/100 calls this hour (58 left)  │    │
│  │ Token Budget:    125K/500K daily (375K left)        │    │
│  └────────────────────────────────────────────────────┘    │
│                                                              │
│  [Start New Session] [View All Sessions] [Settings]        │
└─────────────────────────────────────────────────────────────┘
```

### Live Session Monitor (Separate Page)

```
WordPress Admin → NV oOS Pro → Session Monitor → #abc123

┌─────────────────────────────────────────────────────────────┐
│ Live Session Monitor: #abc123                               │
├─────────────────────────────────────────────────────────────┤
│                                                              │
│  Session Details                                             │
│  Task Plan: Contact Form Plugin (#123)                      │
│  Started: 2026-01-22 15:45:00 (18 minutes ago)             │
│  Iteration: 12 of 50                                        │
│                                                              │
│  ┌─────────────────────────────────────────────────────┐   │
│  │ 🖥️ Live Terminal Output (tmux session)              │   │
│  ├─────────────────────────────────────────────────────┤   │
│  │ Iteration 12: Analyzing form validation logic...    │   │
│  │ > Using tool: search_content                         │   │
│  │ > Found 3 validation functions                       │   │
│  │ > Refactoring to use WordPress sanitize_text_field  │   │
│  │ > Writing changes to includes/form-handler.php      │   │
│  │ > Running tests...                                   │   │
│  │ ✅ Tests passed (12/12)                              │   │
│  │                                                       │   │
│  │ Iteration 12 complete. Moving to next task...       │   │
│  │ Completion indicators: 2                             │   │
│  │ EXIT_SIGNAL: false (more work needed)               │   │
│  │                                                       │   │
│  │ Iteration 13: Adding spam protection...             │   │
│  │ █ (updates in real-time)                            │   │
│  └─────────────────────────────────────────────────────┘   │
│                                                              │
│  📊 Health Metrics                                           │
│  ┌───────────────────────────┐                             │
│  │ Status: ✅ Healthy         │                             │
│  │ Warnings: None             │                             │
│  │ Errors: 0 consecutive      │                             │
│  │ Circuit Breaker: Closed    │                             │
│  └───────────────────────────┘                             │
│                                                              │
│  🎯 Task Progress                                            │
│  - [x] Design form UI                                       │
│  - [x] Create database schema                               │
│  - [x] Build form submission handler                        │
│  - [x] Add email notifications                              │
│  - [✓] Implement spam protection (in progress)             │
│  - [ ] Create admin settings page                           │
│  - [ ] Write documentation                                  │
│  - [ ] Add unit tests                                       │
│                                                              │
│  Controls: [Pause] [Stop] [Emergency Kill] [Export Logs]   │
└─────────────────────────────────────────────────────────────┘

(Auto-refreshes every 5 seconds)
```

## Configuration Settings

### New Pro Settings Section

```
WordPress Admin → Settings → NV oOS Pro → AI Development

┌─────────────────────────────────────────────────────────────┐
│ AI Development Assistant Configuration                      │
├─────────────────────────────────────────────────────────────┤
│                                                              │
│  🛠️ CLI Installation                                         │
│  Claude Code CLI:   [✅ Installed] [Check Status]           │
│  GitHub Copilot:    [✅ Installed] [Check Status]           │
│  tmux:              [✅ Installed] [Check Status]           │
│                                                              │
│  📁 Workspace Management                                     │
│  Default Workspace: [/wp-content/ai-projects/]              │
│  [Add Custom Workspace]                                     │
│                                                              │
│  Custom Workspaces:                                         │
│  • /var/www/external-project/    [Edit] [Remove]           │
│    Required Capability: manage_options                      │
│    Read-Only: ☐                                             │
│                                                              │
│  ⚡ Rate Limiting                                             │
│  Max Concurrent Sessions: [3]                               │
│  Max Hourly CLI Calls:    [100]                             │
│  Max Daily Token Budget:  [500000]                          │
│  Session Cooldown (min):  [5]                               │
│                                                              │
│  🔒 Safety Controls                                          │
│  [x] Enable circuit breaker                                 │
│  [x] Require dual-condition exit gates                      │
│  [x] Auto-stop on error cascade (5+ errors)                │
│  [x] Budget warning at 80%                                  │
│  [ ] Allow WordPress core modifications (⚠️ dangerous)      │
│                                                              │
│  📧 Notifications                                            │
│  [x] Email on session completion                            │
│  [x] Email on session failures                              │
│  [x] Email on budget warnings                               │
│  Notification Email: [admin@example.com]                    │
│                                                              │
│  🚨 Emergency Controls                                       │
│  [Emergency Stop All Sessions] ⚠️                           │
│  [Reset Rate Limits] (admin only)                          │
│                                                              │
│  [Save Settings]                                            │
└─────────────────────────────────────────────────────────────┘
```

## Installation Requirements

### Server Requirements for CLI Integration

**Minimum** (Web-based tools only):
- PHP 7.4+
- WordPress 6.0+
- 128MB memory

**Required for AI Development Assistant**:
- ✅ VPS or Dedicated Server (NOT shared hosting)
- ✅ Shell access (SSH)
- ✅ PHP proc_open/proc_close enabled
- ✅ Node.js 16+ (for Claude CLI)
- ✅ Git installed
- ✅ 512MB+ memory
- ✅ Sudo access (for CLI installations)

**Optional but Recommended**:
- ✅ tmux (for session monitoring)
- ✅ GitHub CLI (for Copilot integration)
- ✅ Composer (for PHP projects)
- ✅ 1GB+ memory (for larger projects)

### Installation Guide for Users

```markdown
## Installing AI Development Assistant (Pro)

### Prerequisites Check

Run this command on your server:
```bash
wp mcp-ai pro check-requirements
```

This checks for:
- PHP functions: proc_open, proc_close, proc_terminate
- Node.js version
- Git availability
- Memory limits
- Shell access

### Install Claude Code CLI

```bash
# Install via npm
npm install -g @anthropic-ai/claude-cli

# Configure with your API key
claude config --api-key YOUR_ANTHROPIC_API_KEY

# Verify installation
claude --version
```

### Install GitHub Copilot CLI (Optional)

```bash
# Install GitHub CLI first
# See: https://github.com/cli/cli#installation

# Install Copilot extension
gh extension install github/gh-copilot

# Authenticate
gh auth login

# Verify
gh copilot --version
```

### Install tmux (Optional but Recommended)

```bash
# Ubuntu/Debian
sudo apt-get install tmux

# CentOS/RHEL
sudo yum install tmux

# macOS
brew install tmux
```

### Configure in WordPress

1. Go to **Settings → NV oOS Pro → AI Development**
2. Click **Check Status** for each CLI tool
3. Configure workspace directories
4. Set rate limits
5. Save settings

### Test Your Setup

```bash
wp mcp-ai pro test-cli --tool=claude
wp mcp-ai pro test-cli --tool=copilot
```

All green? You're ready to use AI Development Assistant! 🎉
```

## Revised Implementation Timeline

### Phase 1: CLI Foundation (Week 1-2)
- [ ] CLI Manager base classes
- [ ] Claude Code CLI integration
- [ ] Workspace security system
- [ ] Rate limiting enhancements
- [ ] Basic session tracking
- [ ] Requirements checker

**Deliverable**: Single-execution CLI tools work

### Phase 2: Ralph Pattern Core (Week 3-4)
- [ ] 13 orchestration tools (not 8)
- [ ] Dual-condition exit detection
- [ ] Circuit breaker implementation
- [ ] Response analyzer
- [ ] Session lifecycle management
- [ ] Health monitoring

**Deliverable**: Autonomous sessions work

### Phase 3: Copilot Integration (Week 5)
- [ ] GitHub Copilot CLI Manager
- [ ] Code suggestion tools
- [ ] Test generation
- [ ] Unified AI Dev Assistant tool

**Deliverable**: Both CLIs integrated

### Phase 4: Admin UI (Week 6-7)
- [ ] Development Assistant Dashboard
- [ ] Live Session Monitor
- [ ] Task Plan management UI
- [ ] Settings page
- [ ] Emergency controls

**Deliverable**: Full Pro UI complete

### Phase 5: tmux Integration (Week 8)
- [ ] tmux session management
- [ ] Live output streaming
- [ ] Multi-session support
- [ ] Dashboard real-time updates

**Deliverable**: Ralph feature parity achieved

### Phase 6: Polish & Documentation (Week 9-10)
- [ ] Installation guides
- [ ] Video tutorials
- [ ] API documentation
- [ ] Security audit
- [ ] Performance optimization
- [ ] Compliance review (WordPress.org)

**Deliverable**: Production-ready Pro feature

**Total Timeline**: 10 weeks (2.5 months)
**Estimated Effort**: 250-300 hours

## Pricing Recommendation

### Free (Base Plugin)
- ✅ 65+ web-based tools
- ✅ Standard agentic loops (5-15 iterations)
- ✅ Basic task management
- ❌ CLI integration
- ❌ Autonomous orchestration
- ❌ Development assistant

### Pro Addon ($199/year or $299 lifetime)
- ✅ Everything in Free
- ✅ 6 exec-based tools (existing)
- ✅ **Claude Code CLI integration** 🆕
- ✅ **GitHub Copilot CLI integration** 🆕
- ✅ **Autonomous development loops (50 iterations)** 🆕
- ✅ **Ralph pattern implementation** 🆕
- ✅ **tmux session monitoring** 🆕
- ✅ **Development Assistant Dashboard** 🆕
- ✅ **Task plan automation** 🆕
- ✅ **Multi-project orchestration** 🆕

### Pro Ultra (Future - $499/year)
- ✅ Everything in Pro
- ✅ Multi-server orchestration
- ✅ Team collaboration features
- ✅ Custom CLI tool integration
- ✅ Priority support
- ✅ White-label options

## Next Steps

1. ✅ **Approve revised architecture** (CLI integration as core Pro feature)
2. **Begin Phase 1**: CLI foundation and security
3. **Alpha testing**: Limited rollout to VPS users
4. **Iterate**: Based on feedback
5. **Full release**: Pro addon v2.0 with AI Development Assistant

---

## Summary: Why This Makes Sense

### ✅ Addresses All Requirements
1. Native WordPress implementation (not external Ralph integration)
2. Full Ralph pattern support via CLI integration
3. Enhances Pro task management toolkit significantly
4. Both Claude Code and Copilot integrated
5. All 13 Ralph features implemented

### ✅ Business Value
- Justifies Pro addon premium pricing
- Differentiates from competitors
- Appeals to developer/agency market
- Leverages existing Symfony Process infrastructure

### ✅ Technical Soundness
- Builds on proven Pro addon patterns
- Reuses existing security infrastructure
- Modular, testable architecture
- Graceful degradation (works without CLIs)

### ✅ User Experience
- Single unified interface for AI development
- Familiar WordPress admin patterns
- Real-time monitoring dashboards
- Safe workspace management

**Recommendation**: Proceed with this approach for Pro addon v2.0 ✅
