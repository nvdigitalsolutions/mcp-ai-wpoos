<?php
/**
 * Merge Duplicate Leads Tool — Safely merges a duplicate lead into a
 * survivor lead, preserving all non-empty data and reassigning child
 * records (deals, activities, customers).
 *
 * Merge rules (industry-standard from HubSpot/Salesforce):
 *
 *   1. SURVIVOR WINS on conflicts — if both leads have the same field
 *      populated, the survivor's value is kept.
 *   2. VACANT-FIELD FILL — any meta field empty on the survivor but
 *      populated on the duplicate is copied over.
 *   3. CHILD REASSIGNMENT — all deals, activities, and customer records
 *      linked to the duplicate are reassigned to the survivor.
 *   4. DUPLICATE DISPOSITION — the merged-away lead is flagged with
 *      _is_merged=1 and _merged_into={survivor_id}. Optionally trashed.
 *   5. SCORE RECALC — survivor's lead_score is recalculated as the
 *      max of both scores (to preserve the best qualification).
 *   6. AUDIT TRAIL — every merge is recorded in the CRM audit ledger.
 *
 * Dry-run mode (default: true) previews the merge plan without writing.
 *
 * @package   WP_MCP_AI_Pro
 * @subpackage CRM_Toolkit
 * @since     2.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Merges duplicate leads safely.
 *
 * @since 2.8.0
 */
class WP_MCP_AI_Tool_Merge_Duplicates implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * Meta keys that are safe to merge (scalar values).
	 *
	 * @var string[]
	 */
	const MERGEABLE_META = array(
		'_email',
		'_phone',
		'_company',
		'_lifecycle_stage',
		'_lead_score',
		'_contact_owner',
		'_source',
		'_source_connection_id',
		'_budget',
		'_authority',
		'_need',
		'_timeline',
		'_industry',
		'_website',
		'_linkedin',
		'_notes',
	);

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
		return 'merge_duplicates';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Merge Duplicate Leads', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Safely merge a duplicate lead into a survivor. Fills empty fields from the duplicate, reassigns all child deals/activities/customers, flags the merged-away record, and audits the operation. Dry-run mode previews the merge plan.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'survivor_id'        => array(
					'type'        => 'integer',
					'description' => __( 'ID of the lead to keep (the survivor). This record will receive the duplicate\'s data and children.', 'mcp-ai-wpoos-pro' ),
				),
				'duplicate_id'       => array(
					'type'        => 'integer',
					'description' => __( 'ID of the lead to merge away. Its data will fill empty fields and its children will be reassigned.', 'mcp-ai-wpoos-pro' ),
				),
				'dry_run'            => array(
					'type'        => 'boolean',
					'description' => __( 'If true, preview the merge plan without making changes.', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'trash_duplicate'    => array(
					'type'        => 'boolean',
					'description' => __( 'If true, move the duplicate to trash after merging. If false, flag it as merged but keep published.', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
			),
			'required'             => array( 'survivor_id', 'duplicate_id' ),
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

		$survivor_id  = isset( $arguments['survivor_id'] ) ? absint( $arguments['survivor_id'] ) : 0;
		$duplicate_id = isset( $arguments['duplicate_id'] ) ? absint( $arguments['duplicate_id'] ) : 0;
		$dry_run      = isset( $arguments['dry_run'] ) ? (bool) $arguments['dry_run'] : true;
		$trash_dup    = isset( $arguments['trash_duplicate'] ) ? (bool) $arguments['trash_duplicate'] : false;

		// Validate IDs.
		if ( $survivor_id < 1 || $duplicate_id < 1 ) {
			return new WP_Error( 'invalid_ids', __( 'Both survivor_id and duplicate_id are required.', 'mcp-ai-wpoos-pro' ), array( 'status' => 400 ) );
		}

		if ( $survivor_id === $duplicate_id ) {
			return new WP_Error( 'same_ids', __( 'Cannot merge a lead into itself.', 'mcp-ai-wpoos-pro' ), array( 'status' => 400 ) );
		}

		// Verify both posts exist and are leads.
		$survivor_post  = get_post( $survivor_id );
		$duplicate_post = get_post( $duplicate_id );

		if ( ! $survivor_post || 'mcp_ai_lead' !== $survivor_post->post_type ) {
			return new WP_Error( 'invalid_survivor', __( 'Survivor ID does not point to a valid lead.', 'mcp-ai-wpoos-pro' ), array( 'status' => 404 ) );
		}

		if ( ! $duplicate_post || 'mcp_ai_lead' !== $duplicate_post->post_type ) {
			return new WP_Error( 'invalid_duplicate', __( 'Duplicate ID does not point to a valid lead.', 'mcp-ai-wpoos-pro' ), array( 'status' => 404 ) );
		}

		// Check if duplicate is already merged.
		if ( get_post_meta( $duplicate_id, '_is_merged', true ) ) {
			return new WP_Error( 'already_merged', __( 'This lead has already been merged into another record.', 'mcp-ai-wpoos-pro' ), array( 'status' => 409 ) );
		}

		// ── Build merge plan ──
		$plan = $this->build_merge_plan( $survivor_id, $duplicate_id );

		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		// ── Execute merge (unless dry run) ──
		if ( ! $dry_run ) {
			$exec_result = $this->execute_merge( $plan, $trash_dup );
			if ( is_wp_error( $exec_result ) ) {
				return $exec_result;
			}

			// Audit.
			if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
				WP_MCP_AI_CRM_Audit::record(
					'duplicates_merged',
					'lead',
					$survivor_id,
					array(
						'survivor_id'  => $survivor_id,
						'duplicate_id' => $duplicate_id,
						'fields_filled' => count( $plan['fields_to_copy'] ),
						'deals_moved'   => $plan['deal_count'],
						'activities_moved' => $plan['activity_count'],
						'customers_moved'  => $plan['customer_count'],
						'trashed'       => $trash_dup,
					)
				);
			}
		}

		// --- Gate 2: Escape at exit ---
		$action_word = $dry_run ? __( 'would be merged', 'mcp-ai-wpoos-pro' ) : __( 'merged', 'mcp-ai-wpoos-pro' );

		return $this->format_success_response(
			sprintf(
				/* translators: 1: duplicate ID, 2: survivor ID, 3: action word */
				__( 'Lead #%1$d %3$s into lead #%2$d.', 'mcp-ai-wpoos-pro' ),
				$duplicate_id,
				$survivor_id,
				$action_word
			),
			array(
				'survivor_id'       => $survivor_id,
				'duplicate_id'      => $duplicate_id,
				'dry_run'           => $dry_run,
				'merge_plan'        => $plan,
			)
		);
	}

	/**
	 * Build a merge plan showing what would change.
	 *
	 * @param int $survivor_id  Survivor lead ID.
	 * @param int $duplicate_id Duplicate lead ID.
	 * @return array|WP_Error
	 */
	private function build_merge_plan( $survivor_id, $duplicate_id ) {
		$plan = array(
			'survivor_title'    => get_the_title( $survivor_id ),
			'duplicate_title'   => get_the_title( $duplicate_id ),
			'fields_to_copy'    => array(),
			'fields_conflict'   => array(),
			'score_action'      => '',
			'deal_count'        => 0,
			'activity_count'    => 0,
			'customer_count'    => 0,
		);

		// ── Compare meta fields ──
		foreach ( self::MERGEABLE_META as $meta_key ) {
			$surv_value = get_post_meta( $survivor_id, $meta_key, true );
			$dup_value  = get_post_meta( $duplicate_id, $meta_key, true );

			// Normalise empty values.
			$surv_empty = ( empty( $surv_value ) && '0' !== (string) $surv_value );
			$dup_empty  = ( empty( $dup_value ) && '0' !== (string) $dup_value );

			if ( $surv_empty && ! $dup_empty ) {
				// Duplicate has data, survivor doesn't — copy over.
				$plan['fields_to_copy'][] = array(
					'key'        => $meta_key,
					'duplicate_value' => $dup_value,
				);
			} elseif ( ! $surv_empty && ! $dup_empty && $surv_value !== $dup_value ) {
				// Both have different data — survivor keeps theirs.
				$plan['fields_conflict'][] = array(
					'key'             => $meta_key,
					'survivor_value'  => $surv_value,
					'duplicate_value' => $dup_value,
					'action'          => 'kept_survivor',
				);
			}
		}

		// ── Score: take the max ──
		$surv_score = (int) get_post_meta( $survivor_id, '_lead_score', true );
		$dup_score  = (int) get_post_meta( $duplicate_id, '_lead_score', true );

		if ( $dup_score > $surv_score ) {
			$plan['score_action'] = sprintf(
				/* translators: 1: survivor score, 2: duplicate score */
				__( 'Score updated: %1$d → %2$d (max of both)', 'mcp-ai-wpoos-pro' ),
				$surv_score,
				$dup_score
			);
			// Update the fields_to_copy entry for _lead_score if present.
			foreach ( $plan['fields_to_copy'] as &$f ) {
				if ( '_lead_score' === $f['key'] ) {
					$f['duplicate_value'] = $dup_score;
				}
			}
			unset( $f );
		} else {
			$plan['score_action'] = sprintf(
				/* translators: %d: survivor score kept */
				__( 'Score kept at %d (survivor score is higher or equal).', 'mcp-ai-wpoos-pro' ),
				$surv_score
			);
		}

		// ── Count child records to reassign ──
		$plan['deal_count'] = $this->count_children( 'mcp_ai_deal', '_lead_id', $duplicate_id );
		$plan['activity_count'] = $this->count_children( 'mcp_ai_crm_activity', '_lead_id', $duplicate_id );

		// Count customers linked to duplicate.
		$customer_q = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_customer',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => false,
				'meta_query'     => array(
					array(
						'key'   => '_source_lead_id',
						'value' => $duplicate_id,
						'type'  => 'NUMERIC',
					),
				),
			)
		);
		$plan['customer_count'] = $customer_q->found_posts;

		return $plan;
	}

	/**
	 * Execute the merge — write changes to the database.
	 *
	 * @param array $plan       Merge plan from build_merge_plan().
	 * @param bool  $trash_dup  Whether to trash the duplicate.
	 * @return true|WP_Error
	 */
	private function execute_merge( array $plan, $trash_dup ) {
		$survivor_id  = $plan['survivor_id'] ?? 0;
		$duplicate_id = $plan['duplicate_id'] ?? 0;

		if ( ! $survivor_id || ! $duplicate_id ) {
			return new WP_Error( 'missing_ids', __( 'Missing survivor or duplicate ID in merge plan.', 'mcp-ai-wpoos-pro' ) );
		}

		// ── Step 1: Copy vacant fields ──
		foreach ( $plan['fields_to_copy'] as $field ) {
			update_post_meta( $survivor_id, $field['key'], $field['duplicate_value'] );
		}

		// ── Step 2: Update score to max ──
		$surv_score = (int) get_post_meta( $survivor_id, '_lead_score', true );
		$dup_score  = (int) get_post_meta( $duplicate_id, '_lead_score', true );
		if ( $dup_score > $surv_score ) {
			update_post_meta( $survivor_id, '_lead_score', $dup_score );
		}

		// ── Step 3: Reassign child deals ──
		$this->reassign_children( 'mcp_ai_deal', '_lead_id', $duplicate_id, $survivor_id );

		// ── Step 4: Reassign child activities ──
		$this->reassign_children( 'mcp_ai_crm_activity', '_lead_id', $duplicate_id, $survivor_id );

		// ── Step 5: Reassign customer records ──
		$this->reassign_children( 'mcp_ai_customer', '_source_lead_id', $duplicate_id, $survivor_id );

		// ── Step 6: Flag duplicate as merged ──
		update_post_meta( $duplicate_id, '_is_merged', '1' );
		update_post_meta( $duplicate_id, '_merged_into', $survivor_id );
		update_post_meta( $duplicate_id, '_merged_date', current_time( 'mysql' ) );

		// Append merge note to duplicate post content.
		$dup_content = get_post_field( 'post_content', $duplicate_id );
		$merge_note  = sprintf(
			/* translators: 1: date, 2: survivor ID, 3: survivor title */
			__( "\n\n--- Merged into lead #%2\$d (%3\$s) on %1\$s ---", 'mcp-ai-wpoos-pro' ),
			current_time( 'Y-m-d H:i:s' ),
			$survivor_id,
			get_the_title( $survivor_id )
		);
		wp_update_post(
			array(
				'ID'           => $duplicate_id,
				'post_content' => $dup_content . $merge_note,
			)
		);

		// Append source note to survivor.
		$surv_content  = get_post_field( 'post_content', $survivor_id );
		$source_note   = sprintf(
			/* translators: 1: date, 2: duplicate ID, 3: duplicate title */
			__( "\n\n--- Absorbed lead #%2\$d (%3\$s) on %1\$s ---", 'mcp-ai-wpoos-pro' ),
			current_time( 'Y-m-d H:i:s' ),
			$duplicate_id,
			get_the_title( $duplicate_id )
		);
		wp_update_post(
			array(
				'ID'           => $survivor_id,
				'post_content' => $surv_content . $source_note,
			)
		);

		// ── Step 7: Optionally trash duplicate ──
		if ( $trash_dup ) {
			wp_trash_post( $duplicate_id );
		}

		return true;
	}

	/**
	 * Reassign child posts from one parent to another.
	 *
	 * @param string $post_type  Child post type.
	 * @param string $meta_key   Meta key linking to parent.
	 * @param int    $from_id    Old parent ID.
	 * @param int    $to_id      New parent ID.
	 * @return void
	 */
	private function reassign_children( $post_type, $meta_key, $from_id, $to_id ) {
		$query = new WP_Query(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'any',
				'posts_per_page' => 200,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'   => $meta_key,
						'value' => $from_id,
						'type'  => 'NUMERIC',
					),
				),
			)
		);

		foreach ( $query->posts as $child_id ) {
			update_post_meta( $child_id, $meta_key, $to_id );
		}
	}

	/**
	 * Count child posts linked to a parent.
	 *
	 * @param string $post_type Child post type.
	 * @param string $meta_key  Meta key linking to parent.
	 * @param int    $parent_id Parent post ID.
	 * @return int
	 */
	private function count_children( $post_type, $meta_key, $parent_id ) {
		$query = new WP_Query(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => false,
				'meta_query'     => array(
					array(
						'key'   => $meta_key,
						'value' => $parent_id,
						'type'  => 'NUMERIC',
					),
				),
			)
		);

		return $query->found_posts;
	}
}
