<?php
/**
 * Manage Email Hygiene Tool — View, add, and remove entries from the
 * CRM email exclude list and priority list.
 *
 * Provides CRUD-style management over the lists that feed into
 * classify_email_hygiene and the inbound-email import pipeline:
 *
 *   - Exclude list: email addresses / domains to ALWAYS skip
 *   - Priority list: email addresses / domains to ALWAYS fast-track
 *
 * Both lists support:
 *   - Exact email matches (e.g. spammer@example.com)
 *   - Domain patterns (e.g. @newsletters.example.com)
 *   - Substring matches against sender domain
 *
 * @package   WP_MCP_AI_Pro
 * @subpackage CRM_Toolkit
 * @since     2.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages CRM email exclude and priority lists.
 *
 * @since 2.8.0
 */
class WP_MCP_AI_Tool_Manage_Email_Hygiene implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * Hygiene settings option key.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'wp_mcp_ai_crm_hygiene_settings';

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
		return 'manage_email_hygiene';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Manage Email Hygiene', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'View, add, and remove entries from the email exclude list and priority list. Exclude entries are always skipped during import; priority entries are always fast-tracked. Supports exact emails, domain patterns (@example.com), and substring matching.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'action'             => array(
					'type'        => 'string',
					'description' => __( 'Action to perform: view, add, remove.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'view', 'add', 'remove' ),
					'default'     => 'view',
				),
				'list_type'          => array(
					'type'        => 'string',
					'description' => __( 'Which list to manage: exclude, priority.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'exclude', 'priority' ),
					'default'     => 'exclude',
				),
				'entry'              => array(
					'type'        => 'string',
					'description' => __( 'Email address or domain pattern to add/remove. Use @domain.com to match an entire domain (e.g. @newsletters.example.com, @spammy.net). Required for add and remove actions.', 'mcp-ai-wpoos-pro' ),
				),
				'reason'             => array(
					'type'        => 'string',
					'description' => __( 'Optional note explaining why this entry was added/removed. Stored for audit.', 'mcp-ai-wpoos-pro' ),
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
			return isset( $map['manage_crm_settings'] ) ? $map['manage_crm_settings'] : 'manage_options';
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
			'profession_tags'       => array( 'sales_manager', 'sales_ops', 'crm_viewer' ),
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

		$action   = isset( $arguments['action'] ) ? sanitize_key( $arguments['action'] ) : 'view';
		$list_type = isset( $arguments['list_type'] ) ? sanitize_key( $arguments['list_type'] ) : 'exclude';
		$entry    = isset( $arguments['entry'] ) ? sanitize_text_field( $arguments['entry'] ) : '';
		$reason   = isset( $arguments['reason'] ) ? sanitize_text_field( $arguments['reason'] ) : '';

		if ( ! in_array( $action, array( 'view', 'add', 'remove' ), true ) ) {
			$action = 'view';
		}

		if ( ! in_array( $list_type, array( 'exclude', 'priority' ), true ) ) {
			$list_type = 'exclude';
		}

		// Load current settings.
		$settings = class_exists( 'WP_MCP_AI_CRM_Engine' )
			? WP_MCP_AI_CRM_Engine::get_hygiene_settings()
			: get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$list_key = 'exclude' === $list_type ? 'exclude_list' : 'priority_list';
		$list     = isset( $settings[ $list_key ] ) ? (array) $settings[ $list_key ] : array();

		// Handle action.
		switch ( $action ) {
			case 'add':
				if ( empty( $entry ) ) {
					return new WP_Error(
						'missing_entry',
						__( 'An email address or domain pattern is required to add.', 'mcp-ai-wpoos-pro' ),
						array( 'status' => 400 )
					);
				}

				$entry_clean = strtolower( trim( $entry ) );

				// Validate: must be either an email or a @domain pattern.
				if ( 0 === strpos( $entry_clean, '@' ) ) {
					// Domain pattern: strip @ and validate remaining.
					$domain = substr( $entry_clean, 1 );
					if ( empty( $domain ) || false === strpos( $domain, '.' ) ) {
						return new WP_Error(
							'invalid_entry',
							__( 'Domain pattern must include a dot (e.g. @example.com).', 'mcp-ai-wpoos-pro' ),
							array( 'status' => 400 )
						);
					}
				} elseif ( false === strpos( $entry_clean, '@' ) ) {
					return new WP_Error(
						'invalid_entry',
						__( 'Entry must be a full email address or a domain pattern starting with @ (e.g. @example.com).', 'mcp-ai-wpoos-pro' ),
						array( 'status' => 400 )
					);
				}

				// Check for duplicates.
				if ( in_array( $entry_clean, $list, true ) ) {
					return $this->format_success_response(
						sprintf(
							/* translators: %s: entry */
							__( '%s is already in the %s list.', 'mcp-ai-wpoos-pro' ),
							$entry_clean,
							$list_type
						),
						array(
							'action'    => 'add',
							'list_type' => $list_type,
							'entry'     => $entry_clean,
							'duplicate' => true,
							'count'     => count( $list ),
						)
					);
				}

				$list[] = $entry_clean;
				$list   = array_unique( $list );
				sort( $list );

				$settings[ $list_key ] = $list;
				$this->save_settings( $settings );

				// Audit.
				if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
					WP_MCP_AI_CRM_Audit::record(
						'email_hygiene_entry_added',
						'hygiene',
						'',
						array(
							'list_type' => $list_type,
							'entry'     => $entry_clean,
							'reason'    => $reason,
						)
					);
				}

				return $this->format_success_response(
					sprintf(
						/* translators: 1: entry, 2: list type */
						__( '%1$s added to %2$s list.', 'mcp-ai-wpoos-pro' ),
						$entry_clean,
						$list_type
					),
					array(
						'action'    => 'add',
						'list_type' => $list_type,
						'entry'     => $entry_clean,
						'count'     => count( $list ),
						'list'      => $list,
					)
				);

			case 'remove':
				if ( empty( $entry ) ) {
					return new WP_Error(
						'missing_entry',
						__( 'An entry is required to remove.', 'mcp-ai-wpoos-pro' ),
						array( 'status' => 400 )
					);
				}

				$entry_clean = strtolower( trim( $entry ) );
				$found       = false;

				$list = array_values( array_filter(
					$list,
					function ( $item ) use ( $entry_clean, &$found ) {
						if ( strtolower( trim( $item ) ) === $entry_clean ) {
							$found = true;
							return false;
						}
						return true;
					}
				) );

				if ( ! $found ) {
					return new WP_Error(
						'not_found',
						sprintf(
							/* translators: %s: entry */
							__( '%s was not found in the %s list.', 'mcp-ai-wpoos-pro' ),
							$entry_clean,
							$list_type
						),
						array( 'status' => 404 )
					);
				}

				$settings[ $list_key ] = $list;
				$this->save_settings( $settings );

				// Audit.
				if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
					WP_MCP_AI_CRM_Audit::record(
						'email_hygiene_entry_removed',
						'hygiene',
						'',
						array(
							'list_type' => $list_type,
							'entry'     => $entry_clean,
							'reason'    => $reason,
						)
					);
				}

				return $this->format_success_response(
					sprintf(
						/* translators: 1: entry, 2: list type */
						__( '%1$s removed from %2$s list.', 'mcp-ai-wpoos-pro' ),
						$entry_clean,
						$list_type
					),
					array(
						'action'    => 'remove',
						'list_type' => $list_type,
						'entry'     => $entry_clean,
						'count'     => count( $list ),
						'list'      => $list,
					)
				);

			case 'view':
			default:
				return $this->format_success_response(
					sprintf(
						/* translators: 1: count, 2: list type */
						__( '%1$d entries in %2$s list.', 'mcp-ai-wpoos-pro' ),
						count( $list ),
						$list_type
					),
					array(
						'action'    => 'view',
						'list_type' => $list_type,
						'count'     => count( $list ),
						'list'      => $list,
					)
				);
		}
	}

	/**
	 * Save hygiene settings, respecting CRM engine cache.
	 *
	 * @param array $settings Settings array.
	 * @return void
	 */
	private function save_settings( array $settings ) {
		update_option( self::OPTION_KEY, $settings, false );

		// Flush CRM engine cache so get_hygiene_settings() picks up the change.
		if ( class_exists( 'WP_MCP_AI_CRM_Engine' ) ) {
			WP_MCP_AI_CRM_Engine::flush_settings_cache();
		}
	}
}
