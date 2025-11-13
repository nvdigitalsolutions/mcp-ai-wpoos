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

PHASE 3: Split REST Controller (WEEK 6-10) - In Progress ⏩
┌──────────────────────────────────────────────────────────────────────┐
│  Risk: 🟡 MEDIUM (incremental extraction)                            │
│  Time: 4-5 weeks total                                               │
│                                                                       │
│  [✓] Phase 3.1 (Week 6): Base Controller Class                      │
│      ✅ Abstract base class created (265 lines)                      │
│      ✅ Multi-client authentication support                          │
│      ✅ Template method pattern implemented                          │
│      ✅ 11 unit tests passing                                        │
│                                                                       │
│  [→] Phase 3.2 (Week 7): Chat Controller ← NEXT STEP                │
│      Extract chat-related endpoints (~800 lines):                    │
│      - /chat (MCP clients, 5 iterations)                             │
│      - /chat-client (Browser clients, 15 iterations)                 │
│      - /chat-transcripts (List all)                                  │
│      - /chat-transcripts/{session_key} (Individual ops)              │
│                                                                       │
│  [ ] Phase 3.3 (Week 8): MCP Protocol Controller                    │
│      Extract MCP-specific endpoints (~600 lines):                    │
│      - /mcp (JSON-RPC 2.0 compliance)                                │
│      - /sse (Server-sent events streaming)                           │
│      - /assistants (MCP directory listing)                           │
│                                                                       │
│  [ ] Phase 3.4 (Week 9): Tools & Admin Controllers                  │
│      Extract remaining endpoints (~700 lines):                       │
│      - /tools (Tool execution)                                       │
│      - /cron-status (Admin dashboard)                                │
│      - /files/{id}/download (File operations)                        │
│                                                                       │
│  [ ] Phase 3.5 (Week 10): Cleanup & Optimization                    │
│      Finalize extraction:                                            │
│      - Convert main REST to router/delegator (~1,500 lines)          │
│      - Optimize route registration                                   │
│      - Final testing and validation                                  │
│                                                                       │
│  Result After Phase 3:                                               │
│  - 1 base controller (shared logic)                                  │
│  - 4 specialized controllers (focused responsibilities)              │
│  - Main REST controller as router (~1,500 lines, down from 7,289)   │
│  - Zero breaking changes                                             │
└──────────────────────────────────────────────────────────────────────┘

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

PROGRESS TRACKER
┌──────────────────────────────────────────────────────────────────────┐
│                                                                       │
│  Week 1: [✓] Phase 1.1 Complete - Settings Repository Migration     │
│  Week 2: [✓] Phase 1.2 Complete - 3 More Services                   │
│  Week 3: [✓] Phase 1.3 Complete - Database Query Extraction         │
│  Week 4: [✓] Phase 2 Complete - Hard-coded Dependencies Removed     │
│  Week 5: [✓] Phase 2.2 Complete - Service Layer Complete            │
│  Week 6: [✓] Phase 3.1 Complete - Base Controller Created           │
│  Week 7: [→] Phase 3.2 Next - Chat Controller Extraction            │
│  Week 8: [ ] Phase 3.3 - MCP Protocol Controller                    │
│  Week 9: [ ] Phase 3.4 - Tools & Admin Controllers                  │
│  Week 10: [ ] Phase 3.5 - Cleanup & Optimization                    │
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

Phase 2.2 ✅
  ▸ Service layer 100% complete
  ▸ Zero direct option calls
  ▸ Consistent DI patterns

Phase 3.1 ✅
  ▸ Foundation for extraction
  ▸ Multi-client auth centralized
  ▸ Template method pattern
  ▸ Reusable base class

Phase 3.2 → (Next)
  ▸ Chat logic isolated
  ▸ ~800 lines extracted
  ▸ Most-used endpoints focused
  ▸ SSE streaming preserved

Phase 3.3-3.5 (Future)
  ▸ Maintainable code
  ▸ Clear responsibilities
  ▸ Easy to navigate
  ▸ Fast feature development

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

CURRENT STATUS: PHASE 3.2 - CHAT CONTROLLER EXTRACTION

Phase 3.1 Complete ✅ - Base controller foundation is ready
Next Step → Phase 3.2 - Extract chat endpoints (~800 lines)

📖 See SEPARATION_PLAN_NEXT_STEP.md for detailed next steps
📖 See PHASE_3_VISUAL_GUIDE.md for architecture diagrams
📖 See PHASE_3_1_COMPLETE.md for what was just completed

Remember: "Incremental progress with validation at each step" ✅
```
