# Separation of Concerns - Incremental Roadmap

```
┌─────────────────────────────────────────────────────────────────────────┐
│                     INCREMENTAL REFACTORING ROADMAP                      │
│                  "Don't do too much at once" - Approach                  │
└─────────────────────────────────────────────────────────────────────────┘

Current State:
┌──────────────────────────────────────────────────────────────────────┐
│  ✅ Services layer exists (12 services)                               │
│  ✅ Repositories layer exists (3 repositories)                        │
│  ⚠️  Services directly call get_option() - 38 instances              │
│  ⚠️  REST controller is God Object - 7,176 lines                     │
│  ⚠️  Hard-coded dependencies - 42 instances of 'new ClassName()'    │
└──────────────────────────────────────────────────────────────────────┘

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

PHASE 1.1: Settings Repository Migration (WEEK 1) ⭐ START HERE
┌──────────────────────────────────────────────────────────────────────┐
│  Risk: 🟢 VERY LOW                                                    │
│  Time: 2-3 hours                                                     │
│  Impact: Small but demonstrates pattern                              │
│                                                                       │
│  Changes:                                                             │
│  ✏️  Refactor 1 service: WP_MCP_AI_Performance_Reporting_Service     │
│  ✏️  Replace 2 get_option() calls                                    │
│  ✏️  Replace 2 update_option() calls                                 │
│  ✏️  Add constructor dependency injection                            │
│  ➕ Add tests for service                                            │
│                                                                       │
│  Files Changed: 3                                                     │
│  Lines Changed: ~15                                                   │
│                                                                       │
│  Success Criteria:                                                    │
│  ✅ grep returns 0 results for get_option in service                 │
│  ✅ All tests pass                                                    │
│  ✅ Performance reporting still works                                 │
│  ✅ Team understands pattern                                          │
└──────────────────────────────────────────────────────────────────────┘
                                    ↓
                    ⏸️  PAUSE - Verify Everything Works
                                    ↓

PHASE 1.2: More Services (WEEK 2) - Only if Phase 1.1 succeeds
┌──────────────────────────────────────────────────────────────────────┐
│  Risk: 🟢 LOW                                                         │
│  Time: 1 week                                                        │
│                                                                       │
│  Refactor 2-3 more services:                                          │
│  ✏️  WP_MCP_AI_Orchestration_Health_Service                          │
│  ✏️  WP_MCP_AI_Performance_Monitor_Service                           │
│  ✏️  WP_MCP_AI_Error_Tracking_Service                                │
│                                                                       │
│  Apply same pattern learned in Phase 1.1                             │
└──────────────────────────────────────────────────────────────────────┘
                                    ↓
                    ⏸️  PAUSE - Measure Impact
                                    ↓

PHASE 1.3: Extract One Database Query (WEEK 3)
┌──────────────────────────────────────────────────────────────────────┐
│  Risk: 🟡 MEDIUM                                                      │
│  Time: 1 week                                                        │
│                                                                       │
│  Find ONE $wpdb query in REST controller                             │
│  Extract to new or existing repository                               │
│  Update REST controller to use repository                            │
│                                                                       │
│  Example: Transcript deletion query (line 1311)                      │
│  Move to: WP_MCP_AI_Transcript_Repository                            │
└──────────────────────────────────────────────────────────────────────┘
                                    ↓
                    ⏸️  PAUSE - Review Progress
                                    ↓

PHASE 2: Remove Hard-coded Dependencies (WEEK 4-5)
┌──────────────────────────────────────────────────────────────────────┐
│  Risk: 🟡 MEDIUM                                                      │
│  Time: 2 weeks                                                       │
│                                                                       │
│  Remove 5-10 'new ClassName()' calls from REST controller            │
│  Use dependency injection instead                                    │
│  Update container to provide dependencies                            │
│                                                                       │
│  Example: new WP_MCP_AI_REST_Authenticator()                         │
│  Change to: Constructor injection                                    │
└──────────────────────────────────────────────────────────────────────┘
                                    ↓
                    ⏸️  PAUSE - Evaluate if Continue
                                    ↓

PHASE 3: Split REST Controller (WEEK 6-9) - Only if ready
┌──────────────────────────────────────────────────────────────────────┐
│  Risk: 🔴 HIGH                                                        │
│  Time: 3-4 weeks                                                     │
│                                                                       │
│  ⚠️  This is BIG REFACTORING - only do after Phases 1-2 succeed     │
│                                                                       │
│  Split into:                                                          │
│  - WP_MCP_AI_Chat_Controller                                          │
│  - WP_MCP_AI_Assistant_Controller                                     │
│  - WP_MCP_AI_Tool_Controller                                          │
│  - WP_MCP_AI_Transcript_Controller                                    │
│  - WP_MCP_AI_File_Controller                                          │
└──────────────────────────────────────────────────────────────────────┘

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

PROGRESS TRACKER
┌──────────────────────────────────────────────────────────────────────┐
│                                                                       │
│  Week 1: [ ] Phase 1.1 Complete                                      │
│  Week 2: [ ] Phase 1.2 Complete                                      │
│  Week 3: [ ] Phase 1.3 Complete                                      │
│  Week 4: [ ] Phase 2 Started                                         │
│  Week 5: [ ] Phase 2 Complete                                        │
│                                                                       │
│  Phase 3: ⏸️  Decision point - evaluate if needed                    │
│                                                                       │
└──────────────────────────────────────────────────────────────────────┘

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

KEY PRINCIPLES
┌──────────────────────────────────────────────────────────────────────┐
│                                                                       │
│  1. ⏸️  PAUSE after each phase                                       │
│  2. ✅ VERIFY everything works before continuing                     │
│  3. 📊 MEASURE improvements (tests, coupling, etc.)                  │
│  4. 🛑 STOP if something breaks - fix or revert                      │
│  5. 📚 DOCUMENT learnings and patterns                               │
│  6. 👥 SHARE knowledge with team                                     │
│  7. 🧪 TEST thoroughly before merging                                │
│  8. 🐢 GO SLOW to go fast in the long run                           │
│                                                                       │
└──────────────────────────────────────────────────────────────────────┘

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

BENEFITS BY PHASE

Phase 1.1 ✅
  ▸ Pattern established
  ▸ Team learns approach
  ▸ 1 service more testable
  ▸ Confidence built

Phase 1.2 ✅
  ▸ 3-4 services testable
  ▸ Pattern validated
  ▸ Measurable improvement

Phase 1.3 ✅
  ▸ REST controller cleaner
  ▸ Data access abstracted
  ▸ Repository pattern proven

Phase 2 ✅
  ▸ Better testability
  ▸ Flexible dependencies
  ▸ Easier mocking

Phase 3 ✅
  ▸ Maintainable code
  ▸ Clear responsibilities
  ▸ Easy to navigate
  ▸ Fast feature development

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

CURRENT RECOMMENDATION: START WITH PHASE 1.1

📖 See IMPLEMENTATION_GUIDE_PHASE_1_1.md for detailed instructions
⚡ See QUICK_START_PHASE_1_1.md for 30-second overview
📚 See NEXT_STEP_SEPARATION_OF_CONCERNS.md for rationale

Remember: "Let's not do too much at one time, don't want things to break" ✅
```
