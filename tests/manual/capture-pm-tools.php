<?php
/**
 * Regenerates tests/fixtures/pm/tools.json from the WordPress PM tools.
 *
 * Run inside the WordPress container:
 *
 *   docker exec -w /var/www/html/wp-content/plugins/mcp-ai-wpoos oos-wp \
 *     php tests/manual/capture-pm-tools.php
 *
 * 090-E step 6: a family ships with read tools, write tools **and** parity
 * fixtures. The corpus records the WordPress response for a fixed seeded state
 * so the Laravel port of the same tools can be compared against it.
 *
 * Ids and dates differ between the two runtimes by construction, so both are
 * normalised to placeholders; every other field is compared verbatim.
 *
 * Regenerating is a deliberate act: it asserts "this behaviour is correct", not
 * "the test is green again".
 *
 * @package WP_MCP_AI\Tests
 * @since   2.6.0
 * @license GPL-3.0-or-later
 */

// phpcs:disable WordPress.WP.GlobalVariablesProhibited, WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.Security.EscapeOutput.OutputNotEscaped -- Standalone runner.

if ( ! defined( 'ABSPATH' ) ) {
	$wp_root = __DIR__;

	while ( ! is_file( $wp_root . '/wp-load.php' ) && dirname( $wp_root ) !== $wp_root ) {
		$wp_root = dirname( $wp_root );
	}

	if ( ! is_file( $wp_root . '/wp-load.php' ) ) {
		fwrite( STDERR, 'Could not locate wp-load.php above ' . __DIR__ . "\n" );
		exit( 1 );
	}

	require $wp_root . '/wp-load.php';
}

$pm_dir = WP_MCP_AI_PRO_PATH . 'includes/tools/project-management/';

foreach ( array(
	'class-wp-mcp-ai-tool-list-projects.php',
	'class-wp-mcp-ai-tool-list-tasks.php',
	'class-wp-mcp-ai-tool-get-task-dependencies.php',
	'class-wp-mcp-ai-tool-create-project.php',
	'class-wp-mcp-ai-tool-update-project.php',
	'class-wp-mcp-ai-tool-delete-project.php',
	'class-wp-mcp-ai-tool-create-task.php',
	'class-wp-mcp-ai-tool-update-task.php',
	'class-wp-mcp-ai-tool-delete-task.php',
) as $file ) {
	require_once $pm_dir . $file;
}

/**
 * Replace ids and dates so the corpus is runtime-neutral.
 *
 * @param mixed $value Response fragment.
 * @return mixed
 */
$normalise = static function ( $value ) use ( &$normalise ) {
	if ( ! is_array( $value ) ) {
		return $value;
	}

	$out = array();

	foreach ( $value as $key => $item ) {
		// User ids differ between runtimes for the same reason record ids do.
		// Checked before the array recursion so `assigned_to` lists are handled
		// as user ids rather than as indexed values.
		if ( 'assigned_to' === $key ) {
			$out[ $key ] = is_array( $item )
				? array_map( static fn ( $v ) => is_numeric( $v ) ? '<id>' : $v, $item )
				: ( is_numeric( $item ) ? '<id>' : $item );
			continue;
		}

		if ( is_array( $item ) ) {
			$out[ $key ] = $normalise( $item );
			continue;
		}

		if ( 'id' === $key || 'project_id' === $key || 'task_id' === $key || 'depends_on' === $key ) {
			$out[ $key ] = is_numeric( $item ) ? '<id>' : $item;
			continue;
		}

		if ( is_string( $key ) && ( str_contains( $key, 'date' ) || 'created' === $key || 'modified' === $key || 'created_at' === $key || 'updated_at' === $key ) && '' !== (string) $item ) {
			$out[ $key ] = '<date>';
			continue;
		}

		$out[ $key ] = $item;
	}

	return $out;
};

$settings_snapshot                     = get_option( 'wp_mcp_ai_settings', array() );
$settings                              = is_array( $settings_snapshot ) ? $settings_snapshot : array();
$settings['enable_project_management'] = true;
update_option( 'wp_mcp_ai_settings', $settings );

$admin = get_users(
	array(
		'role'    => 'administrator',
		'number'  => 1,
		'orderby' => 'ID',
	)
);

if ( empty( $admin ) ) {
	fwrite( STDERR, "No administrator user found.\n" );
	exit( 1 );
}

$user_id = (int) $admin[0]->ID;
wp_set_current_user( $user_id );

/**
 * Create one project row.
 *
 * @param string $title Project title.
 * @param array  $meta  Meta values.
 * @param string $body  Post content (the tool reports it as `description`).
 */
$make_project = static function ( string $title, array $meta, string $body = '', string $date = '' ): int {
	$args = array(
		'post_type'    => 'mcp_ai_project',
		'post_title'   => $title,
		'post_status'  => 'publish',
		'post_content' => $body,
		'meta_input'   => $meta,
	);

	if ( '' !== $date ) {
		$args['post_date']     = $date;
		$args['post_date_gmt'] = $date;
	}

	return (int) wp_insert_post( $args );
};

/**
 * Create one task row.
 *
 * @param string $title Project title.
 * @param array  $meta  Meta values.
 * @param string $body  Post content (the tool reports it as `description`).
 */
$make_task = static function ( string $title, array $meta, string $body = '', string $date = '' ): int {
	$args = array(
		'post_type'    => 'mcp_ai_task',
		'post_title'   => $title,
		'post_status'  => 'publish',
		'post_content' => $body,
		'meta_input'   => $meta,
	);

	if ( '' !== $date ) {
		$args['post_date']     = $date;
		$args['post_date_gmt'] = $date;
	}

	return (int) wp_insert_post( $args );
};

$seeded = array();

$seeded[] = $make_project(
	'Atlas Migration',
	array(
		'_project_status'      => 'active',
		'_project_start_date'  => '2026-01-05',
		'_project_end_date'    => '2026-03-31',
		'_project_assigned_to' => array( (string) $user_id ),
	),
	'Move the estate to the new platform.',
	'2026-01-03 09:00:00'
);

$seeded[] = $make_project(
	'Beacon Audit',
	array(
		'_project_status'      => 'planning',
		'_project_start_date'  => '2026-02-01',
		'_project_end_date'    => '2026-04-30',
		'_project_assigned_to' => array(),
	),
	'Accessibility and performance review.',
	'2026-01-02 09:00:00'
);

$seeded[] = $make_project(
	'Cinder Rebuild',
	array(
		'_project_status'      => 'completed',
		'_project_start_date'  => '2025-10-01',
		'_project_end_date'    => '2025-12-20',
		'_project_assigned_to' => array(),
	),
	'Rebuild the storefront.',
	'2026-01-01 09:00:00'
);

$task_one = $make_task(
	'Draft the migration plan',
	array(
		'_task_project_id'  => $seeded[0],
		'_task_status'      => 'in-progress',
		'_task_priority'    => 'high',
		'_task_due_date'    => '2026-01-20',
		'_task_assigned_to' => $user_id,
	),
	'Write the cutover plan.',
	'2026-01-06 09:00:00'
);

$task_two = $make_task(
	'Freeze plugin updates',
	array(
		'_task_project_id'  => $seeded[0],
		'_task_status'      => 'pending',
		'_task_priority'    => 'medium',
		'_task_due_date'    => '2026-01-25',
		'_task_assigned_to' => 0,
	),
	'Announce and freeze.',
	'2026-01-05 09:00:00'
);

$task_three = $make_task(
	'Run the contrast sweep',
	array(
		'_task_project_id'  => $seeded[1],
		'_task_status'      => 'completed',
		'_task_priority'    => 'low',
		'_task_due_date'    => '2026-02-10',
		'_task_assigned_to' => 0,
	),
	'WCAG AA across the top 20 pages.',
	'2026-01-04 09:00:00'
);

$seeded[] = $task_one;
$seeded[] = $task_two;
$seeded[] = $task_three;

// Dependency: the freeze waits on the plan.
$task_one_meta = get_post_meta( $task_two, '_task_depends_on', true );
$depends       = is_array( $task_one_meta ) ? $task_one_meta : array();
$depends[]     = $task_one;
update_post_meta( $task_two, '_task_depends_on', $depends );

$cases = array(
	'list-projects'            => array( 'list_projects', array() ),
	'list-projects-limit'      => array( 'list_projects', array( 'limit' => 2 ) ),
	'list-projects-status'     => array( 'list_projects', array( 'status' => 'active' ) ),
	'list-tasks'               => array( 'list_tasks', array() ),
	'list-tasks-by-project'    => array( 'list_tasks', array( 'project_id' => '<project:atlas>' ) ),
	'task-dependencies'        => array( 'get_task_dependencies', array( 'task_id' => '<task:freeze>' ) ),
	'task-dependencies-nested' => array( 'get_task_dependencies', array( 'task_id' => '<task:plan>' ) ),
);

$tool_map = array(
	'list_projects'         => new WP_MCP_AI_Tool_List_Projects(),
	'list_tasks'            => new WP_MCP_AI_Tool_List_Tasks(),
	'get_task_dependencies' => new WP_MCP_AI_Tool_Get_Task_Dependencies(),
);

$out = array();

// Arguments are recorded symbolically so both runtimes can resolve them against
// their own seeded ids.
$symbols = array(
	'<project:atlas>' => $seeded[0],
	'<task:plan>'     => $task_one,
	'<task:freeze>'   => $task_two,
);

foreach ( $cases as $name => $case ) {
	$runtime_args = $case[1];

	foreach ( $runtime_args as $key => $value ) {
		if ( is_string( $value ) && isset( $symbols[ $value ] ) ) {
			$runtime_args[ $key ] = $symbols[ $value ];
		}
	}

	$result = $tool_map[ $case[0] ]->execute( $runtime_args );

	$out[ $name ] = array(
		'tool'     => $case[0],
		'args'     => $case[1],
		'expected' => $normalise( $result ),
	);
}

foreach ( $seeded as $post_id ) {
	wp_delete_post( $post_id, true );
}

update_option( 'wp_mcp_ai_settings', $settings_snapshot );

$target = WP_MCP_AI_PATH . 'tests/fixtures/pm/tools.json';

if ( ! is_dir( dirname( $target ) ) ) {
	mkdir( dirname( $target ), 0777, true );
}

$json = json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

file_put_contents( $target, $json . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture writer.

printf( "Wrote %d cases to %s\n", count( $out ), $target );
