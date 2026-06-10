<?php
/**
 * Prune CRM Messages Tool — Sheds old, spam, and low-value messages
 * from the CRM lead database based on configurable retention rules.
 *
 * Designed for WordPress plugin environments where database bloat from
 * imported emails must be managed.  Performs safe, auditable cleanup:
 *
 *   - Remove leads classified as spam (never engaged)
 *   - Archive stale leads beyond retention age that never progressed
 *   - Remove leads from excluded domains/addresses
 *   - Dry-run mode for preview without deletion
 *
 * Industry-standard recommendation (Google/Yahoo 2024-2025):
 * remove contacts with no engagement after 90–180 days.
 *
 * @package   WP_MCP_AI_Pro
 * @subpackage CRM_Toolkit
 * @since     2.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Prunes stale, spam, and excluded leads from the CRM.
 *
 * @since 2.8.0
 */
class WP_MCP_AI_Tool_Prune_CRM_Messages implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public static function is_available() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_crm_toolkit'] );
	}

	/**
	 * {@inheritdoc}
	 */
	public static function get_unavailable_reason() {
		return __( 'CRM Toolkit required.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'prune_crm_messages';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Prune CRM Messages', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Shed old, spam, and low-value messages from the CRM lead database. Supports pruning by: spam classification, age (stale leads), excluded domains, and never-engaged contacts. Dry-run mode previews what would be deleted without making changes. Industry recommendation: remove unengaged after 90–180 days.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'dry_run'            => array(
					'type'        => 'boolean',
					'description' => __( 'If true, preview what would be pruned without actually deleting anything.', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'prune_spam'         => array(
					'type'        => 'boolean',
					'description' => __( 'Remove leads flagged as spam (is_spam meta = 1).', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'prune_excluded'     => array(
					'type'        => 'boolean',
					'description' => __( 'Remove leads whose email domain matches the exclude list.', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'prune_stale_days'   => array(
					'type'        => 'integer',
					'description' => __( 'Remove leads older than this many days that are still in the "lead" lifecycle stage (never progressed). 0 = skip stale pruning. Industry recommendation: 90–180.', 'mcp-ai-wpoos-pro' ),
					'default'     => 0,
					'minimum'     => 0,
					'maximum'     => 730,
				),
				'prune_never_engaged' => array(
					'type'        => 'boolean',
					'description' => __( 'Remove leads with zero associated activities and zero deals.', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'max_prune'          => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of leads to prune in a single run (safety limit).', 'mcp-ai-wpoos-pro' ),
					'default'     => 100,
					'minimum'     => 1,
					'maximum'     => 500,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function requires_base_pro() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		if ( class_exists( 'WP_MCP_AI_CRM_Capabilities' ) ) {
			$map = WP_MCP_AI_CRM_Capabilities::get_map();
			return isset( $map['delete_lead'] ) ? $map['delete_lead'] : 'manage_options';
		}
		return 'manage_options';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'database-write',
			'destructive',
			'requires-capability',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'crm',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'sales_ops', 'crm_viewer' ),
			'risk_level'            => 'high',
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'unavailable', self::get_unavailable_reason(), array( 'status' => 403 ) );
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, $this->get_required_capability() ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ), array( 'status' => 403 ) );
		}

		// --- Gate 1: Sanitise at entry ---

		$dry_run            = isset( $arguments['dry_run'] ) ? (bool) $arguments['dry_run'] : true;
		$prune_spam         = isset( $arguments['prune_spam'] ) ? (bool) $arguments['prune_spam'] : true;
		$prune_excluded     = isset( $arguments['prune_excluded'] ) ? (bool) $arguments['prune_excluded'] : true;
		$prune_stale_days   = isset( $arguments['prune_stale_days'] ) ? absint( $arguments['prune_stale_days'] ) : 0;
		$prune_stale_days   = max( 0, min( 730, $prune_stale_days ) );
		$prune_never_engaged = isset( $arguments['prune_never_engaged'] ) ? (bool) $arguments['prune_never_engaged'] : false;
		$max_prune          = isset( $arguments['max_prune'] ) ? absint( $arguments['max_prune'] ) : 100;
		$max_prune          = min( 500, max( 1, $max_prune ) );

		// Load hygiene settings for exclude list.
		$hygiene = class_exists( 'WP_MCP_AI_CRM_Engine' )
			? WP_MCP_AI_CRM_Engine::get_hygiene_settings()
			: array();

		$stats = array(
			'dry_run'           => $dry_run,
			'spam_removed'      => 0,
			'excluded_removed'  => 0,
			'stale_removed'     => 0,
			'never_engaged_removed' => 0,
			'total_removed'     => 0,
			'errors'            => 0,
			'pruned_ids'        => array(),
			'details'           => array(),
		);

		$all_prune_ids = array();

		// --- Pass 1: Spam leads ---
		if ( $prune_spam ) {
			$spam_ids = $this->find_spam_leads( $max_prune - count( $all_prune_ids ) );
			foreach ( $spam_ids as $id ) {
				$all_prune_ids[]        = $id;
				$stats['details'][]     = array(
					'id'     => $id,
					'reason' => 'spam',
					'title'  => get_the_title( $id ),
				);
			}
			$stats['spam_removed'] = count( $spam_ids );
		}

		// --- Pass 2: Excluded domain leads ---
		if ( $prune_excluded && count( $all_prune_ids ) < $max_prune ) {
			$excluded_ids = $this->find_excluded_leads( $hygiene, $max_prune - count( $all_prune_ids ) );
			foreach ( $excluded_ids as $id ) {
				if ( ! in_array( $id, $all_prune_ids, true ) ) {
					$all_prune_ids[]    = $id;
					$stats['details'][] = array(
						'id'     => $id,
						'reason' => 'excluded_domain',
						'title'  => get_the_title( $id ),
					);
				}
			}
			$stats['excluded_removed'] = count( $excluded_ids );
		}

		// --- Pass 3: Stale leads ---
		if ( $prune_stale_days > 0 && count( $all_prune_ids ) < $max_prune ) {
			$stale_ids = $this->find_stale_leads( $prune_stale_days, $max_prune - count( $all_prune_ids ) );
			foreach ( $stale_ids as $id ) {
				if ( ! in_array( $id, $all_prune_ids, true ) ) {
					$all_prune_ids[]    = $id;
					$stats['details'][] = array(
						'id'     => $id,
						'reason' => 'stale_' . $prune_stale_days . 'd',
						'title'  => get_the_title( $id ),
					);
				}
			}
			$stats['stale_removed'] = count( $stale_ids );
		}

		// --- Pass 4: Never-engaged leads ---
		if ( $prune_never_engaged && count( $all_prune_ids ) < $max_prune ) {
			$never_engaged_ids = $this->find_never_engaged_leads( $max_prune - count( $all_prune_ids ) );
			foreach ( $never_engaged_ids as $id ) {
				if ( ! in_array( $id, $all_prune_ids, true ) ) {
					$all_prune_ids[]    = $id;
					$stats['details'][] = array(
						'id'     => $id,
						'reason' => 'never_engaged',
						'title'  => get_the_title( $id ),
					);
				}
			}
			$stats['never_engaged_removed'] = count( $never_engaged_ids );
		}

		$stats['total_removed'] = count( $all_prune_ids );
		$stats['pruned_ids']    = $all_prune_ids;

		// --- Execute deletion (unless dry run) ---
		if ( ! $dry_run && ! empty( $all_prune_ids ) ) {
			foreach ( $all_prune_ids as $prune_id ) {
				$deleted = wp_delete_post( $prune_id, true ); // Force delete, skip trash.
				if ( ! $deleted ) {
					++$stats['errors'];
				}
			}

			// Audit.
			if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
				WP_MCP_AI_CRM_Audit::record(
					'crm_messages_pruned',
					'lead',
					'',
					array(
						'count'         => count( $all_prune_ids ),
						'spam'          => $stats['spam_removed'],
						'excluded'      => $stats['excluded_removed'],
						'stale'         => $stats['stale_removed'],
						'never_engaged' => $stats['never_engaged_removed'],
						'dry_run'       => false,
					)
				);
			}
		}

		// --- Gate 2: Escape at exit ---
		$action_word = $dry_run ? __( 'would be pruned', 'mcp-ai-wpoos-pro' ) : __( 'pruned', 'mcp-ai-wpoos-pro' );

		return $this->format_success_response(
			sprintf(
				/* translators: 1: count, 2: action word */
				__( '%1$d leads %2$s.', 'mcp-ai-wpoos-pro' ),
				$stats['total_removed'],
				$action_word
			),
			$stats
		);
	}

	/**
	 * Find leads flagged as spam.
	 *
	 * @param int $limit Max results.
	 * @return int[] Lead IDs.
	 */
	private function find_spam_leads( $limit ) {
		if ( $limit <= 0 ) {
			return array();
		}

		$query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_lead',
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'   => '_is_spam',
						'value' => '1',
					),
				),
			)
		);

		return $query->posts;
	}

	/**
	 * Find leads from excluded domains/addresses.
	 *
	 * @param array $hygiene Hygiene settings.
	 * @param int   $limit   Max results.
	 * @return int[] Lead IDs.
	 */
	private function find_excluded_leads( array $hygiene, $limit ) {
		if ( $limit <= 0 ) {
			return array();
		}

		$exclude_list = isset( $hygiene['exclude_list'] ) ? (array) $hygiene['exclude_list'] : array();
		if ( empty( $exclude_list ) ) {
			return array();
		}

		// Get all leads and filter in PHP (WP_Query can't do "email domain IN list").
		$query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_lead',
				'post_status'    => 'publish',
				'posts_per_page' => min( 200, $limit * 3 ), // Over-fetch for filtering.
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'orderby'        => 'date',
				'order'          => 'ASC',
			)
		);

		$prune_ids = array();
		foreach ( $query->posts as $lead_id ) {
			if ( count( $prune_ids ) >= $limit ) {
				break;
			}

			$email  = strtolower( trim( (string) get_post_meta( $lead_id, '_email', true ) ) );
			if ( empty( $email ) ) {
				continue;
			}

			$domain = strtolower( substr( strrchr( $email, '@' ), 1 ) );

			foreach ( $exclude_list as $entry ) {
				$entry = strtolower( trim( $entry ) );
				if ( '' === $entry ) {
					continue;
				}

				if ( $email === $entry ) {
					$prune_ids[] = $lead_id;
					break;
				}

				if ( 0 === strpos( $entry, '@' ) ) {
					$edomain = substr( $entry, 1 );
					if ( $domain === $edomain || false !== strpos( $domain, '.' . $edomain ) ) {
						$prune_ids[] = $lead_id;
						break;
					}
				}

				if ( '' !== $domain && false !== strpos( $domain, $entry ) ) {
					$prune_ids[] = $lead_id;
					break;
				}
			}
		}

		return $prune_ids;
	}

	/**
	 * Find stale leads that never progressed past 'lead' stage.
	 *
	 * @param int $stale_days Age threshold in days.
	 * @param int $limit      Max results.
	 * @return int[] Lead IDs.
	 */
	private function find_stale_leads( $stale_days, $limit ) {
		if ( $limit <= 0 || $stale_days <= 0 ) {
			return array();
		}

		$cutoff_date = gmdate( 'Y-m-d H:i:s', strtotime( "-{$stale_days} days" ) );

		$query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_lead',
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'orderby'        => 'date',
				'order'          => 'ASC',
				'date_query'     => array(
					array(
						'before'    => $cutoff_date,
						'inclusive' => true,
					),
				),
				'meta_query'     => array(
					'relation' => 'OR',
					array(
						'key'     => '_lifecycle_stage',
						'value'   => 'lead',
					),
					array(
						'key'     => '_lifecycle_stage',
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		return $query->posts;
	}

	/**
	 * Find leads with zero activities and zero deals.
	 *
	 * @param int $limit Max results.
	 * @return int[] Lead IDs.
	 */
	private function find_never_engaged_leads( $limit ) {
		if ( $limit <= 0 ) {
			return array();
		}

		// Fetch all leads and filter.
		$query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_lead',
				'post_status'    => 'publish',
				'posts_per_page' => min( 200, $limit * 3 ),
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'orderby'        => 'date',
				'order'          => 'ASC',
			)
		);

		$prune_ids = array();
		foreach ( $query->posts as $lead_id ) {
			if ( count( $prune_ids ) >= $limit ) {
				break;
			}

			// Check activities.
			$activity_query = new WP_Query(
				array(
					'post_type'      => 'mcp_ai_crm_activity',
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'no_found_rows'  => false,
					'meta_query'     => array(
						array(
							'key'   => '_lead_id',
							'value' => $lead_id,
							'type'  => 'NUMERIC',
						),
					),
				)
			);

			if ( $activity_query->found_posts > 0 ) {
				continue;
			}

			// Check deals.
			$deal_query = new WP_Query(
				array(
					'post_type'      => 'mcp_ai_deal',
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'no_found_rows'  => false,
					'meta_query'     => array(
						array(
							'key'   => '_lead_id',
							'value' => $lead_id,
							'type'  => 'NUMERIC',
						),
					),
				)
			);

			if ( $deal_query->found_posts > 0 ) {
				continue;
			}

			$prune_ids[] = $lead_id;
		}

		return $prune_ids;
	}
}
