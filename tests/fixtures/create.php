<?php
/**
 * Fixture Factory — creates prerequisite data for dependent-tool tests.
 *
 * Creates: 1 project, 1 lead, 1 deal (linked to lead), 1 task (linked to
 * project), 1 event, 1 sprint (linked to project), 1 CRM activity, 1 task
 * template, 1 outreach sequence. Provides known IDs for all dependent-tool
 * tests.
 *
 * Usage:
 *   studio wp --user=admin eval-file tests/fixtures/create.php
 *
 * @package WP_MCP_AI
 * @since  1.1.34
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	// Gracefully skip when run under PHPUnit or direct web access.
	if ( defined( 'PHPUNIT_COMPOSER_INSTALL' ) || defined( 'WP_TESTS_DOMAIN' ) ) {
		return;
	}
	fwrite( STDERR, "This script must be run via WP-CLI.\n" );
	exit( 1 );
}

$user_id = get_current_user_id();
$fixtures = array();
$errors   = array();

WP_CLI::log( '=== Creating test fixtures ===' );

// ---------------------------------------------------------------------------
// 1. Create a project (PM toolkit).
// ---------------------------------------------------------------------------
WP_CLI::log( 'Creating project...' );
if ( class_exists( 'WP_MCP_AI_Tool_Create_Project' ) ) {
	$tool = new WP_MCP_AI_Tool_Create_Project();
	if ( method_exists( $tool, 'is_available' ) && $tool->is_available() ) {
		$result = $tool->execute(
			array(
				'name'        => 'CI Smoke Test Project',
				'description' => 'Auto-created by test fixture factory.',
			),
			array( 'user_id' => $user_id )
		);
		if ( is_wp_error( $result ) ) {
			$errors[] = 'create_project: ' . $result->get_error_message();
		} else {
			$fixtures['project_id'] = $result['project_id'] ?? $result['id'] ?? 0;
			WP_CLI::log( sprintf( '  Created project #%d', $fixtures['project_id'] ) );
		}
	} else {
		WP_CLI::log( '  Skipped: Project Management toolkit not available.' );
	}
} else {
	$errors[] = 'create_project: class not found.';
}

// ---------------------------------------------------------------------------
// 2. Create a lead (CRM toolkit).
// ---------------------------------------------------------------------------
WP_CLI::log( 'Creating lead...' );
if ( class_exists( 'WP_MCP_AI_Tool_Create_Lead' ) ) {
	$tool = new WP_MCP_AI_Tool_Create_Lead();
	if ( method_exists( $tool, 'is_available' ) && $tool->is_available() ) {
		$result = $tool->execute(
			array(
				'first_name' => 'CI',
				'last_name'  => 'TestLead',
				'email'      => 'ci-test-lead@example.com',
				'company'    => 'CI Test Corp',
			),
			array( 'user_id' => $user_id )
		);
		if ( is_wp_error( $result ) ) {
			$errors[] = 'create_lead: ' . $result->get_error_message();
		} else {
			$fixtures['lead_id'] = $result['lead_id'] ?? $result['id'] ?? 0;
			WP_CLI::log( sprintf( '  Created lead #%d', $fixtures['lead_id'] ) );
		}
	} else {
		WP_CLI::log( '  Skipped: CRM toolkit not available.' );
	}
}

// ---------------------------------------------------------------------------
// 3. Create a deal (linked to lead).
// ---------------------------------------------------------------------------
if ( ! empty( $fixtures['lead_id'] ) && class_exists( 'WP_MCP_AI_Tool_Create_Deal' ) ) {
	WP_CLI::log( 'Creating deal...' );
	$tool = new WP_MCP_AI_Tool_Create_Deal();
	if ( method_exists( $tool, 'is_available' ) && $tool->is_available() ) {
		$result = $tool->execute(
			array(
				'lead_id' => $fixtures['lead_id'],
				'name'    => 'CI Test Deal',
				'amount'  => 5000,
				'stage'   => 'proposal',
			),
			array( 'user_id' => $user_id )
		);
		if ( is_wp_error( $result ) ) {
			$errors[] = 'create_deal: ' . $result->get_error_message();
		} else {
			$fixtures['deal_id'] = $result['deal_id'] ?? $result['id'] ?? 0;
			WP_CLI::log( sprintf( '  Created deal #%d', $fixtures['deal_id'] ) );
		}
	}
}

// ---------------------------------------------------------------------------
// 4. Create a task (linked to project).
// ---------------------------------------------------------------------------
if ( ! empty( $fixtures['project_id'] ) && class_exists( 'WP_MCP_AI_Tool_Create_Task' ) ) {
	WP_CLI::log( 'Creating task...' );
	$tool = new WP_MCP_AI_Tool_Create_Task();
	if ( method_exists( $tool, 'is_available' ) && $tool->is_available() ) {
		$result = $tool->execute(
			array(
				'title'      => 'CI Smoke Test Task',
				'project_id' => $fixtures['project_id'],
				'status'     => 'todo',
			),
			array( 'user_id' => $user_id )
		);
		if ( is_wp_error( $result ) ) {
			$errors[] = 'create_task: ' . $result->get_error_message();
		} else {
			$fixtures['task_id'] = $result['task_id'] ?? $result['id'] ?? 0;
			WP_CLI::log( sprintf( '  Created task #%d', $fixtures['task_id'] ) );
		}
	}
}

// ---------------------------------------------------------------------------
// 5. Create an event.
// ---------------------------------------------------------------------------
if ( class_exists( 'WP_MCP_AI_Tool_Create_Event' ) ) {
	WP_CLI::log( 'Creating event...' );
	$tool  = new WP_MCP_AI_Tool_Create_Event();
	$start = gmdate( 'Y-m-d\TH:i:s', strtotime( '+1 day' ) );
	$end   = gmdate( 'Y-m-d\TH:i:s', strtotime( '+1 day +1 hour' ) );
	if ( method_exists( $tool, 'is_available' ) && $tool->is_available() ) {
		$result = $tool->execute(
			array(
				'title'      => 'CI Smoke Test Event',
				'start_date' => $start,
				'end_date'   => $end,
			),
			array( 'user_id' => $user_id )
		);
		if ( is_wp_error( $result ) ) {
			$errors[] = 'create_event: ' . $result->get_error_message();
		} else {
			$fixtures['event_id'] = $result['event_id'] ?? $result['id'] ?? 0;
			WP_CLI::log( sprintf( '  Created event #%d', $fixtures['event_id'] ) );
		}
	}
}

// ---------------------------------------------------------------------------
// 6. Create a sprint (linked to project).
// ---------------------------------------------------------------------------
if ( ! empty( $fixtures['project_id'] ) && class_exists( 'WP_MCP_AI_Tool_Plan_Sprint' ) ) {
	WP_CLI::log( 'Creating sprint...' );
	$tool = new WP_MCP_AI_Tool_Plan_Sprint();
	if ( method_exists( $tool, 'is_available' ) && $tool->is_available() ) {
		$result = $tool->execute(
			array(
				'project_id' => $fixtures['project_id'],
				'name'       => 'CI Smoke Test Sprint',
				'duration'   => 14,
			),
			array( 'user_id' => $user_id )
		);
		if ( is_wp_error( $result ) ) {
			$errors[] = 'plan_sprint: ' . $result->get_error_message();
		} else {
			$fixtures['sprint_id'] = $result['sprint_id'] ?? $result['id'] ?? 0;
			WP_CLI::log( sprintf( '  Created sprint #%d', $fixtures['sprint_id'] ) );
		}
	}
}

// ---------------------------------------------------------------------------
// 7. Create a task template.
// ---------------------------------------------------------------------------
if ( class_exists( 'WP_MCP_AI_Tool_Create_Task_Template' ) ) {
	WP_CLI::log( 'Creating task template...' );
	$tool = new WP_MCP_AI_Tool_Create_Task_Template();
	if ( method_exists( $tool, 'is_available' ) && $tool->is_available() ) {
		$result = $tool->execute(
			array( 'title' => 'CI Smoke Test Template' ),
			array( 'user_id' => $user_id )
		);
		if ( is_wp_error( $result ) ) {
			$errors[] = 'create_task_template: ' . $result->get_error_message();
		} else {
			$fixtures['template_id'] = $result['template_id'] ?? $result['id'] ?? 0;
			WP_CLI::log( sprintf( '  Created task template #%d', $fixtures['template_id'] ) );
		}
	}
}

// ---------------------------------------------------------------------------
// 8. Create an outreach sequence.
// ---------------------------------------------------------------------------
if ( class_exists( 'WP_MCP_AI_Tool_Create_Outreach_Sequence' ) ) {
	WP_CLI::log( 'Creating outreach sequence...' );
	$tool = new WP_MCP_AI_Tool_Create_Outreach_Sequence();
	if ( method_exists( $tool, 'is_available' ) && $tool->is_available() ) {
		$result = $tool->execute(
			array(
				'name'  => 'CI Smoke Test Sequence',
				'steps' => array(
					array( 'type' => 'email', 'delay' => 1 ),
					array( 'type' => 'call', 'delay' => 3 ),
				),
			),
			array( 'user_id' => $user_id )
		);
		if ( is_wp_error( $result ) ) {
			$errors[] = 'create_outreach_sequence: ' . $result->get_error_message();
		} else {
			$fixtures['sequence_id'] = $result['sequence_id'] ?? $result['id'] ?? 0;
			WP_CLI::log( sprintf( '  Created sequence #%d', $fixtures['sequence_id'] ) );
		}
	}
}

// ---------------------------------------------------------------------------
// Report.
// ---------------------------------------------------------------------------
WP_CLI::log( '' );
WP_CLI::log( '========================================' );
WP_CLI::log( '         FIXTURE CREATION RESULTS' );
WP_CLI::log( '========================================' );
foreach ( $fixtures as $key => $id ) {
	WP_CLI::log( sprintf( '  %s: %d', $key, $id ) );
}

if ( ! empty( $errors ) ) {
	WP_CLI::log( '' );
	WP_CLI::warning( 'Some fixtures could not be created:' );
	foreach ( $errors as $err ) {
		WP_CLI::log( '  ' . $err );
	}
}

// Persist fixture IDs to a JSON file so other scripts can read them.
$fixture_file = WP_CONTENT_DIR . '/uploads/wp-mcp-ai-fixtures.json';
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
file_put_contents( $fixture_file, wp_json_encode( $fixtures, JSON_PRETTY_PRINT ) );
WP_CLI::log( '' );
WP_CLI::log( sprintf( 'Fixture IDs written to %s', $fixture_file ) );
