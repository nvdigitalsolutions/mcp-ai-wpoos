<?php
/**
 * Regenerates tests/fixtures/pm/writes.json from the WordPress PM write tools.
 *
 * Run inside the WordPress container:
 *
 *   docker exec -w /var/www/html/wp-content/plugins/mcp-ai-wpoos oos-wp \
 *     php tests/manual/capture-pm-writes.php
 *
 * 090-E step 6, write half. The three create tools are upserts, so the corpus
 * exercises both branches (`updated: false` and `updated: true`) before the
 * update and delete tools.
 *
 * Both upsert cases pass **every** field. The WordPress upsert echoes the
 * arguments it was given, so a partial upsert reports empty strings for the
 * fields it left alone while the row still holds the seeded values; the port
 * returns the stored row. That difference is deliberate (the port's answer is
 * the true one) and is recorded in 090-e §9 rather than reproduced here.
 *
 * Ids, user ids and dates are normalised; everything else is compared
 * verbatim. The core ports return the full row from `update_*`, which the
 * WordPress tools do not, so the replay asserts "every recorded field matches,
 * and the only extra keys are the documented ones" rather than equality.
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
 * Replace ids, user ids and dates so the corpus is runtime-neutral.
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
		// Checked before the array recursion so lists are handled as ids.
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

		if ( 'id' === $key || 'project_id' === $key || 'task_id' === $key ) {
			$out[ $key ] = is_numeric( $item ) ? '<id>' : $item;
			continue;
		}

		if ( is_string( $key ) && ( str_contains( $key, 'date' ) || str_contains( $key, '_at' ) ) && '' !== (string) $item ) {
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

wp_set_current_user( (int) $admin[0]->ID );

$make_project = static function ( string $title, array $meta ) : int {
	return (int) wp_insert_post(
		array(
			'post_type'    => 'mcp_ai_project',
			'post_title'   => $title,
			'post_status'  => 'publish',
			'post_content' => 'Seeded for the write corpus.',
			'meta_input'   => $meta,
		)
	);
};

$make_task = static function ( string $title, array $meta ) : int {
	return (int) wp_insert_post(
		array(
			'post_type'    => 'mcp_ai_task',
			'post_title'   => $title,
			'post_status'  => 'publish',
			'post_content' => 'Seeded for the write corpus.',
			'meta_input'   => $meta,
		)
	);
};

$seeded = array();

$atlas = $make_project(
	'Atlas Migration',
	array(
		'_project_status'     => 'active',
		'_project_start_date' => '2026-01-05',
		'_project_end_date'   => '2026-03-31',
	)
);

$beacon = $make_project(
	'Beacon Audit',
	array(
		'_project_status'     => 'planning',
		'_project_start_date' => '2026-02-01',
		'_project_end_date'   => '2026-04-30',
	)
);

$cinder = $make_project(
	'Cinder Rebuild',
	array(
		'_project_status'     => 'completed',
		'_project_start_date' => '2025-10-01',
		'_project_end_date'   => '2025-12-20',
	)
);

$plan = $make_task(
	'Draft the migration plan',
	array(
		'_task_project_id' => $atlas,
		'_task_status'     => 'in-progress',
		'_task_priority'   => 'high',
		'_task_due_date'   => '2026-01-20',
	)
);

$contrast = $make_task(
	'Run the contrast sweep',
	array(
		'_task_project_id' => $beacon,
		'_task_status'     => 'pending',
		'_task_priority'   => 'low',
		'_task_due_date'   => '2026-02-10',
	)
);

$seeded = array_merge( $seeded, array( $atlas, $beacon, $cinder, $plan, $contrast ) );

// Symbolic ids so both runtimes can resolve their own. `<user>` is the same
// idea for the caller: user 1 in this database is not user 1 in a fresh test
// database, so the corpus names the caller rather than numbering them.
$symbols = array(
	'<project:atlas>'  => $atlas,
	'<project:beacon>' => $beacon,
	'<project:cinder>' => $cinder,
	'<task:plan>'      => $plan,
	'<task:contrast>'  => $contrast,
	'<user>'           => $admin[0]->ID,
);

$cases = array(
	'create-project'        => array(
		'create_project',
		array(
			'name'        => 'Delta Onboarding',
			'description' => 'Stand up the new starter flow.',
			'status'      => 'planning',
			'start_date'  => '2026-05-01',
			'end_date'    => '2026-06-30',
			'assigned_to' => array( '<user>' ),
		),
	),
	'create-project-upsert' => array(
		'create_project',
		array(
			'project_id'  => '<project:beacon>',
			'name'        => 'Beacon Audit (revised)',
			'description' => 'Scope widened to the checkout flow.',
			'status'      => 'active',
			'start_date'  => '2025-12-01',
			'end_date'    => '2026-03-15',
			'assigned_to' => array( '<user>' ),
		),
	),
	'update-project'        => array(
		'update_project',
		array(
			'project_id'  => '<project:atlas>',
			'name'        => 'Atlas Migration',
			'description' => 'Move the estate, phase two.',
			'status'      => 'completed',
			'end_date'    => '2026-04-15',
		),
	),
	'delete-project'        => array(
		'delete_project',
		array( 'project_id' => '<project:cinder>' ),
	),
	'create-task'           => array(
		'create_task',
		array(
			'title'       => 'Provision accounts',
			'description' => 'Create the starter accounts.',
			'project_id'  => '<project:atlas>',
			'status'      => 'pending',
			'priority'    => 'medium',
			'due_date'    => '2026-05-15',
			'assigned_to' => '<user>',
			'category'    => 'general',
		),
	),
	'create-task-upsert'    => array(
		'create_task',
		array(
			'task_id'     => '<task:plan>',
			'title'       => 'Draft the cutover plan',
			'description' => 'Rewrite against the phase-two scope.',
			'project_id'  => '<project:atlas>',
			'status'      => 'in-progress',
			'priority'    => 'high',
			'category'    => 'maintenance',
			'tags'        => 'cutover,phase-two',
			'due_date'    => '2026-06-10',
			'assigned_to' => '<user>',
		),
	),
	'update-task'           => array(
		'update_task',
		array(
			'task_id'  => '<task:contrast>',
			'title'    => 'Run the contrast sweep',
			'status'   => 'completed',
			'priority' => 'medium',
		),
	),
	'delete-task'           => array(
		'delete_task',
		array( 'task_id' => '<task:contrast>' ),
	),
);

$tools = array(
	'create_project' => new WP_MCP_AI_Tool_Create_Project(),
	'update_project' => new WP_MCP_AI_Tool_Update_Project(),
	'delete_project' => new WP_MCP_AI_Tool_Delete_Project(),
	'create_task'    => new WP_MCP_AI_Tool_Create_Task(),
	'update_task'    => new WP_MCP_AI_Tool_Update_Task(),
	'delete_task'    => new WP_MCP_AI_Tool_Delete_Task(),
);

$out = array();

foreach ( $cases as $name => $case ) {
	$runtime_args = $case[1];

	foreach ( $runtime_args as $key => $value ) {
		if ( is_string( $value ) && isset( $symbols[ $value ] ) ) {
			$runtime_args[ $key ] = $symbols[ $value ];
			continue;
		}

		if ( is_array( $value ) ) {
			$runtime_args[ $key ] = array_map(
				static fn ( $v ) => is_string( $v ) && isset( $symbols[ $v ] ) ? $symbols[ $v ] : $v,
				$value
			);
		}
	}

	$result = $tools[ $case[0] ]->execute( $runtime_args );

	if ( is_wp_error( $result ) ) {
		$out[ $name ] = array(
			'tool'     => $case[0],
			'args'     => $case[1],
			'expected' => array( 'error' => $result->get_error_code() ),
		);
		continue;
	}

	$out[ $name ] = array(
		'tool'     => $case[0],
		'args'     => $case[1],
		'expected' => $normalise( $result ),
	);
}

// Created rows outlive the seed list.
foreach ( get_posts(
	array(
		'post_type'   => array( 'mcp_ai_project', 'mcp_ai_task' ),
		'post_status' => 'any',
		'numberposts' => -1,
		'fields'      => 'ids',
	)
) as $post_id ) {
	if ( in_array( (int) $post_id, $seeded, true ) || 'Seeded for the write corpus.' === get_post_field( 'post_content', $post_id ) ) {
		wp_delete_post( (int) $post_id, true );
	}
}

foreach ( array( 'Delta Onboarding', 'Provision accounts' ) as $title ) {
	$found = get_posts(
		array(
			'post_type'   => array( 'mcp_ai_project', 'mcp_ai_task' ),
			'post_status' => 'any',
			'title'       => $title,
			'numberposts' => -1,
			'fields'      => 'ids',
		)
	);

	foreach ( $found as $post_id ) {
		wp_delete_post( (int) $post_id, true );
	}
}

update_option( 'wp_mcp_ai_settings', $settings_snapshot );

$target = WP_MCP_AI_PATH . 'tests/fixtures/pm/writes.json';

if ( ! is_dir( dirname( $target ) ) ) {
	wp_mkdir_p( dirname( $target ) );
}

$json = wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

file_put_contents( $target, $json . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture writer.

printf( "Wrote %d cases to %s\n", count( $out ), $target );
