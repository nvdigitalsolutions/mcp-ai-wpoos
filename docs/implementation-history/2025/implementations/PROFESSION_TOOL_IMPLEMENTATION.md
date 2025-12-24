# Implementation Plan: Profession Tool Enhancements

## Overview

This document provides the specific implementation plan for enhancing profession default tools based on extensive research. The changes will bring all 70 professions into the optimal 5-7 tool range.

## Implementation Summary

- **UI Changes**: ✅ Already implemented (search, filter, bulk actions)
- **Data Changes**: Update profession seeder with enhanced tool assignments
- **Priority**: Phase approach over 4 weeks
- **Validation**: Automated tests to verify tool count ranges

## Specific Code Changes

### File to Modify
`includes/professions/class-wp-mcp-ai-profession-seeder.php`

### Change Format

Each profession's `'default_tools'` array will be updated following this pattern:

```php
'default_tools' => array(
    // Core (3): Always include
    'web_search', 'search_content', 'save_post',
    // Category (2-3): Based on profession category  
    'category_tool_1', 'category_tool_2',
    // Specialty (1-2): Unique to this profession
    'specialty_tool_1'
),
```

---

## Phase 1: Critical Fixes (Priority 1)

### Healthcare Advisor
```php
// LINE ~861
// CURRENT (2 tools):
'default_tools' => array( 'web_search', 'search_content' ),

// PROPOSED (6 tools):
'default_tools' => array( 
    'web_search', 'search_content', 'save_post',          // Core
    'reliefweb_reports', 'create_chart',                  // Healthcare category
    'send_group_email'                                    // Communication
),
```

### Veterinarian  
```php
// LINE ~862 (approximate)
// CURRENT (2 tools):
'default_tools' => array( 'web_search', 'search_content' ),

// PROPOSED (6 tools):
'default_tools' => array( 
    'web_search', 'search_content', 'save_post',          // Core
    'create_chart', 'search_attachments',                 // Research tools
    'send_group_email'                                    // Client communication
),
```

### All Bookkeeper
```php
// LINE ~134
// CURRENT (3 tools - missing save_post):
'default_tools' => array( 'web_search', 'search_content', 'get_quickbooks_report' ),

// PROPOSED (6 tools):
'default_tools' => array( 
    'web_search', 'search_content', 'save_post',          // Core (ADD save_post)
    'get_quickbooks_report', 'create_chart',              // Financial category
    'send_group_email'                                    // Client communication
),
```

---

## Phase 2: Category-Wide Enhancements (Priority 2)

### Financial Category (4 professions)

#### Tax Advisor
```php
// LINE ~98
// CURRENT (4 tools):
'default_tools' => array( 'web_search', 'search_content', 'save_post', 'get_quickbooks_report' ),

// PROPOSED (6 tools):
'default_tools' => array( 
    'web_search', 'search_content', 'save_post',
    'get_quickbooks_report', 'create_chart',
    'send_group_email'
),
```

#### Accountant
```php
// LINE ~116
// CURRENT (4 tools):
'default_tools' => array( 'web_search', 'search_content', 'save_post', 'get_quickbooks_report' ),

// PROPOSED (7 tools):
'default_tools' => array( 
    'web_search', 'search_content', 'save_post',
    'get_quickbooks_report', 'create_chart',
    'send_group_email', 'create_cron_job'
),
```

#### Financial Advisor
```php
// LINE ~190 (approximate)
// CURRENT (3 tools):
'default_tools' => array( 'web_search', 'search_content', 'save_post' ),

// PROPOSED (7 tools):
'default_tools' => array( 
    'web_search', 'search_content', 'save_post',
    'get_quickbooks_report', 'create_chart',
    'send_group_email', 'search_attachments'
),
```

### Healthcare Category (15 professions)

Template for most healthcare professions with research focus:
```php
// CURRENT (3 tools):
'default_tools' => array( 'web_search', 'search_content', 'save_post' ),

// PROPOSED (6 tools):
'default_tools' => array( 
    'web_search', 'search_content', 'save_post',
    'create_chart', 'search_attachments',
    'reliefweb_reports'  // For global health roles
),
```

Specific healthcare enhancements:

#### Epidemiologist / Public Health Advisor / Global Health Specialist
```php
// PROPOSED (7 tools):
'default_tools' => array( 
    'web_search', 'search_content', 'save_post',
    'reliefweb_reports', 'create_chart',
    'get_open_meteo_forecast', 'send_group_email'
),
```

#### Medical Researcher / Pharmaceutical Researcher
```php
// PROPOSED (6 tools):
'default_tools' => array( 
    'web_search', 'search_content', 'save_post',
    'create_chart', 'search_attachments',
    'count_tokens'
),
```

#### Clinical Research Coordinator / Medical Science Liaison
```php
// Medical Science Liaison already has 4 tools - good base
// PROPOSED (6 tools):
'default_tools' => array( 
    'web_search', 'search_content', 'save_post',
    'send_group_email', 'create_chart',
    'reliefweb_reports'
),
```

### Technical Category (15 professions)

#### IT Consultant
```php
// LINE ~213 (approximate)
// CURRENT (3 tools):
'default_tools' => array( 'web_search', 'search_content', 'save_post' ),

// PROPOSED (7 tools):
'default_tools' => array( 
    'web_search', 'search_content', 'save_post',
    'get_site_health', 'check_site_security',
    'purge_cache', 'get_system_logs'
),
```

#### Software Engineer / Software Developer / Computer Scientist
```php
// CURRENT (3 tools):
'default_tools' => array( 'web_search', 'search_content', 'save_post' ),

// PROPOSED (7 tools):
'default_tools' => array( 
    'web_search', 'search_content', 'save_post',
    'search_attachments', 'get_site_summary',
    'check_site_security', 'count_tokens'
),
```

#### Data Scientist / Statistician / Data Analyst
```php
// CURRENT (3 tools):
'default_tools' => array( 'web_search', 'search_content', 'save_post' ),

// PROPOSED (7 tools):
'default_tools' => array( 
    'web_search', 'search_content', 'save_post',
    'create_chart', 'query_mesh_intelligent',
    'count_tokens', 'search_attachments'
),
```

#### Engineers (Mechanical, Electrical, Civil, Biomedical, Aerospace)
```php
// CURRENT (3 tools):
'default_tools' => array( 'web_search', 'search_content', 'save_post' ),

// PROPOSED (6 tools):
'default_tools' => array( 
    'web_search', 'search_content', 'save_post',
    'create_chart', 'search_attachments',
    'generate_openai_image'  // For technical diagrams
),
```

#### Mathematician / Physicist / Chemist / Biologist / Research Scientist
```php
// CURRENT (3 tools):
'default_tools' => array( 'web_search', 'search_content', 'save_post' ),

// PROPOSED (6 tools):
'default_tools' => array( 
    'web_search', 'search_content', 'save_post',
    'create_chart', 'count_tokens',
    'search_attachments'
),
```

### Creative Category (16 professions)

#### Graphic Artist / Graphic Designer
```php
// LINE ~378 & ~397 (approximate)
// CURRENT (5 tools):
'default_tools' => array( 'web_search', 'search_content', 'save_post', 
                          'generate_openai_image', 'generate_gemini_image' ),

// PROPOSED (7 tools):
'default_tools' => array( 
    'web_search', 'search_content', 'save_post',
    'generate_openai_image', 'generate_gemini_image',
    'resize_image', 'crop_image'
),
```

#### Photographer
```php
// LINE ~492 (approximate)
// CURRENT (4 tools):
'default_tools' => array( 'web_search', 'search_content', 'save_post', 'search_attachments' ),

// PROPOSED (7 tools):
'default_tools' => array( 
    'web_search', 'search_content', 'save_post',
    'search_attachments', 'resize_image',
    'crop_image', 'generate_image_caption'
),
```

#### Video Producer / Video Editor / Videographer / Film Editor
```php
// CURRENT (3-4 tools):
'default_tools' => array( 'web_search', 'search_content', 'save_post' [, 'generate_openai_image'] ),

// PROPOSED (7 tools):
'default_tools' => array( 
    'web_search', 'search_content', 'save_post',
    'generate_sora_video', 'generate_veo_video',
    'analyze_video', 'generate_video_caption'
),
```

#### Content Creator
```php
// LINE ~511 (approximate)
// CURRENT (5 tools - already good):
'default_tools' => array( 'web_search', 'search_content', 'save_post', 
                          'post_facebook_instagram', 'post_linkedin_update' ),

// PROPOSED (7 tools):
'default_tools' => array( 
    'web_search', 'search_content', 'save_post',
    'post_facebook_instagram', 'post_linkedin_update',
    'generate_openai_image', 'get_rankmath_seo'
),
```

#### Web Designer
```php
// LINE ~435 (approximate)
// CURRENT (4 tools):
'default_tools' => array( 'web_search', 'search_content', 'save_post', 'get_rankmath_seo' ),

// PROPOSED (6 tools):
'default_tools' => array( 
    'web_search', 'search_content', 'save_post',
    'get_rankmath_seo', 'generate_openai_image',
    'resize_image'
),
```

#### Film Director / Film Producer / Cinematographer / Screenwriter
```php
// CURRENT (3 tools):
'default_tools' => array( 'web_search', 'search_content', 'save_post' ),

// PROPOSED Film Director / Cinematographer (7 tools):
'default_tools' => array( 
    'web_search', 'search_content', 'save_post',
    'generate_sora_video', 'generate_veo_video',
    'analyze_video', 'generate_openai_image'
),

// PROPOSED Screenwriter (5 tools):
'default_tools' => array( 
    'web_search', 'search_content', 'save_post',
    'count_tokens', 'search_attachments'
),

// PROPOSED Film Producer (6 tools):
'default_tools' => array( 
    'web_search', 'search_content', 'save_post',
    'create_chart', 'send_group_email',
    'create_cron_job'
),
```

#### Sound Designer / Production Designer / Architect / UX Designer
```php
// CURRENT (3 tools):
'default_tools' => array( 'web_search', 'search_content', 'save_post' ),

// PROPOSED Sound Designer (6 tools):
'default_tools' => array( 
    'web_search', 'search_content', 'save_post',
    'generate_music', 'transcribe_openai_audio',
    'generate_openai_speech'
),

// PROPOSED Architect / Production Designer / UX Designer (6 tools):
'default_tools' => array( 
    'web_search', 'search_content', 'save_post',
    'generate_openai_image', 'resize_image',
    'search_attachments'
),
```

### Legal Category (2 professions)

#### Lawyer / Legal Advisor
```php
// LINE ~137 & ~170 (approximate)
// CURRENT (3 tools):
'default_tools' => array( 'web_search', 'search_content', 'save_post' ),

// PROPOSED (7 tools):
'default_tools' => array( 
    'web_search', 'search_content', 'save_post',
    'search_attachments', 'analyze_comment_content',
    'count_tokens', 'create_chart'
),
```

### Advisory Category (7 professions)

#### Business Consultant
```php
// LINE ~245 (approximate)
// CURRENT (5 tools - already good):
'default_tools' => array( 'web_search', 'search_content', 'save_post', 
                          'get_woo_products', 'get_woo_recent_orders' ),

// PROPOSED (7 tools):
'default_tools' => array( 
    'web_search', 'search_content', 'save_post',
    'get_woo_products', 'get_woo_recent_orders',
    'create_chart', 'get_site_summary'
),
```

#### Marketing Consultant
```php
// LINE ~300 (approximate)
// CURRENT (5 tools - already good):
'default_tools' => array( 'web_search', 'search_content', 'save_post', 
                          'google_analytics_report', 'post_facebook_instagram' ),

// PROPOSED (7 tools):
'default_tools' => array( 
    'web_search', 'search_content', 'save_post',
    'google_analytics_report', 'post_facebook_instagram',
    'create_chart', 'generate_openai_image'
),
```

#### Real Estate Agent
```php
// LINE ~264 (approximate)
// CURRENT (3 tools):
'default_tools' => array( 'web_search', 'search_content', 'save_post' ),

// PROPOSED (7 tools):
'default_tools' => array( 
    'web_search', 'search_content', 'save_post',
    'search_places', 'geocode_address',
    'generate_openai_image', 'send_group_email'
),
```

#### HR Consultant / Restaurant Consultant / Customs Broker / Import Export Specialist
```php
// CURRENT (3 tools):
'default_tools' => array( 'web_search', 'search_content', 'save_post' ),

// PROPOSED (6 tools):
'default_tools' => array( 
    'web_search', 'search_content', 'save_post',
    'create_chart', 'send_group_email',
    'search_attachments'
),
```

### Other Category (11 professions)

#### Emergency Management Director / Crisis Communications Manager / Hazard Mitigation Specialist
```php
// CURRENT (3-5 tools):
'default_tools' => array( 'web_search', 'search_content', 'save_post' 
                          [, 'send_group_email', 'post_facebook_instagram'] ),

// PROPOSED (7 tools):
'default_tools' => array( 
    'web_search', 'search_content', 'save_post',
    'get_gdacs_events', 'get_nhc_active_storms',
    'reliefweb_reports', 'send_group_email'
),
```

#### Environmental Scientist / Marine Biologist / Oceanographer / Wildlife Conservationist
```php
// CURRENT (3 tools):
'default_tools' => array( 'web_search', 'search_content', 'save_post' ),

// PROPOSED (6 tools):
'default_tools' => array( 
    'web_search', 'search_content', 'save_post',
    'get_open_meteo_forecast', 'create_chart',
    'search_attachments'
),
```

#### Animal Behaviorist / Aquaculture Specialist
```php
// CURRENT (3 tools):
'default_tools' => array( 'web_search', 'search_content', 'save_post' ),

// PROPOSED (5 tools):
'default_tools' => array( 
    'web_search', 'search_content', 'save_post',
    'create_chart', 'search_attachments'
),
```

---

## Validation Tests

Create a new test file: `tests/test-profession-tool-count-limits.php`

```php
<?php
/**
 * Test profession tool count limits.
 *
 * @package WP_MCP_AI
 */

class Test_Profession_Tool_Count_Limits extends WP_UnitTestCase {

    public function test_all_professions_have_minimum_tools() {
        $seeder = new ReflectionClass('WP_MCP_AI_Profession_Seeder');
        $method = $seeder->getMethod('get_default_professions');
        $method->setAccessible(true);
        $professions = $method->invoke(null);

        foreach ($professions as $prof) {
            $tool_count = count($prof['default_tools']);
            $this->assertGreaterThanOrEqual(
                4,
                $tool_count,
                sprintf(
                    'Profession "%s" has only %d tools (minimum 4 required)',
                    $prof['title'],
                    $tool_count
                )
            );
        }
    }

    public function test_no_profession_exceeds_maximum_tools() {
        $seeder = new ReflectionClass('WP_MCP_AI_Profession_Seeder');
        $method = $seeder->getMethod('get_default_professions');
        $method->setAccessible(true);
        $professions = $method->invoke(null);

        foreach ($professions as $prof) {
            $tool_count = count($prof['default_tools']);
            $this->assertLessThanOrEqual(
                8,
                $tool_count,
                sprintf(
                    'Profession "%s" has %d tools (maximum 8 recommended)',
                    $prof['title'],
                    $tool_count
                )
            );
        }
    }

    public function test_optimal_tool_range_distribution() {
        $seeder = new ReflectionClass('WP_MCP_AI_Profession_Seeder');
        $method = $seeder->getMethod('get_default_professions');
        $method->setAccessible(true);
        $professions = $method->invoke(null);

        $optimal_count = 0;
        $total_count = count($professions);

        foreach ($professions as $prof) {
            $tool_count = count($prof['default_tools']);
            if ($tool_count >= 5 && $tool_count <= 7) {
                $optimal_count++;
            }
        }

        $optimal_percentage = ($optimal_count / $total_count) * 100;

        // At least 60% should be in optimal range
        $this->assertGreaterThanOrEqual(
            60,
            $optimal_percentage,
            sprintf(
                'Only %.1f%% of professions are in optimal 5-7 tool range (target: 60%+)',
                $optimal_percentage
            )
        );
    }

    public function test_core_tools_present() {
        $seeder = new ReflectionClass('WP_MCP_AI_Profession_Seeder');
        $method = $seeder->getMethod('get_default_professions');
        $method->setAccessible(true);
        $professions = $method->invoke(null);

        $core_tools = array('web_search', 'search_content');

        foreach ($professions as $prof) {
            $tools = $prof['default_tools'];
            foreach ($core_tools as $core_tool) {
                $this->assertContains(
                    $core_tool,
                    $tools,
                    sprintf(
                        'Profession "%s" is missing core tool "%s"',
                        $prof['title'],
                        $core_tool
                    )
                );
            }
        }
    }
}
```

---

## Rollout Checklist

### Week 1-2: Phase 1 (Critical Fixes)
- [ ] Update Healthcare Advisor (2→6 tools)
- [ ] Update Veterinarian (2→6 tools)
- [ ] Update Bookkeeper (3→6 tools, add save_post)
- [ ] Run tests to verify minimum 4 tools
- [ ] Deploy to staging
- [ ] User acceptance testing

### Week 3-4: Phase 2 (Category Enhancements)
- [ ] Update all Financial professions (4 total)
- [ ] Update all Healthcare professions (15 total)
- [ ] Update all Technical professions (15 total)
- [ ] Run tests to verify optimal range
- [ ] Deploy to staging
- [ ] Performance testing

### Week 5-6: Phase 3 (Specialty Tools)
- [ ] Update Creative professions (16 total)
- [ ] Update Legal professions (2 total)
- [ ] Update Advisory professions (7 total)
- [ ] Update Other category (11 total)
- [ ] Run full test suite
- [ ] Deploy to staging

### Week 7-8: Phase 4 (QA & Production)
- [ ] Comprehensive testing all 70 professions
- [ ] Verify tool count distribution meets targets
- [ ] Update documentation
- [ ] Production deployment
- [ ] Monitor usage analytics
- [ ] Collect user feedback

---

## Success Metrics

After implementation, verify:

1. **Tool Count Distribution**:
   - ✅ 0% professions with <4 tools
   - ✅ 75%+ professions with 5-7 tools
   - ✅ 0% professions with >8 tools

2. **Category Coverage**:
   - ✅ Financial: All have create_chart and get_quickbooks_report
   - ✅ Creative: Image roles have manipulation tools, video roles have video tools
   - ✅ Technical: All have relevant system/security tools
   - ✅ Healthcare: All have research and health-specific tools
   - ✅ Legal: All have document discovery tools

3. **Core Tools**:
   - ✅ 100% have web_search
   - ✅ 100% have search_content
   - ✅ 95%+ have save_post (exceptions allowed for specialized read-only roles)

---

## Notes for Developers

1. **Preserve Existing Good Configurations**: Professions already in 4-8 range may need minor adjustments, not major overhauls

2. **Tool Availability**: All recommended tools exist in the codebase. No new tool development required.

3. **Backwards Compatibility**: Existing assistants will not be affected. This only changes defaults for NEW assistant creation.

4. **User Customization**: Users can still add/remove tools via the UI (search, filter, bulk actions already implemented).

5. **Testing**: Run `composer run test` after each phase to catch issues early.

---

## Contact for Questions

For implementation questions or clarifications, refer to:
- Research document: `/tmp/PROFESSION_TOOL_RESEARCH_COMPREHENSIVE.md`
- Tool recommender service: `includes/services/class-wp-mcp-ai-profession-tool-recommender.php`
- Profession seeder: `includes/professions/class-wp-mcp-ai-profession-seeder.php`
- Expertise metabox (UI): `includes/professions/metaboxes/class-wp-mcp-ai-profession-metabox-expertise.php`
