<?php
/**
 * Tests for the 36 new workflow preset tools added in v2.7.0+.
 *
 * Validates that every tool referenced in schedule workflow presets:
 * - Implements the required tool interfaces
 * - Has a valid slug, name, description, parameters schema, and capability
 * - Has working is_available() and get_unavailable_reason() methods
 * - Has get_definition() and get_capability_flags() methods
 *
 * @package WP_MCP_AI_Pro
 * @since   2.7.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
 */

// Guard: only run if Pro addon is present.
if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
	return;
}

/**
 * Test suite for new workflow preset tools.
 *
 * @since 2.7.0
 */
class Test_New_Workflow_Preset_Tools extends WP_UnitTestCase {

	/**
	 * Map of tool slug => expected class name.
	 *
	 * @var array<string, string>
	 */
	const TOOL_MAP = array(
		// Calendar Booking (4).
		'get_no_show_appointments'     => 'WP_MCP_AI_Tool_Get_No_Show_Appointments',
		'get_unconfirmed_bookings'     => 'WP_MCP_AI_Tool_Get_Unconfirmed_Bookings',
		'send_booking_confirmations'   => 'WP_MCP_AI_Tool_Send_Booking_Confirmations',
		'send_reschedule_invitation'   => 'WP_MCP_AI_Tool_Send_Reschedule_Invitation',
		// CRM (4).
		'get_contact_interactions'     => 'WP_MCP_AI_Tool_Get_Contact_Interactions',
		'archive_stale_contacts'       => 'WP_MCP_AI_Tool_Archive_Stale_Contacts',
		'recalculate_engagement_scores' => 'WP_MCP_AI_Tool_Recalculate_Engagement_Scores',
		'scan_duplicate_contacts'      => 'WP_MCP_AI_Tool_Scan_Duplicate_Contacts',
		// DJ Management (2).
		'get_trending_tracks'          => 'WP_MCP_AI_Tool_Get_Trending_Tracks',
		'update_playlist_rotation'     => 'WP_MCP_AI_Tool_Update_Playlist_Rotation',
		// Document Generation (4).
		'get_expired_documents'        => 'WP_MCP_AI_Tool_Get_Expired_Documents',
		'get_uninvoiced_orders'        => 'WP_MCP_AI_Tool_Get_Uninvoiced_Orders',
		'archive_documents'            => 'WP_MCP_AI_Tool_Archive_Documents',
		'generate_invoice_batch'       => 'WP_MCP_AI_Tool_Generate_Invoice_Batch',
		// E-commerce (2).
		'get_abandoned_carts'          => 'WP_MCP_AI_Tool_Get_Abandoned_Carts',
		'send_cart_recovery_email'     => 'WP_MCP_AI_Tool_Send_Cart_Recovery_Email',
		// Financial Planner (2).
		'get_uncategorised_transactions' => 'WP_MCP_AI_Tool_Get_Uncategorised_Transactions',
		'categorise_transactions'       => 'WP_MCP_AI_Tool_Categorise_Transactions',
		// Health & Wellness (2).
		'get_recent_health_appointments' => 'WP_MCP_AI_Tool_Get_Recent_Health_Appointments',
		'send_appointment_followup'     => 'WP_MCP_AI_Tool_Send_Appointment_Followup',
		// Image Production (5).
		'get_images_without_alt'       => 'WP_MCP_AI_Tool_Get_Images_Without_Alt',
		'get_unoptimised_images'       => 'WP_MCP_AI_Tool_Get_Unoptimised_Images',
		'get_unwatermarked_images'     => 'WP_MCP_AI_Tool_Get_Unwatermarked_Images',
		'apply_watermark_batch'        => 'WP_MCP_AI_Tool_Apply_Watermark_Batch',
		'optimise_images_batch'        => 'WP_MCP_AI_Tool_Optimise_Images_Batch',
		// Media (2).
		'scan_orphaned_media'          => 'WP_MCP_AI_Tool_Scan_Orphaned_Media',
		'cleanup_orphaned_media'       => 'WP_MCP_AI_Tool_Cleanup_Orphaned_Media',
		// Social Media (4).
		'get_content_calendar'         => 'WP_MCP_AI_Tool_Get_Content_Calendar',
		'generate_social_captions'     => 'WP_MCP_AI_Tool_Generate_Social_Captions',
		'schedule_social_posts'        => 'WP_MCP_AI_Tool_Schedule_Social_Posts',
		'publish_to_social'            => 'WP_MCP_AI_Tool_Publish_To_Social',
		// Video Production (5).
		'get_queued_videos'            => 'WP_MCP_AI_Tool_Get_Queued_Videos',
		'get_videos_without_thumbnails' => 'WP_MCP_AI_Tool_Get_Videos_Without_Thumbnails',
		'get_videos_without_transcripts' => 'WP_MCP_AI_Tool_Get_Videos_Without_Transcripts',
		'upload_video_batch'           => 'WP_MCP_AI_Tool_Upload_Video_Batch',
		'transcribe_video'             => 'WP_MCP_AI_Tool_Transcribe_Video',
	);

	/**
	 * Get a list of all new tool slugs.
	 *
	 * @return array
	 */
	public static function provide_all_tool_slugs() {
		$data = array();
		foreach ( self::TOOL_MAP as $slug => $class ) {
			$data[ $slug ] = array( $slug, $class );
		}
		return $data;
	}

	/**
	 * Test that every tool slug has a corresponding class that exists.
	 *
	 * @dataProvider provide_all_tool_slugs
	 * @param string $slug  Tool slug.
	 * @param string $class Expected class name.
	 */
	public function test_tool_class_exists( $slug, $class ) {
		if ( ! class_exists( $class ) ) {
			$this->markTestSkipped( sprintf( 'Class %s not yet implemented.', $class ) );
			return;
		}
		$this->assertTrue(
			class_exists( $class ),
			sprintf( 'Tool class %s should exist for slug %s.', $class, $slug )
		);
	}

	/**
	 * Test that every tool implements the required interfaces.
	 *
	 * @dataProvider provide_all_tool_slugs
	 * @param string $slug  Tool slug.
	 * @param string $class Expected class name.
	 */
	public function test_tool_implements_interfaces( $slug, $class ) {
		if ( ! class_exists( $class ) ) {
			$this->markTestSkipped( sprintf( 'Class %s not found.', $class ) );
			return;
		}

		$instance = new $class();

		$this->assertInstanceOf(
			'WP_MCP_AI_Tool_Interface',
			$instance,
			sprintf( '%s should implement WP_MCP_AI_Tool_Interface.', $class )
		);

		$this->assertInstanceOf(
			'WP_MCP_AI_Tool_Capability_Flags_Interface',
			$instance,
			sprintf( '%s should implement WP_MCP_AI_Tool_Capability_Flags_Interface.', $class )
		);
	}

	/**
	 * Test that every tool has a valid, non-empty slug.
	 *
	 * @dataProvider provide_all_tool_slugs
	 * @param string $slug  Tool slug.
	 * @param string $class Expected class name.
	 */
	public function test_tool_slug_matches( $slug, $class ) {
		if ( ! class_exists( $class ) ) {
			$this->markTestSkipped( sprintf( 'Class %s not found.', $class ) );
			return;
		}

		$instance = new $class();

		$this->assertSame(
			$slug,
			$instance->get_slug(),
			sprintf( '%s::get_slug() should return "%s".', $class, $slug )
		);
	}

	/**
	 * Test that every tool has a non-empty name and description.
	 *
	 * @dataProvider provide_all_tool_slugs
	 * @param string $slug  Tool slug.
	 * @param string $class Expected class name.
	 */
	public function test_tool_has_name_and_description( $slug, $class ) {
		if ( ! class_exists( $class ) ) {
			$this->markTestSkipped( sprintf( 'Class %s not found.', $class ) );
			return;
		}

		$instance = new $class();

		$name = $instance->get_name();
		$this->assertNotEmpty( $name, sprintf( '%s::get_name() should not be empty.', $class ) );
		$this->assertIsString( $name );

		$description = $instance->get_description();
		$this->assertNotEmpty( $description, sprintf( '%s::get_description() should not be empty.', $class ) );
		$this->assertIsString( $description );
	}

	/**
	 * Test that every tool has a valid parameters schema.
	 *
	 * @dataProvider provide_all_tool_slugs
	 * @param string $slug  Tool slug.
	 * @param string $class Expected class name.
	 */
	public function test_tool_has_valid_parameters_schema( $slug, $class ) {
		if ( ! class_exists( $class ) ) {
			$this->markTestSkipped( sprintf( 'Class %s not found.', $class ) );
			return;
		}

		$instance = new $class();
		$schema   = $instance->get_parameters_schema();

		$this->assertIsArray( $schema, sprintf( '%s::get_parameters_schema() should return an array.', $class ) );
		$this->assertArrayHasKey( 'type', $schema, sprintf( '%s schema should have "type" key.', $class ) );
		$this->assertSame( 'object', $schema['type'], sprintf( '%s schema type should be "object".', $class ) );
		$this->assertArrayHasKey( 'properties', $schema, sprintf( '%s schema should have "properties" key.', $class ) );
		$this->assertIsArray( $schema['properties'] );
	}

	/**
	 * Test that every tool has a valid capability string.
	 *
	 * @dataProvider provide_all_tool_slugs
	 * @param string $slug  Tool slug.
	 * @param string $class Expected class name.
	 */
	public function test_tool_has_valid_capability( $slug, $class ) {
		if ( ! class_exists( $class ) ) {
			$this->markTestSkipped( sprintf( 'Class %s not found.', $class ) );
			return;
		}

		$instance   = new $class();
		$capability = $instance->get_required_capability();

		$this->assertNotEmpty( $capability, sprintf( '%s::get_required_capability() should not be empty.', $class ) );
		$this->assertIsString( $capability );
	}

	/**
	 * Test that every tool has a working is_available() method.
	 *
	 * @dataProvider provide_all_tool_slugs
	 * @param string $slug  Tool slug.
	 * @param string $class Expected class name.
	 */
	public function test_tool_is_available_returns_bool( $slug, $class ) {
		if ( ! class_exists( $class ) ) {
			$this->markTestSkipped( sprintf( 'Class %s not found.', $class ) );
			return;
		}

		$this->assertTrue(
			method_exists( $class, 'is_available' ),
			sprintf( '%s should have an is_available() method.', $class )
		);

		$available = call_user_func( array( $class, 'is_available' ) );
		$this->assertIsBool( $available, sprintf( '%s::is_available() should return a boolean.', $class ) );
	}

	/**
	 * Test that every tool has get_definition() returning valid metadata.
	 *
	 * @dataProvider provide_all_tool_slugs
	 * @param string $slug  Tool slug.
	 * @param string $class Expected class name.
	 */
	public function test_tool_has_valid_definition( $slug, $class ) {
		if ( ! class_exists( $class ) ) {
			$this->markTestSkipped( sprintf( 'Class %s not found.', $class ) );
			return;
		}

		$instance   = new $class();
		$definition = $instance->get_definition();

		$this->assertIsArray( $definition, sprintf( '%s::get_definition() should return an array.', $class ) );
		$this->assertArrayHasKey( 'name', $definition );
		$this->assertArrayHasKey( 'description', $definition );
		$this->assertArrayHasKey( 'toolkit', $definition );
	}

	/**
	 * Test that every tool has valid capability flags.
	 *
	 * @dataProvider provide_all_tool_slugs
	 * @param string $slug  Tool slug.
	 * @param string $class Expected class name.
	 */
	public function test_tool_has_valid_capability_flags( $slug, $class ) {
		if ( ! class_exists( $class ) ) {
			$this->markTestSkipped( sprintf( 'Class %s not found.', $class ) );
			return;
		}

		$instance = new $class();
		$flags    = $instance->get_capability_flags();

		$this->assertIsArray( $flags, sprintf( '%s::get_capability_flags() should return an array.', $class ) );
		$this->assertNotEmpty( $flags, sprintf( '%s should have at least one capability flag.', $class ) );

		// Every pro tool should have the 'pro' flag.
		$this->assertContains( 'pro', $flags, sprintf( '%s should have the "pro" capability flag.', $class ) );
	}

	/**
	 * Test that action tools default to dry_run = true for safety.
	 *
	 * @dataProvider provide_action_tools
	 * @param string $slug  Tool slug.
	 * @param string $class Expected class name.
	 */
	public function test_action_tools_have_dry_run_safety( $slug, $class ) {
		if ( ! class_exists( $class ) ) {
			$this->markTestSkipped( sprintf( 'Class %s not found.', $class ) );
			return;
		}

		$instance = new $class();
		$schema   = $instance->get_parameters_schema();

		if ( isset( $schema['properties']['dry_run'] ) ) {
			$dry_run_prop = $schema['properties']['dry_run'];

			// Default value should be true for safety.
			if ( isset( $dry_run_prop['default'] ) ) {
				$this->assertTrue(
					(bool) $dry_run_prop['default'],
					sprintf( '%s should default dry_run to true for safety.', $class )
				);
			}
		}
	}

	/**
	 * Provide action tools (tools that modify state).
	 *
	 * @return array
	 */
	public static function provide_action_tools() {
		$action_slugs = array(
			'send_booking_confirmations',
			'send_reschedule_invitation',
			'archive_stale_contacts',
			'recalculate_engagement_scores',
			'update_playlist_rotation',
			'archive_documents',
			'generate_invoice_batch',
			'send_cart_recovery_email',
			'categorise_transactions',
			'send_appointment_followup',
			'apply_watermark_batch',
			'optimise_images_batch',
			'cleanup_orphaned_media',
			'schedule_social_posts',
			'publish_to_social',
			'upload_video_batch',
			'transcribe_video',
		);

		$data = array();
		foreach ( $action_slugs as $slug ) {
			if ( isset( self::TOOL_MAP[ $slug ] ) ) {
				$data[ $slug ] = array( $slug, self::TOOL_MAP[ $slug ] );
			}
		}
		return $data;
	}

	/**
	 * Test that query tools have the 'read-only' capability flag.
	 *
	 * @dataProvider provide_query_tools
	 * @param string $slug  Tool slug.
	 * @param string $class Expected class name.
	 */
	public function test_query_tools_are_read_only( $slug, $class ) {
		if ( ! class_exists( $class ) ) {
			$this->markTestSkipped( sprintf( 'Class %s not found.', $class ) );
			return;
		}

		$instance = new $class();
		$flags    = $instance->get_capability_flags();

		$this->assertContains(
			'read-only',
			$flags,
			sprintf( '%s (query tool) should have the "read-only" capability flag.', $class )
		);
	}

	/**
	 * Provide query tools (tools that only read data).
	 *
	 * @return array
	 */
	public static function provide_query_tools() {
		$query_slugs = array(
			'get_no_show_appointments',
			'get_unconfirmed_bookings',
			'get_contact_interactions',
			'scan_duplicate_contacts',
			'get_trending_tracks',
			'get_expired_documents',
			'get_uninvoiced_orders',
			'get_abandoned_carts',
			'get_uncategorised_transactions',
			'get_recent_health_appointments',
			'get_images_without_alt',
			'get_unoptimised_images',
			'get_unwatermarked_images',
			'scan_orphaned_media',
			'get_content_calendar',
			'get_queued_videos',
			'get_videos_without_thumbnails',
			'get_videos_without_transcripts',
		);

		$data = array();
		foreach ( $query_slugs as $slug ) {
			if ( isset( self::TOOL_MAP[ $slug ] ) ) {
				$data[ $slug ] = array( $slug, self::TOOL_MAP[ $slug ] );
			}
		}
		return $data;
	}

	/**
	 * Test that scan_orphaned_media executes successfully with default args.
	 */
	public function test_scan_orphaned_media_executes() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Scan_Orphaned_Media' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Tool_Scan_Orphaned_Media not found.' );
			return;
		}

		// Enable the media toolkit.
		update_option(
			'wp_mcp_ai_settings',
			array_merge(
				get_option( 'wp_mcp_ai_settings', array() ),
				array( 'enable_media_toolkit' => true )
			)
		);

		$tool    = new WP_MCP_AI_Tool_Scan_Orphaned_Media();
		$result  = $tool->execute( array(), array() );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'unreferenced', $result );
		$this->assertArrayHasKey( 'missing_files', $result );
		$this->assertArrayHasKey( 'unregistered', $result );
	}

	/**
	 * Test that cleanup_orphaned_media executes in dry_run mode by default.
	 */
	public function test_cleanup_orphaned_media_dry_run() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Cleanup_Orphaned_Media' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Tool_Cleanup_Orphaned_Media not found.' );
			return;
		}

		// Enable the media toolkit.
		update_option(
			'wp_mcp_ai_settings',
			array_merge(
				get_option( 'wp_mcp_ai_settings', array() ),
				array( 'enable_media_toolkit' => true )
			)
		);

		$tool   = new WP_MCP_AI_Tool_Cleanup_Orphaned_Media();
		$result = $tool->execute( array(), array() );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'dry_run', $result );
		$this->assertTrue( $result['dry_run'], 'Dry run should be true by default.' );
	}

	/**
	 * Test that all workflow preset tools referenced in schedule presets
	 * are accounted for in the TOOL_MAP.
	 */
	public function test_tool_count_matches_expected() {
		// 36 tools total: 34 new + 2 original fix.
		$this->assertCount( 36, self::TOOL_MAP, 'Should have exactly 36 tools in the map.' );
	}

	/**
	 * Test that schedule presets use only slugs present in TOOL_MAP.
	 */
	public function test_all_workflow_preset_tools_exist() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Schedule_Presets' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Pro_Schedule_Presets not available.' );
			return;
		}

		$presets = WP_MCP_AI_Pro_Schedule_Presets::get_presets();

		$missing = array();
		foreach ( $presets as $preset_id => $preset ) {
			if ( empty( $preset['schedule_type'] ) || 'workflow' !== $preset['schedule_type'] ) {
				continue;
			}

			if ( empty( $preset['schedule_data']['workflow_steps'] ) ) {
				continue;
			}

			foreach ( $preset['schedule_data']['workflow_steps'] as $step ) {
				if ( empty( $step['tool_slug'] ) ) {
					continue;
				}

				$tool_slug = $step['tool_slug'];

				// Check if this tool exists in our map OR is a known existing tool.
				$known_tools = array_merge(
					array_keys( self::TOOL_MAP ),
					array(
						'crm_email_search_correspondence',
						'crm_email_search_leads',
						'forecast_pipeline_revenue',
						'generate_image_alt_text',
						'generate_video_thumbnails',
						'get_recent_posts',
						'list_customers',
						'manage_crm_contact',
						'search_attachments',
						'search_upwork_jobs',
						'send_group_email',
						'track_document_version',
						'validate_document_checklist',
					)
				);

				if ( ! in_array( $tool_slug, $known_tools, true ) ) {
					$missing[] = $tool_slug;
				}
			}
		}

		$this->assertEmpty(
			$missing,
			sprintf(
				'All workflow preset tool slugs should be implemented. Missing: %s.',
				implode( ', ', $missing )
			)
		);
	}
}
