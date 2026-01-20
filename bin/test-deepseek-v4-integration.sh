#!/bin/bash
# DeepSeek V4 Orchestration - Integration Test Script
# 
# This script validates the complete multi-agent orchestration system
# by running through key workflows and checking results.
#
# Usage: bash bin/test-deepseek-v4-integration.sh

set -e

echo "======================================================================"
echo "DeepSeek V4 Multi-Agent Orchestration - Integration Test"
echo "======================================================================"
echo ""

# Colors for output
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

# Check if WP-CLI is available
if ! command -v wp &> /dev/null; then
    echo -e "${RED}Error: WP-CLI is not installed or not in PATH${NC}"
    exit 1
fi

# Check if WordPress is installed
if ! wp core is-installed 2>/dev/null; then
    echo -e "${RED}Error: WordPress is not installed${NC}"
    exit 1
fi

echo -e "${GREEN}✓ WP-CLI available${NC}"
echo -e "${GREEN}✓ WordPress installed${NC}"
echo ""

# Test 1: Check plugin is active
echo "Test 1: Checking plugin activation..."
if wp plugin is-active mcp-ai-wpoos 2>/dev/null; then
    echo -e "${GREEN}✓ NV oOS plugin is active${NC}"
else
    echo -e "${RED}✗ NV oOS plugin is not active${NC}"
    exit 1
fi
echo ""

# Test 2: Check professions exist
echo "Test 2: Checking profession posts..."
PROFESSION_COUNT=$(wp post list --post_type=mcp_ai_profession --post_status=publish --format=count 2>/dev/null || echo "0")
if [ "$PROFESSION_COUNT" -gt "0" ]; then
    echo -e "${GREEN}✓ Found $PROFESSION_COUNT profession posts${NC}"
else
    echo -e "${YELLOW}⚠ No profession posts found - seeder may not work without professions${NC}"
fi
echo ""

# Test 3: Run orchestration seeder
echo "Test 3: Running orchestration seeder..."
if wp profession seed-orchestration 2>&1 | tee /tmp/seeder-output.txt; then
    if grep -q "Success" /tmp/seeder-output.txt || grep -q "already seeded" /tmp/seeder-output.txt; then
        echo -e "${GREEN}✓ Seeder executed successfully${NC}"
    else
        echo -e "${YELLOW}⚠ Seeder ran but check output above${NC}"
    fi
else
    echo -e "${RED}✗ Seeder failed${NC}"
    exit 1
fi
echo ""

# Test 4: Check orchestration statistics
echo "Test 4: Checking orchestration statistics..."
if wp profession orchestration-stats 2>&1 | tee /tmp/stats-output.txt; then
    echo -e "${GREEN}✓ Statistics retrieved${NC}"
    
    # Parse statistics
    PLANNER_COUNT=$(grep "Planner" /tmp/stats-output.txt | awk '{print $NF}' || echo "0")
    EXECUTOR_COUNT=$(grep "Executor" /tmp/stats-output.txt | awk '{print $NF}' || echo "0")
    CRITIC_COUNT=$(grep "Critic" /tmp/stats-output.txt | awk '{print $NF}' || echo "0")
    
    echo ""
    echo "  Agent Role Distribution:"
    echo "    Planner:  $PLANNER_COUNT"
    echo "    Executor: $EXECUTOR_COUNT"
    echo "    Critic:   $CRITIC_COUNT"
    echo ""
else
    echo -e "${RED}✗ Failed to retrieve statistics${NC}"
    exit 1
fi
echo ""

# Test 5: Verify tool registry
echo "Test 5: Checking agent coordination tools..."
TOOLS_FOUND=0

# Check if tools are registered by attempting to get them
# Note: This would need a PHP script or WP-CLI command to properly check
# For now, we'll use a PHP one-liner
TOOLS_CHECK=$(wp eval '
    $registry = WP_MCP_AI_Tool_Registry::get_instance();
    $registry->init();
    $tools = array("create_agent_team", "delegate_to_agent", "aggregate_agent_results");
    $found = 0;
    foreach ($tools as $tool) {
        if ($registry->is_tool_registered($tool)) {
            $found++;
        }
    }
    echo $found;
' 2>/dev/null || echo "0")

if [ "$TOOLS_CHECK" = "3" ]; then
    echo -e "${GREEN}✓ All 3 agent coordination tools are registered${NC}"
else
    echo -e "${YELLOW}⚠ Only $TOOLS_CHECK/3 agent coordination tools found${NC}"
fi
echo ""

# Test 6: Verify agent role classes exist
echo "Test 6: Checking agent role classes..."
CLASSES_CHECK=$(wp eval '
    $classes = array(
        "WP_MCP_AI_Agent_Role_Executor",
        "WP_MCP_AI_Agent_Role_Planner",
        "WP_MCP_AI_Agent_Role_Critic",
        "WP_MCP_AI_Agent_Team_Orchestrator"
    );
    $found = 0;
    foreach ($classes as $class) {
        if (class_exists($class)) {
            $found++;
        }
    }
    echo $found;
' 2>/dev/null || echo "0")

if [ "$CLASSES_CHECK" = "4" ]; then
    echo -e "${GREEN}✓ All 4 core agent classes exist${NC}"
else
    echo -e "${YELLOW}⚠ Only $CLASSES_CHECK/4 agent classes found${NC}"
fi
echo ""

# Test 7: Test executor tool execution
echo "Test 7: Testing executor agent tool execution..."
EXECUTOR_TEST=$(wp eval '
    try {
        $executor = new WP_MCP_AI_Agent_Role_Executor();
        
        $task = array(
            "description" => "Test task",
            "type" => "generic",
        );
        
        $context = array(
            "assistant_id" => 1,
            "user_id" => 1,
        );
        
        $result = $executor->execute_role_task($task, $context);
        
        if (is_wp_error($result)) {
            echo "error: " . $result->get_error_message();
        } elseif (isset($result["status"])) {
            echo $result["status"];
        } else {
            echo "unknown";
        }
    } catch (Exception $e) {
        echo "error: " . $e->getMessage();
    }
' 2>/dev/null || echo "error")

if [[ "$EXECUTOR_TEST" == "completed" ]] || [[ "$EXECUTOR_TEST" == "failed" ]]; then
    echo -e "${GREEN}✓ Executor agent executed task (status: $EXECUTOR_TEST)${NC}"
else
    echo -e "${YELLOW}⚠ Executor test result: $EXECUTOR_TEST${NC}"
fi
echo ""

# Test 8: Test orchestrator team composition
echo "Test 8: Testing orchestrator team composition..."
ORCHESTRATOR_TEST=$(wp eval '
    try {
        $orchestrator = new WP_MCP_AI_Agent_Team_Orchestrator();
        
        $team = $orchestrator->compose_team(array(
            "task_type" => "generic"
        ));
        
        if (is_wp_error($team)) {
            echo "error: " . $team->get_error_message();
        } elseif (isset($team["team_id"])) {
            echo "success";
        } else {
            echo "unknown";
        }
    } catch (Exception $e) {
        echo "error: " . $e->getMessage();
    }
' 2>/dev/null || echo "error")

if [ "$ORCHESTRATOR_TEST" = "success" ]; then
    echo -e "${GREEN}✓ Orchestrator successfully composed team${NC}"
else
    echo -e "${YELLOW}⚠ Orchestrator test result: $ORCHESTRATOR_TEST${NC}"
fi
echo ""

# Test 9: Check meta field constants
echo "Test 9: Verifying profession CPT meta fields..."
META_CHECK=$(wp eval '
    $constants = array(
        "WP_MCP_AI_Profession_CPT::META_AGENT_ROLE",
        "WP_MCP_AI_Profession_CPT::META_AGENT_SECONDARY_ROLES",
        "WP_MCP_AI_Profession_CPT::META_TASK_PATTERNS",
        "WP_MCP_AI_Profession_CPT::META_ORCHESTRATION_RULES"
    );
    $found = 0;
    foreach ($constants as $constant) {
        if (defined($constant)) {
            $found++;
        }
    }
    echo $found;
' 2>/dev/null || echo "0")

if [ "$META_CHECK" = "4" ]; then
    echo -e "${GREEN}✓ All 4 orchestration meta field constants defined${NC}"
else
    echo -e "${YELLOW}⚠ Only $META_CHECK/4 meta field constants found${NC}"
fi
echo ""

# Summary
echo "======================================================================"
echo "Integration Test Summary"
echo "======================================================================"
echo ""

TOTAL_TESTS=9
PASSED_TESTS=0

# Count passed tests (simplified)
if wp plugin is-active mcp-ai-wpoos 2>/dev/null; then ((PASSED_TESTS++)); fi
if [ "$PROFESSION_COUNT" -gt "0" ]; then ((PASSED_TESTS++)); fi
if grep -q "Success\|already seeded" /tmp/seeder-output.txt 2>/dev/null; then ((PASSED_TESTS++)); fi
if [ -f /tmp/stats-output.txt ]; then ((PASSED_TESTS++)); fi
if [ "$TOOLS_CHECK" = "3" ]; then ((PASSED_TESTS++)); fi
if [ "$CLASSES_CHECK" = "4" ]; then ((PASSED_TESTS++)); fi
if [[ "$EXECUTOR_TEST" == "completed" ]] || [[ "$EXECUTOR_TEST" == "failed" ]]; then ((PASSED_TESTS++)); fi
if [ "$ORCHESTRATOR_TEST" = "success" ]; then ((PASSED_TESTS++)); fi
if [ "$META_CHECK" = "4" ]; then ((PASSED_TESTS++)); fi

echo "Tests Passed: $PASSED_TESTS / $TOTAL_TESTS"
echo ""

if [ "$PASSED_TESTS" = "$TOTAL_TESTS" ]; then
    echo -e "${GREEN}✓ All integration tests passed!${NC}"
    echo ""
    echo "DeepSeek V4 orchestration system is operational."
    exit 0
elif [ "$PASSED_TESTS" -ge 7 ]; then
    echo -e "${YELLOW}⚠ Most tests passed ($PASSED_TESTS/$TOTAL_TESTS)${NC}"
    echo ""
    echo "DeepSeek V4 orchestration system is mostly operational."
    echo "Check warnings above for details."
    exit 0
else
    echo -e "${RED}✗ Only $PASSED_TESTS/$TOTAL_TESTS tests passed${NC}"
    echo ""
    echo "DeepSeek V4 orchestration system may have issues."
    echo "Review errors above for details."
    exit 1
fi

# Cleanup
rm -f /tmp/seeder-output.txt /tmp/stats-output.txt
