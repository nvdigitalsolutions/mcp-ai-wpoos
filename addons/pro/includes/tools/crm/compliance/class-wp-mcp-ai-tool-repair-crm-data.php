<?php
/**
 * Repair CRM Data Tool — Detects and fixes common data quality issues in
 * imported lead and activity records.
 *
 * Addresses three classes of problem that accumulate from the Gmail import
 * pipeline and automated CRM workflows:
 *
 *   1. BROKEN DATES — due_date meta set to "1970-01-01" (Unix epoch from
 *      empty-date defaults), dates before year 2000, or far-future dates.
 *
 *   2. GENERIC LEAD TITLES — Leads whose post_title is "Inbound Lead",
 *      a raw email address, or an impersonal company name (e.g. "The
 *      Google Workspace Team") instead of an actual contact name.
 *
 *   3. GENERIC ACTIVITY TITLES — Activities with auto-generated titles
 *      like "Follow up with lead #15417" where the lead ID reference
 *      is less useful than the lead's actual name/company.
 *
 * Dry-run mode (default: true) previews all repairs without writing.
 *
 * @package   WP_MCP_AI_Pro
 * @subpackage CRM_Toolkit
 * @since     2.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Repairs common CRM data quality issues.
 *
 * @since 2.8.0
 */
class WP_MCP_AI_Tool_Repair_CRM_Data implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
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
		return 'repair_crm_data';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Repair CRM Data', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Detect and fix common data quality issues in imported CRM records: broken dates (1970-01-01 epoch defaults, far-future dates), generic lead titles (raw emails, impersonal company names), and auto-generated activity titles. Dry-run mode previews all repairs safely.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'dry_run'                => array(
					'type'        => 'boolean',
					'description' => __( 'If true, preview repairs without writing changes.', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'repair_broken_dates'    => array(
					'type'        => 'boolean',
					'description' => __( 'Fix due_date meta that is 1970-01-01, before year 2000, or far-future (clears them to empty).', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'repair_lead_titles'     => array(
					'type'        => 'boolean',
					'description' => __( 'Fix leads with generic titles (Inbound Lead, raw email, impersonal company names). Enriches from email address pattern when possible.', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'repair_activity_titles' => array(
					'type'        => 'boolean',
					'description' => __( 'Fix activities with auto-generated titles like "Follow up with lead #XXXXX". Enriches from related lead data.', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'repair_sender_names'    => array(
					'type'        => 'boolean',
					'description' => __( 'Fix leads whose title is an impersonal sender name (e.g. company team names). Attempts to extract a real contact name from email or body content.', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'max_repairs'            => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of records to repair per category.', 'mcp-ai-wpoos-pro' ),
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
		return 'manage_options';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'database-write',
			'repair',
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
			'risk_level'            => 'standard',
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

		$dry_run                = isset( $arguments['dry_run'] ) ? (bool) $arguments['dry_run'] : true;
		$repair_broken_dates    = isset( $arguments['repair_broken_dates'] ) ? (bool) $arguments['repair_broken_dates'] : true;
		$repair_lead_titles     = isset( $arguments['repair_lead_titles'] ) ? (bool) $arguments['repair_lead_titles'] : true;
		$repair_activity_titles = isset( $arguments['repair_activity_titles'] ) ? (bool) $arguments['repair_activity_titles'] : true;
		$repair_sender_names    = isset( $arguments['repair_sender_names'] ) ? (bool) $arguments['repair_sender_names'] : false;
		$max_repairs            = isset( $arguments['max_repairs'] ) ? absint( $arguments['max_repairs'] ) : 100;
		$max_repairs            = min( 500, max( 1, $max_repairs ) );

		$stats = array(
			'dry_run'                     => $dry_run,
			'dates_checked'               => 0,
			'dates_fixed'                 => 0,
			'lead_titles_checked'         => 0,
			'lead_titles_fixed'           => 0,
			'activity_titles_checked'     => 0,
			'activity_titles_fixed'       => 0,
			'sender_names_fixed'          => 0,
			'total_repaired'              => 0,
			'repairs'                     => array(),
		);

		// --- Repair 1: Broken dates on activities ---
		if ( $repair_broken_dates ) {
			$date_results = $this->repair_broken_dates( $max_repairs, $dry_run );
			$stats['dates_checked']   = $date_results['checked'];
			$stats['dates_fixed']     = $date_results['fixed'];
			$stats['total_repaired'] += $date_results['fixed'];
			$stats['repairs']         = array_merge( $stats['repairs'], $date_results['repairs'] );
		}

		// --- Repair 2: Generic lead titles ---
		if ( $repair_lead_titles ) {
			$remaining = $max_repairs - $stats['total_repaired'];
			if ( $remaining > 0 ) {
				$title_results = $this->repair_lead_titles( $remaining, $dry_run );
				$stats['lead_titles_checked']   = $title_results['checked'];
				$stats['lead_titles_fixed']     = $title_results['fixed'];
				$stats['total_repaired']       += $title_results['fixed'];
				$stats['repairs']               = array_merge( $stats['repairs'], $title_results['repairs'] );
			}
		}

		// --- Repair 3: Generic activity titles ---
		if ( $repair_activity_titles ) {
			$remaining = $max_repairs - $stats['total_repaired'];
			if ( $remaining > 0 ) {
				$act_results = $this->repair_activity_titles( $remaining, $dry_run );
				$stats['activity_titles_checked']   = $act_results['checked'];
				$stats['activity_titles_fixed']     = $act_results['fixed'];
				$stats['total_repaired']           += $act_results['fixed'];
				$stats['repairs']                   = array_merge( $stats['repairs'], $act_results['repairs'] );
			}
		}

		// --- Repair 4: Impersonal sender names as lead titles ---
		if ( $repair_sender_names ) {
			$remaining = $max_repairs - $stats['total_repaired'];
			if ( $remaining > 0 ) {
				$sender_results = $this->repair_sender_names( $remaining, $dry_run );
				$stats['sender_names_fixed'] = $sender_results['fixed'];
				$stats['total_repaired']    += $sender_results['fixed'];
				$stats['repairs']            = array_merge( $stats['repairs'], $sender_results['repairs'] );
			}
		}

		// Audit.
		if ( ! $dry_run && $stats['total_repaired'] > 0 && class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
			WP_MCP_AI_CRM_Audit::record(
				'crm_data_repaired',
				'lead',
				'',
				array(
					'total'           => $stats['total_repaired'],
					'dates_fixed'     => $stats['dates_fixed'],
					'lead_titles'     => $stats['lead_titles_fixed'],
					'activity_titles' => $stats['activity_titles_fixed'],
					'sender_names'    => $stats['sender_names_fixed'],
					'dry_run'         => false,
				)
			);
		}

		// --- Gate 2: Escape at exit ---
		$action_word = $dry_run ? __( 'would be repaired', 'mcp-ai-wpoos-pro' ) : __( 'repaired', 'mcp-ai-wpoos-pro' );

		return $this->format_success_response(
			sprintf(
				/* translators: %d: number of records */
				__( '%d records %s.', 'mcp-ai-wpoos-pro' ),
				$stats['total_repaired'],
				$action_word
			),
			$stats
		);
	}

	/**
	 * Repair broken due_date meta on activities.
	 *
	 * Detects: "1970-01-01", empty string, dates before 2000-01-01,
	 * dates after 2100-01-01.
	 *
	 * Fix: deletes the meta key (not sets to empty — truly removes).
	 *
	 * @param int  $limit   Max records.
	 * @param bool $dry_run Whether to actually write.
	 * @return array{checked: int, fixed: int, repairs: array}
	 */
	private function repair_broken_dates( $limit, $dry_run ) {
		$fixed   = 0;
		$checked = 0;
		$repairs = array();

		// Query activities that have a due_date meta.
		$query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_crm_activity',
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => 'due_date', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Targeted repair scan.
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		foreach ( $query->posts as $activity_id ) {
			++$checked;
			$due_date = get_post_meta( $activity_id, 'due_date', true );

			if ( empty( $due_date ) ) {
				continue;
			}

			$timestamp  = strtotime( $due_date );
			$is_broken  = false;
			$reason     = '';

			// Empty or epoch default.
			if ( false === $timestamp || '1970-01-01' === substr( $due_date, 0, 10 ) ) {
				$is_broken = true;
				$reason    = 'epoch_default';
			} elseif ( $timestamp < strtotime( '2000-01-01' ) ) {
				$is_broken = true;
				$reason    = 'before_2000';
			} elseif ( $timestamp > strtotime( '2100-01-01' ) ) {
				$is_broken = true;
				$reason    = 'far_future';
			}

			if ( ! $is_broken ) {
				continue;
			}

			++$fixed;
			$repairs[] = array(
				'id'        => $activity_id,
				'type'      => 'broken_date',
				'reason'    => $reason,
				'old_value' => $due_date,
				'new_value' => '(removed)',
				'title'     => get_the_title( $activity_id ),
			);

			if ( ! $dry_run ) {
				delete_post_meta( $activity_id, 'due_date' );
			}

			if ( $fixed >= $limit ) {
				break;
			}
		}

		return array(
			'checked' => $checked,
			'fixed'   => $fixed,
			'repairs' => $repairs,
		);
	}

	/**
	 * Repair leads with generic or unhelpful titles.
	 *
	 * Detects:
	 *   - "Inbound Lead" (default from extract_lead_from_message)
	 *   - Raw email address as title
	 *   - Impersonal company-like names
	 *
	 * Fix: attempts to extract a human name from the email address
	 * (e.g. john.smith@company.com → "John Smith") and uses that.
	 * Falls back to email if no name can be derived.
	 *
	 * @param int  $limit   Max records.
	 * @param bool $dry_run Whether to actually write.
	 * @return array{checked: int, fixed: int, repairs: array}
	 */
	private function repair_lead_titles( $limit, $dry_run ) {
		$fixed   = 0;
		$checked = 0;
		$repairs = array();

		// Query leads with suspicious titles.
		$query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_lead',
				'post_status'    => 'publish',
				'posts_per_page' => min( 300, $limit * 3 ), // Over-fetch for filtering.
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'orderby'        => 'date',
				'order'          => 'ASC',
			)
		);

		foreach ( $query->posts as $lead_id ) {
			if ( $fixed >= $limit ) {
				break;
			}

			++$checked;
			$title       = get_the_title( $lead_id );
			$email       = strtolower( trim( (string) get_post_meta( $lead_id, '_email', true ) ) );
			$is_generic  = false;
			$reason      = '';
			$new_title   = '';

			// Check: "Inbound Lead" default.
			if ( 'Inbound Lead' === $title || 'inbound lead' === strtolower( $title ) ) {
				$is_generic = true;
				$reason     = 'default_inbound_lead';
			}

			// Check: title is an email address.
			if ( ! $is_generic && false !== strpos( $title, '@' ) && filter_var( $title, FILTER_VALIDATE_EMAIL ) ) {
				$is_generic = true;
				$reason     = 'title_is_email';
			}

			// Check: title looks like a company/team name (all caps, contains Inc/LLC/Team/Group, etc.).
			if ( ! $is_generic ) {
				$lower_title = strtolower( $title );
				$company_signals = array(
					'team', ' group', ' inc', ' llc', ' ltd', ' corp',
					'corporation', ' limited', ' department', ' support',
					' sales', ' marketing', 'admin', 'noreply', 'no-reply',
					'google workspace', 'microsoft teams',
				);

				// Strong signals: title starts with "The " + has a company word.
				if ( 0 === stripos( $title, 'The ' ) ) {
					foreach ( $company_signals as $signal ) {
						if ( false !== strpos( $lower_title, $signal ) ) {
							$is_generic = true;
							$reason     = 'impersonal_company_name';
							break;
						}
					}
				}

				// All-caps short name is likely not a real person.
				if ( ! $is_generic && strlen( $title ) > 5 && $title === strtoupper( $title ) && false === strpos( $title, ' ' ) ) {
					$is_generic = true;
					$reason     = 'all_caps_short_name';
				}
			}

			if ( ! $is_generic ) {
				continue;
			}

			// Try to derive a better title from the email address.
			if ( ! empty( $email ) && false !== strpos( $email, '@' ) ) {
				$local_part = strstr( $email, '@', true );
				$new_title  = $this->email_to_human_name( $local_part );
			}

			// Fallback: use email as title (better than "Inbound Lead").
			if ( empty( $new_title ) && ! empty( $email ) ) {
				$new_title = $email;
			}

			// If still nothing, use the company name.
			if ( empty( $new_title ) ) {
				$company = trim( (string) get_post_meta( $lead_id, '_company', true ) );
				if ( ! empty( $company ) ) {
					$new_title = $company;
				}
			}

			// Last resort: keep existing.
			if ( empty( $new_title ) ) {
				continue;
			}

			++$fixed;
			$repairs[] = array(
				'id'        => $lead_id,
				'type'      => 'lead_title',
				'reason'    => $reason,
				'old_value' => $title,
				'new_value' => $new_title,
				'email'     => $email,
			);

			if ( ! $dry_run ) {
				wp_update_post(
					array(
						'ID'         => $lead_id,
						'post_title' => sanitize_text_field( $new_title ),
					)
				);
			}
		}

		return array(
			'checked' => $checked,
			'fixed'   => $fixed,
			'repairs' => $repairs,
		);
	}

	/**
	 * Repair activities with auto-generated generic titles.
	 *
	 * Detects patterns like:
	 *   - "Follow up with lead #XXXXX"
	 *   - "Task"
	 *   - "Follow up"
	 *
	 * Fix: replaces with lead's actual name/email/company when the
	 * activity has a related lead_id meta.
	 *
	 * @param int  $limit   Max records.
	 * @param bool $dry_run Whether to actually write.
	 * @return array{checked: int, fixed: int, repairs: array}
	 */
	private function repair_activity_titles( $limit, $dry_run ) {
		$fixed   = 0;
		$checked = 0;
		$repairs = array();

		$query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_crm_activity',
				'post_status'    => 'publish',
				'posts_per_page' => min( 300, $limit * 3 ),
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'orderby'        => 'date',
				'order'          => 'ASC',
			)
		);

		// Generic title patterns to detect.
		$generic_patterns = array(
			'/^Follow up with lead #\d+$/i',
			'/^Task$/i',
			'/^Follow up$/i',
			'/^Follow-up$/i',
			'/^Call$/i',
			'/^Email$/i',
			'/^Meeting$/i',
			'/^Note$/i',
		);

		foreach ( $query->posts as $activity_id ) {
			if ( $fixed >= $limit ) {
				break;
			}

			++$checked;
			$title   = get_the_title( $activity_id );
			$is_generic = false;

			foreach ( $generic_patterns as $pattern ) {
				if ( 1 === preg_match( $pattern, $title ) ) {
					$is_generic = true;
					break;
				}
			}

			if ( ! $is_generic ) {
				continue;
			}

			// Look for related lead.
			$lead_id    = (int) get_post_meta( $activity_id, '_lead_id', true );
			$new_title  = '';

			if ( $lead_id > 0 ) {
				$lead_post = get_post( $lead_id );
				if ( $lead_post && 'publish' === $lead_post->post_status ) {
					$lead_title = get_the_title( $lead_id );
					$lead_email = get_post_meta( $lead_id, '_email', true );
					$lead_company = get_post_meta( $lead_id, '_company', true );

					// Build enriched title.
					if ( ! empty( $lead_title ) && 'Inbound Lead' !== $lead_title ) {
						$new_title = $lead_title;
					} elseif ( ! empty( $lead_company ) ) {
						$new_title = $lead_company;
					} elseif ( ! empty( $lead_email ) ) {
						$new_title = $lead_email;
					}

					// Prepend activity type if helpful.
					$activity_type = get_post_meta( $activity_id, '_activity_type', true );
					if ( ! empty( $activity_type ) && ! empty( $new_title ) ) {
						$type_labels = array(
							'call'    => __( 'Call with', 'mcp-ai-wpoos-pro' ),
							'email'   => __( 'Email to', 'mcp-ai-wpoos-pro' ),
							'meeting' => __( 'Meeting with', 'mcp-ai-wpoos-pro' ),
							'task'    => __( 'Task:', 'mcp-ai-wpoos-pro' ),
							'note'    => __( 'Note on', 'mcp-ai-wpoos-pro' ),
						);
						$prefix = isset( $type_labels[ $activity_type ] ) ? $type_labels[ $activity_type ] : '';
						if ( ! empty( $prefix ) ) {
							$new_title = $prefix . ' ' . $new_title;
						}
					}
				}
			}

			if ( empty( $new_title ) ) {
				continue;
			}

			++$fixed;
			$repairs[] = array(
				'id'        => $activity_id,
				'type'      => 'activity_title',
				'reason'    => 'generic_pattern',
				'old_value' => $title,
				'new_value' => $new_title,
				'lead_id'   => $lead_id,
			);

			if ( ! $dry_run ) {
				wp_update_post(
					array(
						'ID'         => $activity_id,
						'post_title' => sanitize_text_field( $new_title ),
					)
				);
			}
		}

		return array(
			'checked' => $checked,
			'fixed'   => $fixed,
			'repairs' => $repairs,
		);
	}

	/**
	 * Repair leads whose title is an impersonal sender name.
	 *
	 * Detects: team names, department names, "no-reply" senders,
	 * and company names used as the lead title.
	 *
	 * Fix: attempts to extract a real contact name from the email
	 * local-part or falls back to company name.
	 *
	 * @param int  $limit   Max records.
	 * @param bool $dry_run Whether to actually write.
	 * @return array{checked: int, fixed: int, repairs: array}
	 */
	private function repair_sender_names( $limit, $dry_run ) {
		$fixed   = 0;
		$repairs = array();

		$query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_lead',
				'post_status'    => 'publish',
				'posts_per_page' => min( 300, $limit * 3 ),
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'orderby'        => 'date',
				'order'          => 'ASC',
			)
		);

		$impersonal_signals = array(
			'team', 'group', 'department', 'support', 'sales',
			'marketing', 'info', 'admin', 'noreply', 'no-reply',
			'help', 'contact', 'hello', 'office', 'service',
			'billing', 'accounts', 'hr', 'careers', 'jobs',
			'newsletter', 'notifications', 'alerts',
		);

		foreach ( $query->posts as $lead_id ) {
			if ( $fixed >= $limit ) {
				break;
			}

			$title = get_the_title( $lead_id );
			$lower = strtolower( $title );
			$email = strtolower( trim( (string) get_post_meta( $lead_id, '_email', true ) ) );
			$company = trim( (string) get_post_meta( $lead_id, '_company', true ) );

			$is_impersonal = false;

			// Already generic — skip (handled by repair_lead_titles).
			if ( 'Inbound Lead' === $title || false !== strpos( $title, '@' ) ) {
				continue;
			}

			// Check if title matches known impersonal signals.
			foreach ( $impersonal_signals as $signal ) {
				if ( $lower === $signal || 0 === strpos( $lower, $signal . ' ' ) || false !== strpos( $lower, ' ' . $signal ) ) {
					$is_impersonal = true;
					break;
				}
			}

			// Also detect: "The X Team", "X Department", etc.
			if ( ! $is_impersonal && preg_match( '/^(the\s+)?\w+\s+(team|group|department|support|desk)$/i', $title ) ) {
				$is_impersonal = true;
			}

			if ( ! $is_impersonal ) {
				continue;
			}

			// Try to derive a human name from email.
			$new_title = '';
			if ( ! empty( $email ) && false !== strpos( $email, '@' ) ) {
				$local_part = strstr( $email, '@', true );
				$new_title  = $this->email_to_human_name( $local_part );
			}

			if ( empty( $new_title ) && ! empty( $company ) ) {
				$new_title = $company;
			}

			if ( empty( $new_title ) && ! empty( $email ) ) {
				$new_title = $email;
			}

			if ( empty( $new_title ) ) {
				continue;
			}

			++$fixed;
			$repairs[] = array(
				'id'        => $lead_id,
				'type'      => 'sender_name',
				'reason'    => 'impersonal_sender',
				'old_value' => $title,
				'new_value' => $new_title,
				'email'     => $email,
			);

			if ( ! $dry_run ) {
				wp_update_post(
					array(
						'ID'         => $lead_id,
						'post_title' => sanitize_text_field( $new_title ),
					)
				);
			}
		}

		return array(
			'checked' => count( $query->posts ),
			'fixed'   => $fixed,
			'repairs' => $repairs,
		);
	}

	/**
	 * Convert an email local-part to a human-readable name.
	 *
	 * Handles common patterns:
	 *   john.smith  → John Smith
	 *   john_smith  → John Smith
	 *   john-smith  → John Smith
	 *   jsmith      → Jsmith (title-cased, single segment)
	 *   john        → John
	 *
	 * @param string $local_part The part before @ in an email address.
	 * @return string Human-readable name or empty string.
	 */
	private function email_to_human_name( $local_part ) {
		$local_part = trim( $local_part );
		if ( empty( $local_part ) ) {
			return '';
		}

		// Skip if it's obviously not a name (very short, all numeric, etc.).
		if ( strlen( $local_part ) < 2 || is_numeric( $local_part ) ) {
			return '';
		}

		// Replace common separators with spaces.
		$with_spaces = str_replace( array( '.', '_', '-' ), ' ', $local_part );

		// Remove common prefixes/suffixes that aren't name parts.
		$with_spaces = preg_replace( '/\b(mr|ms|mrs|dr|prof)\.?\b/i', '', $with_spaces );

		// Title-case each segment.
		$parts  = explode( ' ', $with_spaces );
		$parts  = array_filter( $parts, function ( $p ) { return '' !== trim( $p ); } );
		$parts  = array_map( 'ucfirst', $parts );

		$name = implode( ' ', $parts );
		$name = trim( $name );

		// Don't return if it's just a single character or too short.
		if ( strlen( $name ) < 2 ) {
			return '';
		}

		return $name;
	}
}
