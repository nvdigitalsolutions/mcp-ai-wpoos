# Ralph Wiggum Task Orchestration - Quick Reference

**Version:** 2.0.0  
**Status:** Revised Proposal  
**Implementation:** Pro Addon with CLI Integration  
**Updated:** 2026-01-22

## What is Ralph Wiggum?

A pattern for **autonomous AI development loops** that enables continuous improvement cycles with intelligent exit detection and self-healing workflows.

## Core Concepts

### 🔄 Autonomous Loops
- AI agents iterate on tasks without manual intervention
- Each cycle refines and improves the work
- Continues until completion or safety limits reached

### 🚪 Dual-Condition Exit Gates
```
Exit ONLY when BOTH conditions are true:
1. Completion indicators ≥ 2 (semantic analysis)
2. EXIT_SIGNAL: true (explicit confirmation)
```

### 📋 Task File Management
- **@fix_plan.md**: Markdown checklist of tasks with priorities
- **PROMPT.md**: Execution instructions for each iteration
- Checkbox-based progress tracking

### ⚡ Circuit Breakers
- Detects stuck loops (same action repeating)
- Prevents API waste (rate limiting)
- Auto-stops on error cascades

## Implementation in NV oOS Pro

### Revised Approach: CLI Integration + Ralph Patterns

**Key Decision**: After requirement clarification, implementation includes:
- ✅ Claude Code CLI integration (full Ralph feature set)
- ✅ GitHub Copilot CLI integration (code assistance)
- ✅ 13 orchestration tools (expanded from 8)
- ✅ tmux session monitoring
- ✅ Pro addon exclusive feature

See [CLI-INTEGRATION-STRATEGY.md](CLI-INTEGRATION-STRATEGY.md) for complete details.

### Phase 1: Core Toolset (13 New Tools)

| Tool | Purpose | Status |
|------|---------|--------|
| `create_task_plan` | Initialize project with task checklist | Proposed |
| `update_task_plan` | Mark tasks complete, add new tasks | Proposed |
| `get_task_plan` | Retrieve current plan status | Proposed |
| `create_execution_prompt` | Generate iteration instructions | Proposed |
| `detect_completion_indicators` | Semantic completion analysis | Proposed |
| `check_exit_conditions` | Dual-condition exit logic | Proposed |
| `manage_autonomous_session` | Session lifecycle management | Proposed |
| `analyze_loop_health` | Monitor for runaway loops | Proposed |
| `ai_dev_assistant` | **Unified CLI interface (Claude + Copilot)** | Proposed |
| `get_session_status` | **Real-time session monitoring** | Proposed |
| `control_session` | **Pause/resume/stop sessions** | Proposed |
| `import_prd` | **Import requirements from documents** | Proposed |
| `monitor_tmux` | **Live terminal output streaming** | Proposed |

### Phase 2: CLI Integration

**Claude Code CLI Manager:**
- Autonomous development sessions
- Multi-file editing
- External project support
- tmux session monitoring

**GitHub Copilot CLI Manager:**
- Code suggestions and completions
- Test generation
- Code explanations
- Refactoring assistance

### Phase 3: Enhanced Orchestration Mode

**New Assistant Settings:**
- ✅ Enable Autonomous Mode
- 🔢 Max Iterations (default: 20)
- ⏱️ Session Timeout (default: 24 hours)
- 🚪 Require EXIT_SIGNAL (default: true)
- 📊 Min Completion Indicators (default: 2)

**New Admin Dashboard:**
- Real-time session monitoring
- Task plan management
- Health metrics and circuit breaker status
- Execution history with analytics

## Comparison with Current NV oOS

| Feature | Current NV oOS | With Ralph Enhancement |
|---------|----------------|------------------------|
| Agentic Loops | ✅ 5-15 iterations | ✅ 20-50 iterations (configurable) |
| Exit Detection | ✅ `finish_reason` only | ✅ Dual-condition (heuristic + explicit) |
| Task Planning | ❌ None | ✅ Markdown-based checklists |
| Session Management | ⚠️ Basic | ✅ Full lifecycle with expiration |
| Health Monitoring | ⚠️ Basic | ✅ Comprehensive with circuit breakers |
| File-Based Prompts | ❌ None | ✅ PROMPT.md + @fix_plan.md |
| Auto-Reset | ❌ Manual | ✅ Automatic on completion/error |

## Quick Start (Post-Implementation)

### 1. Create a Task Plan
```
User: "Create a task plan for building a contact form plugin"
AI: [Uses create_task_plan tool]
"I've created task plan #123 with 8 tasks:
- [ ] Design form UI (High priority)
- [ ] Create database schema (High priority)
- [ ] Build form submission handler (Medium priority)
..."
```

### 2. Enable Autonomous Mode
1. Edit assistant in WordPress admin
2. Check "Enable Autonomous Mode"
3. Set max iterations: 25
4. Link to task plan #123
5. Save

### 3. Start Autonomous Session
```
User: "Start autonomous work on task plan #123"
AI: [Uses manage_autonomous_session tool with action='start']
"Session abc123 started. I'll work through the task plan and 
report back when complete or if I need assistance."

[AI begins autonomous loop...]
```

### 4. Monitor Progress
- Navigate to **WordPress Admin → NV oOS → Task Orchestration**
- View active sessions in real-time
- See task completion status
- Review health metrics

### 5. Natural Completion
```
Iteration 18:
AI: "All tasks in the plan are complete. The contact form plugin
is functional and tested. EXIT_SIGNAL: true"

System: [Checks exit conditions]
- Completion indicators: 4 (exceeds threshold of 2)
- EXIT_SIGNAL: true
- Result: Session terminated gracefully ✅
```

## Best Practices

### ✅ DO
- Start with small task plans (3-5 tasks) to test
- Set realistic iteration limits (15-25 for most projects)
- Monitor first autonomous session closely
- Use explicit success criteria in PROMPT.md
- Review task completion regularly

### ❌ DON'T
- Don't set iteration limits >50 (runaway protection)
- Don't leave sessions unmonitored initially
- Don't use autonomous mode for critical production tasks (yet)
- Don't ignore circuit breaker warnings
- Don't skip budget limits

## Safety Features

### 1. Hard Limits
- Max 50 iterations per session (system enforced)
- Max 3 concurrent sessions per user
- 24-hour session expiration (configurable)
- Token budget caps respected

### 2. Circuit Breakers
- **Stuck Loop**: Detects identical repeated actions
- **Error Cascade**: Stops after 5+ consecutive errors
- **Budget Exhaustion**: Halts at 90% token usage
- **Time Limit**: Auto-stops at 95% session duration

### 3. Health Monitoring
- Analyzed every 3rd iteration
- Warning alerts sent to admin
- Critical issues trigger auto-stop
- Full audit trail maintained

## Exit Conditions Explained

### Natural Exit (Good ✅)
```
Condition 1: Completion Indicators ≥ 2
- "task complete" detected
- "all requirements met" detected
- Markdown checklist: 100% complete
- "ready for review" detected
Total: 4 indicators

Condition 2: EXIT_SIGNAL = true
- AI explicitly confirms: "EXIT_SIGNAL: true"

Result: BOTH conditions met → Graceful exit
```

### Premature Exit Prevention (Good ✅)
```
Condition 1: Completion Indicators = 3
- "phase complete" detected
- "moving to next feature" detected
Total: 2 indicators (threshold met)

Condition 2: EXIT_SIGNAL = false
- AI says: "More work needed on authentication"

Result: Only 1 condition met → Continue iteration
```

### Emergency Exit (Safety 🛑)
```
Forced exit when:
- Max iterations reached (e.g., 50)
- Circuit breaker triggered (stuck loop detected)
- Budget exhausted (<5% tokens remaining)
- Session expired (>24 hours)
- Manual stop by admin

Result: Immediate termination with reason logged
```

## Example Task Plan Format

```markdown
# Project: Contact Form Plugin

## Goal
Create a WordPress plugin that adds a customizable contact form
with spam protection and email notifications.

## Tasks
- [x] Design form UI (Priority: High)
- [x] Create database schema (Priority: High)
- [ ] Build form submission handler (Priority: Medium)
- [ ] Add email notification system (Priority: Medium)
- [ ] Implement spam protection (Priority: Medium)
- [ ] Create admin settings page (Priority: Low)
- [ ] Write documentation (Priority: Low)
- [ ] Add unit tests (Priority: Low)

## Dependencies
- Email system depends on form handler
- Admin page depends on database schema
- Testing depends on all features being complete

## Success Criteria
- Form renders correctly on frontend
- Submissions save to database
- Emails send successfully
- Admin can configure all settings
- All tests pass

## Current Status
Progress: 25% (2 of 8 tasks complete)
Last Updated: 2026-01-22 15:30:00
```

## Example Execution Prompt

```markdown
# Execution Prompt: Contact Form Plugin - Iteration 3

## Objective
Build the form submission handler for the contact form plugin.

## Current Task
Task #3: Build form submission handler (Priority: Medium)

## Context
- Database schema is complete (wp_contact_submissions table exists)
- Form UI is rendering correctly
- Need to handle POST requests and save to database

## Available Tools
- save_post (create/update WordPress posts)
- get_recent_posts (query existing data)
- search_content (find relevant code)
- web_search (research best practices)

## Constraints
- Budget: 2000 tokens remaining
- Max iterations: 22 remaining (3 of 25 used)
- Timeout: 23 hours remaining

## Success Criteria
- POST endpoint handles form submissions
- Data is validated and sanitized
- Submissions save to wp_contact_submissions table
- Proper error handling and user feedback
- Security measures (nonce verification, capability checks)

## Exit Signal
Report EXIT_SIGNAL: true when:
1. Form submission handler is fully functional
2. All success criteria are met
3. Code is tested and working
4. No security vulnerabilities present
```

## Monitoring & Metrics

### Session Health Dashboard

**Healthy Session:**
```
Session ID: abc123xyz
Status: Active
Task Plan: Contact Form Plugin (#123)
Progress: 37% (3 of 8 tasks)
Iteration: 8 of 25
Budget: 65% remaining (6500/10000 tokens)
Health: ✅ Healthy
Warnings: None
Time Remaining: 22.5 hours
```

**Warning Session:**
```
Session ID: def456uvw
Status: Active ⚠️
Task Plan: E-commerce Integration (#456)
Progress: 75% (6 of 8 tasks)
Iteration: 42 of 50
Budget: 15% remaining (1500/10000 tokens)
Health: ⚠️ Warning
Warnings:
- Approaching iteration limit (42/50)
- Low budget remaining (15%)
- Same task attempted 3 times
Recommendation: Consider manual intervention
Time Remaining: 3.2 hours
```

**Critical Session:**
```
Session ID: ghi789rst
Status: Paused 🛑
Task Plan: Database Migration (#789)
Progress: 50% (4 of 8 tasks)
Iteration: 50 of 50 (LIMIT REACHED)
Budget: 5% remaining (500/10000 tokens)
Health: 🛑 Critical
Issues:
- Max iterations reached
- Stuck loop detected (same error 5x)
- Circuit breaker: OPEN
Action Taken: Auto-paused for review
Time Elapsed: 6.7 hours
```

## Troubleshooting

### Issue: Session Won't Exit
**Symptoms**: AI keeps iterating past task completion
**Causes**:
1. EXIT_SIGNAL not explicitly returned
2. Completion indicators below threshold
3. Task plan checklist not updated

**Solutions**:
1. Remind AI to include "EXIT_SIGNAL: true" in response
2. Update task plan manually to trigger completion check
3. Review PROMPT.md for clear exit criteria

### Issue: Premature Exit
**Symptoms**: Session stops before work is complete
**Causes**:
1. AI incorrectly returns EXIT_SIGNAL: true
2. False positive completion indicators
3. Budget exhausted unexpectedly

**Solutions**:
1. Increase min_completion_indicators threshold
2. Make success criteria more specific in PROMPT.md
3. Increase token budget allocation

### Issue: Stuck Loop
**Symptoms**: Same action repeated multiple times
**Causes**:
1. Tool returning errors consistently
2. AI not recognizing tool output
3. Missing required context or permissions

**Solutions**:
1. Check tool logs for error patterns
2. Add explicit error handling in PROMPT.md
3. Verify assistant has required capabilities
4. Manual intervention to provide missing information

## Future Enhancements (Phase 3+)

### Project Templates
- Pre-built task plans for common projects
- WordPress plugin development template
- Theme customization template
- Content migration template

### External Integrations
- GitHub Issues synchronization
- Jira/Asana project import
- Slack notifications for session updates
- Email reports for completed sessions

### Advanced Analytics
- Success rate by project type
- Average iterations per task category
- Budget efficiency metrics
- Time-to-completion predictions

### Collaborative Features
- Multi-assistant team workflows
- Human approval checkpoints
- Peer review integration
- Stakeholder notifications

---

## Quick Links

- **Full Proposal**: [RALPH-WIGGUM-TASK-ORCHESTRATION-IMPLEMENTATION.md](RALPH-WIGGUM-TASK-ORCHESTRATION-IMPLEMENTATION.md)
- **Existing Orchestration Docs**: `/docs/architecture/orchestration/`
- **Tool Development Guide**: `/docs/guides/developer/tool-development/`
- **Best Practices**: `/docs/guides/developer/best-practices/BEST_PRACTICES.md`

## Feedback & Questions

For implementation questions or feedback on this proposal:
1. Open a GitHub issue with label `enhancement:orchestration`
2. Tag maintainers in discussions
3. Reference proposal document in comments

---

**Status**: ✅ Ready for review and approval  
**Next Step**: Approve Phase 1 implementation and begin core toolset development
