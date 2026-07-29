<?php
/**
 * Incident Custom Post Type
 *
 * Registers the `mcp_ai_incident` CPT for operational incident management.
 * Each post represents a live incident with a phase state machine (detected →
 * investigating → identified → monitoring → resolved), severity level, affected
 * services, and an append-only timeline of updates.
 *
 * When an incident is resolved, it can be linked to an `mcp_ai_lesson` post
 * in the Incident Learning System for post-mortem analysis (ISO 27001 A.5.27).
 *
 * @package   WP_MCP_AI_Pro
 * @since     1.4.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Incident_CPT' ) ) {
	/**
	 * Incident CPT class.
	 *
	 * @since 1.4.0
	 */
	class WP_MCP_AI_Incident_CPT {

		/**
		 * Post type slug.
		 *
		 * @since 1.4.0
		 * @var string
		 */
		const POST_TYPE = 'mcp_ai_incident';

		/**
		 * Phase constants.
		 *
		 * @since 1.4.0
		 * @var string
		 */
		const PHASE_DETECTED      = 'detected';
		const PHASE_INVESTIGATING = 'investigating';
		const PHASE_IDENTIFIED    = 'identified';
		const PHASE_MONITORING    = 'monitoring';
		const PHASE_RESOLVED      = 'resolved';

		/**
		 * Severity constants.
		 *
		 * @since 1.4.0
		 * @var string
		 */
		const SEVERITY_MINOR    = 'minor';
		const SEVERITY_MAJOR    = 'major';
		const SEVERITY_CRITICAL = 'critical';

		/**
		 * Valid phase transitions.
		 *
		 * @since 1.4.0
		 * @var array<string, string[]>
		 */
		const VALID_TRANSITIONS = array(
			self::PHASE_DETECTED      => array( self::PHASE_INVESTIGATING, self::PHASE_RESOLVED ),
			self::PHASE_INVESTIGATING => array( self::PHASE_IDENTIFIED, self::PHASE_RESOLVED ),
			self::PHASE_IDENTIFIED    => array( self::PHASE_MONITORING, self::PHASE_RESOLVED ),
			self::PHASE_MONITORING    => array( self::PHASE_RESOLVED ),
			self::PHASE_RESOLVED      => array(),
		);

		/**
		 * Maximum timeline entries per incident.
		 *
		 * @since 1.4.0
		 * @var int
		 */
		const MAX_TIMELINE_ENTRIES = 100;

		/**
		 * Cooldown between auto-created incidents per component (seconds).
		 *
		 * @since 1.4.0
		 * @var int
		 */
		const AUTO_CREATE_COOLDOWN = 3600;

		/**
		 * Register the CPT and hooks.
		 *
		 * @since 1.4.0
		 *
		 * @return void
		 */
		public static function init(): void {
			add_action( 'init', array( __CLASS__, 'register_post_type' ) );
			add_action( 'init', array( __CLASS__, 'register_meta' ) );

			// Auto-create incidents from service status changes (with cooldown).
			add_action( 'wp_mcp_ai_service_status_changed', array( __CLASS__, 'maybe_auto_create_incident' ), 10, 4 );
		}

		/**
		 * Register the custom post type.
		 *
		 * @since 1.4.0
		 *
		 * @return void
		 */
		public static function register_post_type(): void {
			$labels = array(
				'name'               => __( 'Incidents', 'mcp-ai-wpoos-pro' ),
				'singular_name'      => __( 'Incident', 'mcp-ai-wpoos-pro' ),
				'add_new'            => __( 'Report Incident', 'mcp-ai-wpoos-pro' ),
				'add_new_item'       => __( 'Report New Incident', 'mcp-ai-wpoos-pro' ),
				'edit_item'          => __( 'Manage Incident', 'mcp-ai-wpoos-pro' ),
				'new_item'           => __( 'New Incident', 'mcp-ai-wpoos-pro' ),
				'view_item'          => __( 'View Incident', 'mcp-ai-wpoos-pro' ),
				'search_items'       => __( 'Search Incidents', 'mcp-ai-wpoos-pro' ),
				'not_found'          => __( 'No incidents found.', 'mcp-ai-wpoos-pro' ),
				'not_found_in_trash' => __( 'No incidents found in Trash.', 'mcp-ai-wpoos-pro' ),
				'all_items'          => __( 'All Incidents', 'mcp-ai-wpoos-pro' ),
			);

			$args = array(
				'labels'          => $labels,
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => false,
				'show_in_rest'    => true,
				'rest_base'       => 'mcp-ai-incidents',
				'supports'        => array( 'title' ),
				'capability_type' => 'post',
				'capabilities'    => array(
					'create_posts' => 'manage_options',
				),
				'map_meta_cap'    => true,
				'has_archive'     => false,
				'rewrite'         => false,
				'query_var'       => false,
			);

			register_post_type( self::POST_TYPE, $args );
		}

		/**
		 * Register post meta fields.
		 *
		 * @since 1.4.0
		 *
		 * @return void
		 */
		public static function register_meta(): void {
			$meta_fields = array(
				'_mcp_ai_incident_phase'           => array(
					'type'        => 'string',
					'description' => __( 'Current phase of the incident.', 'mcp-ai-wpoos-pro' ),
					'default'     => self::PHASE_DETECTED,
				),
				'_mcp_ai_incident_severity'        => array(
					'type'        => 'string',
					'description' => __( 'Severity level.', 'mcp-ai-wpoos-pro' ),
					'default'     => self::SEVERITY_MINOR,
				),
				'_mcp_ai_incident_services'        => array(
					'type'         => 'array',
					'description'  => __( 'Affected service component slugs.', 'mcp-ai-wpoos-pro' ),
					'show_in_rest' => array(
						'schema' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
					),
				),
				'_mcp_ai_incident_timeline'        => array(
					'type'         => 'array',
					'description'  => __( 'Append-only timeline of incident updates.', 'mcp-ai-wpoos-pro' ),
					'show_in_rest' => array(
						'schema' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'timestamp'   => array( 'type' => 'integer' ),
									'phase'       => array( 'type' => 'string' ),
									'message'     => array( 'type' => 'string' ),
									'operator_id' => array( 'type' => 'integer' ),
								),
							),
						),
					),
				),
				'_mcp_ai_incident_resolved_at'     => array(
					'type'        => 'string',
					'description' => __( 'ISO 8601 timestamp when resolved.', 'mcp-ai-wpoos-pro' ),
				),
				'_mcp_ai_incident_lesson_id'       => array(
					'type'        => 'integer',
					'description' => __( 'Linked mcp_ai_lesson post ID.', 'mcp-ai-wpoos-pro' ),
				),
				'_mcp_ai_incident_notify_channels' => array(
					'type'         => 'array',
					'description'  => __( 'Notification channel identifiers.', 'mcp-ai-wpoos-pro' ),
					'show_in_rest' => array(
						'schema' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
					),
				),
			);

			foreach ( $meta_fields as $key => $args ) {
				$defaults = array(
					'single'       => true,
					'show_in_rest' => true,
				);

				$args = array_merge( $defaults, $args );

				register_post_meta( self::POST_TYPE, $key, $args );
			}
		}

		/**
		 * Transition an incident to a new phase.
		 *
		 * Validates the transition, appends to the timeline, and fires hooks.
		 *
		 * @since 1.4.0
		 *
		 * @param int    $post_id    Incident post ID.
		 * @param string $new_phase  Target phase.
		 * @param string $message    Update message for the timeline.
		 * @return bool True on success.
		 */
		public static function transition_phase( int $post_id, string $new_phase, string $message = '' ): bool {
			$old_phase = (string) get_post_meta( $post_id, '_mcp_ai_incident_phase', true );

			if ( '' === $old_phase ) {
				$old_phase = self::PHASE_DETECTED;
			}

			if ( $old_phase === $new_phase ) {
				// Same phase — just append a timeline update.
				if ( '' !== $message ) {
					self::append_timeline_entry( $post_id, $new_phase, $message );
				}
				return true;
			}

			// Validate transition.
			$allowed = self::VALID_TRANSITIONS[ $old_phase ] ?? array();
			if ( ! in_array( $new_phase, $allowed, true ) ) {
				return false;
			}

			update_post_meta( $post_id, '_mcp_ai_incident_phase', $new_phase );

			// Append timeline entry.
			self::append_timeline_entry( $post_id, $new_phase, $message );

			// Set resolved timestamp.
			if ( self::PHASE_RESOLVED === $new_phase ) {
				update_post_meta( $post_id, '_mcp_ai_incident_resolved_at', gmdate( 'c' ) );

				/** Fires when an incident is resolved. @since 1.4.0 */
				do_action( 'wp_mcp_ai_incident_resolved', $post_id );
			}

			/** Fires when an incident phase changes. @since 1.4.0 */
			do_action( 'wp_mcp_ai_incident_phase_changed', $post_id, $old_phase, $new_phase );

			return true;
		}

		/**
		 * Append an entry to the incident timeline.
		 *
		 * @since 1.4.0
		 *
		 * @param int    $post_id Incident post ID.
		 * @param string $phase   Current phase.
		 * @param string $message Update message.
		 * @return void
		 */
		public static function append_timeline_entry( int $post_id, string $phase, string $message ): void {
			$timeline = get_post_meta( $post_id, '_mcp_ai_incident_timeline', true );

			if ( ! is_array( $timeline ) ) {
				$timeline = array();
			}

			// Enforce max entries (keep most recent).
			if ( count( $timeline ) >= self::MAX_TIMELINE_ENTRIES ) {
				array_shift( $timeline );
			}

			$timeline[] = array(
				'timestamp'   => time(),
				'phase'       => $phase,
				'message'     => $message,
				'operator_id' => get_current_user_id(),
			);

			update_post_meta( $post_id, '_mcp_ai_incident_timeline', $timeline );
		}

		/**
		 * Get the public-friendly phase label.
		 *
		 * @since 1.4.0
		 *
		 * @param string $phase Phase slug.
		 * @return string
		 */
		public static function get_phase_label( string $phase ): string {
			$labels = array(
				self::PHASE_DETECTED      => __( 'Detected', 'mcp-ai-wpoos-pro' ),
				self::PHASE_INVESTIGATING => __( 'Investigating', 'mcp-ai-wpoos-pro' ),
				self::PHASE_IDENTIFIED    => __( 'Identified', 'mcp-ai-wpoos-pro' ),
				self::PHASE_MONITORING    => __( 'Monitoring', 'mcp-ai-wpoos-pro' ),
				self::PHASE_RESOLVED      => __( 'Resolved', 'mcp-ai-wpoos-pro' ),
			);

			return $labels[ $phase ] ?? $phase;
		}

		/**
		 * Get active (unresolved) incidents.
		 *
		 * @since 1.4.0
		 *
		 * @param int $limit Maximum to return.
		 * @return WP_Post[]
		 */
		public static function get_active_incidents( int $limit = 10 ): array {
			return get_posts(
				array(
					'post_type'      => self::POST_TYPE,
					'post_status'    => 'publish',
					'posts_per_page' => $limit,
					'meta_key'       => '_mcp_ai_incident_phase',
					'meta_value'     => self::PHASE_RESOLVED,
					'meta_compare'   => '!=',
					'orderby'        => 'date',
					'order'          => 'DESC',
					'no_found_rows'  => true,
				)
			);
		}

		/**
		 * Auto-create an incident when a service status degrades.
		 *
		 * Hooked into wp_mcp_ai_service_status_changed. Only fires for
		 * major_outage transitions with a per-component cooldown to prevent
		 * incident spam from flapping health checks.
		 *
		 * @since 1.4.0
		 *
		 * @param string $slug       Component slug.
		 * @param string $old_status Previous status.
		 * @param string $new_status New status.
		 * @param array  $component  Component data.
		 * @return void
		 */
		public static function maybe_auto_create_incident( string $slug, string $old_status, string $new_status, array $component ): void {
			unset( $old_status, $component );

			if ( 'major_outage' !== $new_status ) {
				return;
			}

			// Check cooldown.
			$cooldown_key = 'wp_mcp_ai_incident_auto_cooldown_' . $slug;
			$last_created = (int) get_transient( $cooldown_key );

			if ( $last_created > 0 ) {
				return;
			}

			// Create the incident.
			$registry = WP_MCP_AI_Service_Status_Registry::get_instance();
			$sources  = $registry->get_sources();
			$source   = $sources[ $slug ] ?? null;
			$name     = $source ? $source->get_name() : $slug;

			$post_id = wp_insert_post(
				array(
					'post_type'   => self::POST_TYPE,
					'post_title'  => sprintf(
						/* translators: %s: component name */
						__( '%s Outage Detected', 'mcp-ai-wpoos-pro' ),
						$name
					),
					'post_status' => 'publish',
					'meta_input'  => array(
						'_mcp_ai_incident_phase'    => self::PHASE_DETECTED,
						'_mcp_ai_incident_severity' => self::SEVERITY_MAJOR,
						'_mcp_ai_incident_services' => array( $slug ),
						'_mcp_ai_incident_timeline' => array(
							array(
								'timestamp'   => time(),
								'phase'       => self::PHASE_DETECTED,
								'message'     => sprintf(
									/* translators: %s: component name */
									__( '%s has been automatically detected as experiencing a major outage. Health check status changed to major_outage.', 'mcp-ai-wpoos-pro' ),
									$name
								),
								'operator_id' => 0,
							),
						),
					),
				),
				true
			);

			if ( ! is_wp_error( $post_id ) ) {
				/** Fires when an incident is created. @since 1.4.0 */
				do_action(
					'wp_mcp_ai_incident_created',
					$post_id,
					array(
						'auto_created' => true,
						'component'    => $slug,
					)
				);
			}

			// Set cooldown.
			set_transient( $cooldown_key, time(), self::AUTO_CREATE_COOLDOWN );
		}
	}

	// Bootstrap.
	WP_MCP_AI_Incident_CPT::init();
}
