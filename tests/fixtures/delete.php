<?php
/**
 * Fixture Teardown — cleans up all fixture data created by create.php.
 *
 * Reads fixture IDs from wp-content/uploads/wp-mcp-ai-fixtures.json
 * and deletes the corresponding entities.
 *
 * Usage:
 *   studio wp --user=admin eval-file tests/fixtures/delete.php
 *
 * @package WP_MCP_AI
 * @since  1.1.34
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	fwrite( STDERR, "This script must be run via WP-CLI.\n" );
	exit( 1 );
}

$fixture_file = WP_CONTENT_DIR . '/uploads/wp-mcp-ai-fixtures.json';

if ( ! file_exists( $fixture_file ) ) {
	WP_CLI::warning( 'No fixture file found. Nothing to clean up.' );
	exit( 0 );
}

$fixtures = json_decode( file_get_contents( $fixture_file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

if ( ! is_array( $fixtures ) ) {
	WP_CLI::warning( 'Fixture file is malformed. Nothing to clean up.' );
	exit( 0 );
}

$user_id = get_current_user_id();
$deleted = 0;
$errors  = 0;

WP_CLI::log( '=== Tearing down test fixtures ===' );

// Delete in dependency order (children first).
$delete_order = array(
	'sprint_id'    => array( 'tool' => 'WP_MCP_AI_Tool_Delete_Project', 'label' => 'sprint',   'param' => 'project_id' ),
	'task_id'      => array( 'tool' => 'WP_MCP_AI_Tool_Delete_Task',   'label' => 'task',     'param' => 'task_id' ),
	'deal_id'      => array( 'tool' => 'WP_MCP_AI_Tool_Delete_Deal',   'label' => 'deal',     'param' => 'deal_id', 'extra' => array( 'confirm' => true ) ),
	'sequence_id'  => array( 'tool' => false,                           'label' => 'sequence', 'param' => null ),
	'template_id'  => array( 'tool' => false,                           'label' => 'template', 'param' => null ),
	'event_id'     => array( 'tool' => 'WP_MCP_AI_Tool_Delete_Event',  'label' => 'event',    'param' => 'event_id' ),
	'lead_id'      => array( 'tool' => 'WP_MCP_AI_Tool_Delete_Lead',   'label' => 'lead',     'param' => 'lead_id', 'extra' => array( 'confirmation_required' => true ) ),
	'project_id'   => array( 'tool' => 'WP_MCP_AI_Tool_Delete_Project', 'label' => 'project',  'param' => 'project_id' ),
);

foreach ( $delete_order as $key => $info ) {
	if ( empty( $fixtures[ $key ] ) ) {
		continue;
	}

	$id = absint( $fixtures[ $key ] );

	// Fallback: delete via wp_delete_post if no specific tool.
	if ( ! $info['tool'] ) {
		$post = get_post( $id );
		if ( $post ) {
			$result = wp_delete_post( $id, true );
			if ( $result ) {
				WP_CLI::log( sprintf( '  Deleted %s #%d (wp_delete_post)', $info['label'], $id ) );
				$deleted++;
			} else {
				WP_CLI::warning( sprintf( '  Failed to delete %s #%d', $info['label'], $id ) );
				$errors++;
			}
		} else {
			WP_CLI::log( sprintf( '  %s #%d already gone.', $info['label'], $id ) );
		}
		continue;
	}

	if ( ! class_exists( $info['tool'] ) ) {
		// Fallback to wp_delete_post.
		$post = get_post( $id );
		if ( $post ) {
			wp_delete_post( $id, true );
			WP_CLI::log( sprintf( '  Deleted %s #%d (class not found, wp_delete_post fallback)', $info['label'], $id ) );
			$deleted++;
		}
		continue;
	}

	$tool    = new $info['tool']();
	$args    = array( $info['param'] => $id );
	if ( isset( $info['extra'] ) ) {
		$args = array_merge( $args, $info['extra'] );
	}

	if ( method_exists( $tool, 'is_available' ) && ! $tool->is_available() ) {
		// Fallback.
		$post = get_post( $id );
		if ( $post ) {
			wp_delete_post( $id, true );
			WP_CLI::log( sprintf( '  Deleted %s #%d (tool unavailable, wp_delete_post fallback)', $info['label'], $id ) );
			$deleted++;
		}
		continue;
	}

	$result = $tool->execute( $args, array( 'user_id' => $user_id ) );

	if ( is_wp_error( $result ) ) {
		WP_CLI::warning( sprintf( '  Failed to delete %s #%d: %s', $info['label'], $id, $result->get_error_message() ) );
		$errors++;
	} else {
		WP_CLI::log( sprintf( '  Deleted %s #%d', $info['label'], $id ) );
		$deleted++;
	}
}

// Remove the fixture file.
// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
unlink( $fixture_file );

WP_CLI::log( '' );
WP_CLI::success( sprintf( 'Cleaned up: %d deleted, %d errors, %d total fixtures.', $deleted, $errors, count( $fixtures ) ) );
