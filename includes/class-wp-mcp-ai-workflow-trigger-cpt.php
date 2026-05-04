<?php
/**
 * Workflow Trigger CPT.
 *
 * Registers the `mcp_ai_trigger` custom post type and provides static helpers
 * for registering post meta and hooking enabled trigger posts into WordPress events.
 *
 * @package WP_MCP_AI
 * @since   2.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages the `mcp_ai_trigger` CPT.
 *
 * @since 2.2.0
 */
class WP_MCP_AI_Workflow_Trigger_CPT {

	/**
	 * CPT slug.
	 */
	const CPT = 'mcp_ai_trigger';

	// ── CPT / meta registration ───────────────────────────────────────────────

	/**
	 * Register the `mcp_ai_trigger` CPT.
	 *
	 * Hooked to `init` at priority 8.
	 *
	 * @return void
	 */
	public static function register_cpt() {
		register_post_type(
			self::CPT,
			array(
				'label'              => __( 'Workflow Triggers', 'mcp-ai-wpoos' ),
				'labels'             => array(
					'name'          => __( 'Workflow Triggers', 'mcp-ai-wpoos' ),
					'singular_name' => __( 'Workflow Trigger', 'mcp-ai-wpoos' ),
				),
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => false,
				'show_in_menu'       => false,
				'show_in_rest'       => false,
				'query_var'          => false,
				'rewrite'            => false,
				'capability_type'    => 'post',
				'capabilities'       => array(
					'edit_post'          => 'manage_options',
					'read_post'          => 'manage_options',
					'delete_post'        => 'manage_options',
					'edit_posts'         => 'manage_options',
					'edit_others_posts'  => 'manage_options',
					'publish_posts'      => 'manage_options',
					'read_private_posts' => 'manage_options',
				),
				'map_meta_cap'       => false,
				'supports'           => array( 'title' ),
				'has_archive'        => false,
			)
		);
	}

	/**
	 * Register post meta for `mcp_ai_trigger`.
	 *
	 * Hooked to `init` at priority 8.
	 *
	 * @return void
	 */
	public static function register_meta() {
		$fields = array(
			'_wp_mcp_ai_trigger_type'          => array(
				'type'    => 'string',
				'single'  => true,
				'default' => '',
			),
			'_wp_mcp_ai_trigger_config'        => array(
				'type'    => 'string',
				'single'  => true,
				'default' => '',
			),
			'_wp_mcp_ai_trigger_workflow_id'   => array(
				'type'    => 'integer',
				'single'  => true,
				'default' => 0,
			),
			'_wp_mcp_ai_trigger_enabled'       => array(
				'type'    => 'boolean',
				'single'  => true,
				'default' => true,
			),
			'_wp_mcp_ai_trigger_last_fired_at' => array(
				'type'    => 'integer',
				'single'  => true,
				'default' => 0,
			),
		);

		foreach ( $fields as $key => $args ) {
			register_post_meta(
				self::CPT,
				$key,
				array(
					'type'         => $args['type'],
					'single'       => $args['single'],
					'default'      => $args['default'],
					'show_in_rest' => false,
				)
			);
		}
	}

	/**
	 * Read all enabled trigger posts and hook each one into the appropriate WordPress event.
	 *
	 * Hooked to `init` at priority 20.
	 *
	 * @return void
	 */
	public static function register_all_triggers() {
		$posts = get_posts(
			array(
				'post_type'      => self::CPT,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'meta_query'     => array(
					array(
						'key'   => '_wp_mcp_ai_trigger_enabled',
						'value' => '1',
					),
				),
			)
		);

		foreach ( $posts as $post ) {
			$type        = get_post_meta( $post->ID, '_wp_mcp_ai_trigger_type', true );
			$config_json = get_post_meta( $post->ID, '_wp_mcp_ai_trigger_config', true );
			$config      = ! empty( $config_json ) ? json_decode( $config_json, true ) : array();
			$workflow_id = (int) get_post_meta( $post->ID, '_wp_mcp_ai_trigger_workflow_id', true );

			if ( empty( $type ) || empty( $workflow_id ) ) {
				continue;
			}

			self::hook_trigger( $post->ID, $type, $config, $workflow_id );
		}
	}

	/**
	 * Hook a single trigger post into the WordPress event system.
	 *
	 * @param int    $trigger_id  Post ID of the trigger.
	 * @param string $type        Trigger type key.
	 * @param array  $config      Type-specific configuration.
	 * @param int    $workflow_id Target workflow post ID.
	 * @return void
	 */
	private static function hook_trigger( $trigger_id, $type, array $config, $workflow_id ) {
		switch ( $type ) {
			case 'post_status_change':
				$post_type   = ! empty( $config['post_type'] ) ? sanitize_key( $config['post_type'] ) : 'post';
				$from_status = ! empty( $config['from_status'] ) ? sanitize_key( $config['from_status'] ) : '*';
				$to_status   = ! empty( $config['to_status'] ) ? sanitize_key( $config['to_status'] ) : '*';

				add_action(
					'transition_post_status',
					function ( $new_status, $old_status, $post ) use ( $trigger_id, $post_type, $from_status, $to_status, $workflow_id ) {
						if ( $post->post_type !== $post_type ) {
							return;
						}
						if ( '*' !== $from_status && $old_status !== $from_status ) {
							return;
						}
						if ( '*' !== $to_status && $new_status !== $to_status ) {
							return;
						}
						self::fire_trigger(
							$trigger_id,
							$workflow_id,
							array(
								'post_id'    => $post->ID,
								'new_status' => $new_status,
								'old_status' => $old_status,
							)
						);
					},
					10,
					3
				);
				break;

			case 'cron_schedule':
				$schedule = ! empty( $config['schedule'] ) ? sanitize_key( $config['schedule'] ) : 'daily';
				$hook     = 'wp_mcp_ai_trigger_cron_' . $trigger_id;

				if ( ! wp_next_scheduled( $hook ) ) {
					wp_schedule_event( time(), $schedule, $hook );
				}

				add_action(
					$hook,
					function () use ( $trigger_id, $workflow_id ) {
						self::fire_trigger( $trigger_id, $workflow_id, array() );
					}
				);
				break;

			case 'a2a_inbound':
				add_action(
					'wp_mcp_ai_a2a_message_received',
					function ( $message ) use ( $trigger_id, $workflow_id ) {
						self::fire_trigger( $trigger_id, $workflow_id, array( 'message' => $message ) );
					}
				);
				break;

			case 'user_registration':
				add_action(
					'user_register',
					function ( $user_id ) use ( $trigger_id, $workflow_id ) {
						self::fire_trigger( $trigger_id, $workflow_id, array( 'user_id' => $user_id ) );
					}
				);
				break;

			case 'comment_published':
				add_action(
					'comment_post',
					function ( $comment_id, $comment_approved ) use ( $trigger_id, $workflow_id ) {
						if ( 1 !== (int) $comment_approved ) {
							return;
						}
						self::fire_trigger( $trigger_id, $workflow_id, array( 'comment_id' => $comment_id ) );
					},
					10,
					2
				);
				break;

			case 'file_upload':
				add_action(
					'add_attachment',
					function ( $attachment_id ) use ( $trigger_id, $workflow_id ) {
						self::fire_trigger( $trigger_id, $workflow_id, array( 'attachment_id' => $attachment_id ) );
					}
				);
				break;
		}
	}

	/**
	 * Fire a trigger — record the event and schedule/run the target workflow.
	 *
	 * @param int   $trigger_id  Trigger post ID.
	 * @param int   $workflow_id Target workflow post ID.
	 * @param array $payload     Contextual data for the workflow run.
	 * @return void
	 */
	public static function fire_trigger( $trigger_id, $workflow_id, array $payload = array() ) {
		// Update last-fired timestamp.
		update_post_meta( $trigger_id, '_wp_mcp_ai_trigger_last_fired_at', time() );

		/**
		 * Fires when a workflow trigger activates.
		 *
		 * @since 2.2.0
		 *
		 * @param int   $trigger_id  Trigger post ID.
		 * @param int   $workflow_id Target workflow post ID.
		 * @param array $payload     Contextual data.
		 */
		do_action( 'wp_mcp_ai_trigger_fired', $trigger_id, $workflow_id, $payload );

		// Hand off to the pluggable dispatcher (Engine V2 by default; Pro and
		// third-party executors can register via wp_mcp_ai_workflow_executor).
		if ( class_exists( 'WP_MCP_AI_Workflow_Dispatcher' ) ) {
			WP_MCP_AI_Workflow_Dispatcher::dispatch(
				$workflow_id,
				$payload,
				array(
					'source'     => 'trigger',
					'trigger_id' => $trigger_id,
				)
			);
			return;
		}

		// Fallback for environments where the dispatcher is unavailable.
		if ( class_exists( 'WP_MCP_AI_Workflow_Engine_V2' ) ) {
			$engine = new WP_MCP_AI_Workflow_Engine_V2();
			$engine->run( $workflow_id, $payload );
		}
	}
}
