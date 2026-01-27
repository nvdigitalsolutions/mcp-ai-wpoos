# CPT Research Enhancement - Implementation Review

## All Custom Post Types in Plugin

### Core CPTs (Base Plugin)
1. **mcp_ai_assistant** - AI Assistants
   - **Research Needed?** No - Internal configuration, not user-facing content
   
2. **mcp_ai_profession** - Professions/Job Roles
   - **Research Needed?** No - Pre-seeded from knowledge base
   
3. **mcp_ai_team** - Teams
   - **Research Needed?** No - Internal organizational structure
   
4. **ai_peer** - AI Peers
   - **Research Needed?** No - Internal AI configuration

### Pro CPTs - Already Implemented Research Pages
5. **mcp_ai_place** - Places/Attractions/Locations ✅
   - **Research Needed?** YES - IMPLEMENTED
   - **Research Page:** `WP_MCP_AI_Place_Research_Page`
   - **Research Tool:** `research_place`
   - **Use Case:** Research attractions, businesses, landmarks before adding

6. **mcp_ai_quiz** - Quizzes ✅
   - **Research Needed?** YES - IMPLEMENTED  
   - **Research Page:** `WP_MCP_AI_Quiz_Research_Page`
   - **Research Tool:** `research_quiz_topic` (needs implementation)
   - **Use Case:** Research topics and generate quiz questions

### Pro CPTs - Project Management (Has Custom AI Assistant)
7. **mcp_ai_project** - Projects
   - **Research Needed?** No - Has specialized AI Assistant metabox with quick actions
   - **Note:** Already has `WP_MCP_AI_Project_Management_AI_Assistant_Metabox`

8. **mcp_ai_task** - Tasks
   - **Research Needed?** No - Has specialized AI Assistant metabox with quick actions
   - **Note:** Part of project management suite

9. **mcp_ai_event** - Events
   - **Research Needed?** No - Has specialized AI Assistant metabox with quick actions
   - **Note:** Part of project management suite

### Pro CPTs - Education/ECAs
10. **mcp_ai_eca** - Extra-Curricular Activities
    - **Research Needed?** MAYBE - Could benefit from research
    - **Use Case:** Research ECA programs, curricula, activities before adding
    - **Priority:** Medium

11. **mcp_ai_student** - Students
    - **Research Needed?** No - Personal data entry, not research-based

### Pro CPTs - Health & Wellness
12. **mcp_ai_member** - Members (people/pets)
    - **Research Needed?** No - Personal data entry

13. **mcp_ai_policy** - Insurance Policies
    - **Research Needed?** MAYBE - Could research insurance policy types
    - **Use Case:** Research insurance policy templates and requirements
    - **Priority:** Low

14. **mcp_ai_med_record** - Medical Records
    - **Research Needed?** No - Personal health data entry

15. **mcp_ai_checkup** - Checkups/Appointments
    - **Research Needed?** No - Personal scheduling data

16. **mcp_ai_prescription** - Prescriptions
    - **Research Needed?** No - Personal health data entry

17. **mcp_ai_allergy** - Allergies
    - **Research Needed?** No - Personal health data entry

### Related CPTs (Submissions)
18. **mcp_ai_submission** - Quiz Submissions
    - **Research Needed?** No - User-generated submission data

## Implementation Status

### ✅ Completed
- [x] Place Research Page with chat UI
- [x] Quiz Research Page with chat UI  
- [x] `research_place` tool implementation
- [x] Removed broken metabox integration from Place & Quiz CPTs

### 🔄 In Progress
- [ ] `research_quiz_topic` tool implementation
- [ ] CSS for research pages (`research-page.css`)
- [ ] JavaScript for research pages (`research-page.js`)
- [ ] Initialize research pages in appropriate init files

### 💡 Potential Future Additions
- [ ] ECA Research Page (Medium priority)
- [ ] Policy Research Page (Low priority)

## Recommendations

### Must Complete Now
1. **Implement `research_quiz_topic` tool** - Similar to `research_place` but for educational topics
2. **Create CSS file** - `addons/pro/assets/css/research-page.css`
3. **Create JS file** - `addons/pro/assets/js/research-page.js`
4. **Load research pages** - Add to appropriate init files

### Can Add Later
1. **ECA Research** - If users need to research educational programs
2. **Policy Research** - If users need insurance policy templates

### Don't Add
- Internal config CPTs (assistants, professions, teams, peers)
- Personal data CPTs (members, medical records, checkups, prescriptions, allergies, students)
- CPTs with existing specialized UI (project management suite)

## File Structure

```
addons/pro/
├── includes/
│   ├── admin/
│   │   ├── class-wp-mcp-ai-place-research-page.php ✅
│   │   ├── class-wp-mcp-ai-quiz-research-page.php ✅
│   │   └── class-wp-mcp-ai-pro-cpt-ai-integration.php (updated) ✅
│   ├── tools/
│   │   ├── class-wp-mcp-ai-tool-research-place.php ✅
│   │   └── class-wp-mcp-ai-tool-research-quiz-topic.php ⏳
│   ├── metaboxes/ (deprecated - replaced by research pages)
│   │   ├── class-wp-mcp-ai-research-metabox-base.php ❌ Not needed
│   │   ├── places/class-wp-mcp-ai-place-research-metabox.php ❌ Not needed
│   │   └── class-wp-mcp-ai-quiz-research-metabox.php ❌ Not needed
│   ├── places-management-init.php (needs update to load research page)
│   └── quiz-system-init.php (needs update to load research page - if exists)
└── assets/
    ├── css/
    │   └── research-page.css ⏳
    └── js/
        └── research-page.js ⏳
```

## Next Steps

1. Create `research_quiz_topic` tool
2. Create CSS and JS assets
3. Update init files to load research pages
4. Test complete workflow
5. Remove/deprecate metabox approach files
