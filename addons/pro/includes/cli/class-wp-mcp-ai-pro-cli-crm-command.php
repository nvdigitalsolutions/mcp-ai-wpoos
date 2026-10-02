<?php
/**
 * WP-CLI CRM commands for NV oOS Pro.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage CLI
 * @since 1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

require_once __DIR__ . '/class-wp-mcp-ai-pro-cli-base-command.php';

/**
 * Root `wp mcp-ai crm` command.
 *
 * Lists the CRM entity subcommands (lead, deal, company, customer,
 * activity, ticket). Each entity is registered as its own subcommand with
 * read-only `list` and `get` verbs.
 *
 * @since 1.3.0
 */
class WP_MCP_AI_Pro_CLI_CRM_Command extends WP_MCP_AI_Pro_CLI_Base_Command {

	/**
	 * Show the CRM entity subcommands available on this site.
	 *
	 * ## EXAMPLES
	 *
	 *     # List CRM entities.
	 *     $ wp mcp-ai crm
	 *
	 *     # List leads.
	 *     $ wp mcp-ai crm lead list
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function __invoke( $args, $assoc_args ) {
		$this->assert_pro_loaded();
		$this->assert_toolkit_enabled( 'enable_crm_toolkit', 'CRM Toolkit' );

		$entities = array(
			array(
				'entity'      => 'lead',
				'post_type'   => 'mcp_ai_lead',
				'description' => __( 'CRM lifecycle-stage lead records.', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'entity'      => 'deal',
				'post_type'   => 'mcp_ai_deal',
				'description' => __( 'CRM pipeline opportunity records.', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'entity'      => 'company',
				'post_type'   => 'mcp_ai_company',
				'description' => __( 'Company and organization records.', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'entity'      => 'customer',
				'post_type'   => 'mcp_ai_customer',
				'description' => __( 'Post-conversion customer records.', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'entity'      => 'activity',
				'post_type'   => 'mcp_ai_crm_activity',
				'description' => __( 'Sales activities: calls, emails, meetings, tasks, notes.', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'entity'      => 'ticket',
				'post_type'   => 'mcp_ai_ticket',
				'description' => __( 'ITIL-aligned support ticket records.', 'mcp-ai-wpoos-pro' ),
			),
		);

		\WP_CLI\Utils\format_items( 'table', $entities, array( 'entity', 'post_type', 'description' ) );
		WP_CLI::log( '' );
		WP_CLI::log( __( 'Usage: wp mcp-ai crm <entity> list|get', 'mcp-ai-wpoos-pro' ) );
	}
}

/**
 * Shared list/get implementation for CRM entity subcommands.
 *
 * Concrete subcommands (lead, deal, company, customer, activity, ticket)
 * only declare their entity labels, backing post type, and meta-key list;
 * the `list` and `get` verbs are inherited and self-register through
 * WP-CLI's reflection of inherited public methods.
 *
 * @since 1.3.0
 */
abstract class WP_MCP_AI_Pro_CLI_CRM_Entity_Command extends WP_MCP_AI_Pro_CLI_Base_Command {

	/**
	 * Output formats supported by list.
	 *
	 * @var string[]
	 */
	const LIST_FORMATS = array( 'table', 'json', 'yaml', 'csv', 'ids' );

	/**
	 * Output formats supported by get.
	 *
	 * @var string[]
	 */
	const GET_FORMATS = array( 'table', 'json', 'yaml' );

	/**
	 * Singular entity label used in messages (e.g. "Lead").
	 *
	 * @var string
	 */
	protected $entity_label = '';

	/**
	 * Plural entity label used in messages (e.g. "leads").
	 *
	 * @var string
	 */
	protected $entity_label_plural = '';

	/**
	 * Backing post type slug (e.g. "mcp_ai_lead").
	 *
	 * @var string
	 */
	protected $post_type = '';

	/**
	 * Meta keys rendered by `get`.
	 *
	 * Entries are either a meta key string, or an array of candidate keys
	 * read in order until one holds a non-empty value (used for entities
	 * that store the same field under both an unprefixed and an
	 * underscore-prefixed key).
	 *
	 * @var array
	 */
	protected $meta_keys = array();

	/**
	 * Assert the backing post type is registered before querying.
	 *
	 * @return void
	 */
	protected function assert_post_type_exists() {
		if ( ! post_type_exists( $this->post_type ) ) {
			WP_CLI::error(
				sprintf(
					/* translators: 1: entity label, 2: post type slug */
					__( 'The %1$s post type (%2$s) is not registered on this site. Enable the CRM toolkit and verify the Pro addon is active.', 'mcp-ai-wpoos-pro' ),
					$this->entity_label,
					$this->post_type
				)
			);
		}
	}

	/**
	 * List records for this CRM entity.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : Filter by post status.
	 * ---
	 * default: any
	 * options:
	 *   - any
	 *   - publish
	 *   - draft
	 *   - trash
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 *   - csv
	 *   - ids
	 * ---
	 *
	 * [--fields=<fields>]
	 * : Limit the output to specific columns (comma-separated).
	 *
	 * ## EXAMPLES
	 *
	 *     # List all records for this entity.
	 *     $ wp mcp-ai crm lead list
	 *
	 *     # List only published records.
	 *     $ wp mcp-ai crm deal list --status=publish
	 *
	 *     # Export record IDs.
	 *     $ wp mcp-ai crm ticket list --format=ids
	 *
	 * @subcommand list
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function list( $args, $assoc_args ) {
		$this->assert_pro_loaded();
		$this->assert_toolkit_enabled( 'enable_crm_toolkit', 'CRM Toolkit' );
		$this->assert_post_type_exists();

		$status = \WP_CLI\Utils\get_flag_value( $assoc_args, 'status', 'any' );
		$status = in_array( $status, array( 'any', 'publish', 'draft', 'trash' ), true ) ? $status : 'any';

		$format = $this->get_format( $assoc_args );
		if ( ! in_array( $format, self::LIST_FORMATS, true ) ) {
			$format = 'table';
		}

		$fields = $this->get_fields( $assoc_args, array( 'ID', 'title', 'status', 'date' ) );

		$posts = get_posts(
			array(
				'post_type'        => $this->post_type,
				'posts_per_page'   => -1,
				'post_status'      => $status,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'no_found_rows'    => true,
				'suppress_filters' => true,
			)
		);

		if ( empty( $posts ) ) {
			/* translators: %s: plural entity label */
			WP_CLI::log( sprintf( __( 'No %s found.', 'mcp-ai-wpoos-pro' ), $this->entity_label_plural ) );
			return;
		}

		$items = array();
		foreach ( $posts as $post ) {
			$items[] = array(
				'ID'     => $post->ID,
				'title'  => $post->post_title,
				'status' => $post->post_status,
				'date'   => $post->post_date,
			);
		}

		\WP_CLI\Utils\format_items( $format, $items, $fields );
	}

	/**
	 * Get details for a single record of this CRM entity.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The record post ID.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Get lead 42.
	 *     $ wp mcp-ai crm lead get 42
	 *
	 *     # Get deal 7 as JSON.
	 *     $ wp mcp-ai crm deal get 7 --format=json
	 *
	 * @subcommand get
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function get( $args, $assoc_args ) {
		$this->assert_pro_loaded();
		$this->assert_toolkit_enabled( 'enable_crm_toolkit', 'CRM Toolkit' );
		$this->assert_post_type_exists();

		$id = isset( $args[0] ) ? absint( $args[0] ) : 0;

		$format = $this->get_format( $assoc_args );
		if ( ! in_array( $format, self::GET_FORMATS, true ) ) {
			$format = 'table';
		}

		if ( ! $id ) {
			/* translators: %s: singular entity label */
			WP_CLI::error( sprintf( __( 'Please provide a valid %s ID.', 'mcp-ai-wpoos-pro' ), $this->entity_label ) );
		}

		$post = get_post( $id );

		if ( ! $post || $this->post_type !== $post->post_type ) {
			/* translators: 1: singular entity label, 2: entity ID */
			WP_CLI::error( sprintf( __( '%1$s %2$d not found.', 'mcp-ai-wpoos-pro' ), $this->entity_label, $id ) );
		}

		$data = array(
			'ID'      => $post->ID,
			'title'   => $post->post_title,
			'status'  => $post->post_status,
			'created' => $post->post_date,
			'updated' => $post->post_modified,
		);

		foreach ( $this->meta_keys as $meta_key ) {
			if ( is_array( $meta_key ) ) {
				$label = (string) reset( $meta_key );
				$value = '';
				foreach ( $meta_key as $candidate ) {
					$candidate_value = get_post_meta( $id, (string) $candidate, true );
					if ( '' !== $candidate_value && false !== $candidate_value ) {
						$value = $candidate_value;
						break;
					}
				}
			} else {
				$label = (string) $meta_key;
				$value = get_post_meta( $id, $label, true );
			}

			if ( '' !== $value && false !== $value ) {
				$data[ $label ] = $value;
			}
		}

		if ( '' !== $post->post_content ) {
			$data['content'] = $post->post_content;
		}

		$rows = array();
		foreach ( $data as $key => $value ) {
			$rows[] = array(
				'field' => $key,
				'value' => is_scalar( $value ) ? (string) $value : wp_json_encode( $value ),
			);
		}

		\WP_CLI\Utils\format_items( $format, $rows, array( 'field', 'value' ) );
	}
}

/**
 * WP-CLI subcommand for CRM leads (mcp_ai_lead).
 *
 * @since 1.3.0
 */
class WP_MCP_AI_Pro_CLI_CRM_Lead_Command extends WP_MCP_AI_Pro_CLI_CRM_Entity_Command {

	/**
	 * Singular entity label.
	 *
	 * @var string
	 */
	protected $entity_label = 'Lead';

	/**
	 * Plural entity label.
	 *
	 * @var string
	 */
	protected $entity_label_plural = 'leads';

	/**
	 * Backing post type slug.
	 *
	 * @var string
	 */
	protected $post_type = 'mcp_ai_lead';

	/**
	 * Meta keys rendered by get.
	 *
	 * @var array
	 */
	protected $meta_keys = array(
		'email',
		'phone',
		'first_name',
		'last_name',
		'company_name',
		'job_title',
		'lead_status',
		'lifecycle_stage',
		'lead_score',
		'contact_owner',
		'source',
		'budget',
		'authority',
		'need',
		'timeline',
		'notes',
		'score_factors',
		'_source_message_id',
		'_source_connection_id',
	);
}

/**
 * WP-CLI subcommand for CRM deals (mcp_ai_deal).
 *
 * @since 1.3.0
 */
class WP_MCP_AI_Pro_CLI_CRM_Deal_Command extends WP_MCP_AI_Pro_CLI_CRM_Entity_Command {

	/**
	 * Singular entity label.
	 *
	 * @var string
	 */
	protected $entity_label = 'Deal';

	/**
	 * Plural entity label.
	 *
	 * @var string
	 */
	protected $entity_label_plural = 'deals';

	/**
	 * Backing post type slug.
	 *
	 * @var string
	 */
	protected $post_type = 'mcp_ai_deal';

	/**
	 * Meta keys rendered by get.
	 *
	 * Fields are stored under both unprefixed and underscore-prefixed keys,
	 * so each entry lists candidate keys read in order.
	 *
	 * @var array
	 */
	protected $meta_keys = array(
		array( 'deal_stage', '_deal_stage' ),
		array( 'deal_amount', '_deal_amount' ),
		array( 'deal_probability', '_deal_probability' ),
		array( 'deal_source', '_deal_source' ),
		array( 'close_date', '_deal_close_date' ),
		array( 'deal_owner', '_deal_owner' ),
	);
}

/**
 * WP-CLI subcommand for CRM companies (mcp_ai_company).
 *
 * @since 1.3.0
 */
class WP_MCP_AI_Pro_CLI_CRM_Company_Command extends WP_MCP_AI_Pro_CLI_CRM_Entity_Command {

	/**
	 * Singular entity label.
	 *
	 * @var string
	 */
	protected $entity_label = 'Company';

	/**
	 * Plural entity label.
	 *
	 * @var string
	 */
	protected $entity_label_plural = 'companies';

	/**
	 * Backing post type slug.
	 *
	 * @var string
	 */
	protected $post_type = 'mcp_ai_company';

	/**
	 * Meta keys rendered by get.
	 *
	 * @var array
	 */
	protected $meta_keys = array(
		'_company_industry',
		'_company_size',
		'_company_city',
		'_company_state',
		'_company_country',
		'_company_target_status',
		'_company_contacts',
	);
}

/**
 * WP-CLI subcommand for CRM customers (mcp_ai_customer).
 *
 * @since 1.3.0
 */
class WP_MCP_AI_Pro_CLI_CRM_Customer_Command extends WP_MCP_AI_Pro_CLI_CRM_Entity_Command {

	/**
	 * Singular entity label.
	 *
	 * @var string
	 */
	protected $entity_label = 'Customer';

	/**
	 * Plural entity label.
	 *
	 * @var string
	 */
	protected $entity_label_plural = 'customers';

	/**
	 * Backing post type slug.
	 *
	 * @var string
	 */
	protected $post_type = 'mcp_ai_customer';

	/**
	 * Meta keys rendered by get.
	 *
	 * @var array
	 */
	protected $meta_keys = array(
		'email',
		'first_name',
		'last_name',
		'phone',
		'company_name',
		'job_title',
		'lifecycle_stage',
		'contact_owner',
		'source_lead_id',
		'source',
		'total_revenue',
		'lifetime_value',
		'customer_since',
		'currency',
		'tags',
		'notes',
	);
}

/**
 * WP-CLI subcommand for CRM activities (mcp_ai_crm_activity).
 *
 * @since 1.3.0
 */
class WP_MCP_AI_Pro_CLI_CRM_Activity_Command extends WP_MCP_AI_Pro_CLI_CRM_Entity_Command {

	/**
	 * Singular entity label.
	 *
	 * @var string
	 */
	protected $entity_label = 'Activity';

	/**
	 * Plural entity label.
	 *
	 * @var string
	 */
	protected $entity_label_plural = 'activities';

	/**
	 * Backing post type slug.
	 *
	 * @var string
	 */
	protected $post_type = 'mcp_ai_crm_activity';

	/**
	 * Meta keys rendered by get.
	 *
	 * @var array
	 */
	protected $meta_keys = array(
		'activity_type',
		'related_type',
		'related_id',
		'due_date',
		'disposition',
	);
}

/**
 * WP-CLI subcommand for CRM support tickets (mcp_ai_ticket).
 *
 * @since 1.3.0
 */
class WP_MCP_AI_Pro_CLI_CRM_Ticket_Command extends WP_MCP_AI_Pro_CLI_CRM_Entity_Command {

	/**
	 * Singular entity label.
	 *
	 * @var string
	 */
	protected $entity_label = 'Ticket';

	/**
	 * Plural entity label.
	 *
	 * @var string
	 */
	protected $entity_label_plural = 'tickets';

	/**
	 * Backing post type slug.
	 *
	 * @var string
	 */
	protected $post_type = 'mcp_ai_ticket';

	/**
	 * Meta keys rendered by get.
	 *
	 * @var array
	 */
	protected $meta_keys = array(
		'_ticket_status',
		'_ticket_priority',
		'_ticket_source',
		'_ticket_category',
		'_ticket_contact_id',
		'_ticket_assignee_id',
		'_ticket_tags',
		'_ticket_parent_id',
		'_ticket_sla_status',
		'_ticket_sla_first_response_by',
		'_ticket_sla_resolution_by',
		'_ticket_sla_first_response_at',
		'_ticket_sla_resolved_at',
		'_ticket_sla_paused_at',
		'_ticket_sla_total_paused_secs',
		'_ticket_resolution_type',
		'_ticket_resolution_note',
		'_ticket_closed_by',
		'_ticket_closed_at',
		'_ticket_reopened_count',
	);
}

// Register commands.
if ( class_exists( 'WP_CLI' ) ) {
	WP_CLI::add_command( 'mcp-ai crm', 'WP_MCP_AI_Pro_CLI_CRM_Command' );
	WP_CLI::add_command( 'mcp-ai crm lead', 'WP_MCP_AI_Pro_CLI_CRM_Lead_Command' );
	WP_CLI::add_command( 'mcp-ai crm deal', 'WP_MCP_AI_Pro_CLI_CRM_Deal_Command' );
	WP_CLI::add_command( 'mcp-ai crm company', 'WP_MCP_AI_Pro_CLI_CRM_Company_Command' );
	WP_CLI::add_command( 'mcp-ai crm customer', 'WP_MCP_AI_Pro_CLI_CRM_Customer_Command' );
	WP_CLI::add_command( 'mcp-ai crm activity', 'WP_MCP_AI_Pro_CLI_CRM_Activity_Command' );
	WP_CLI::add_command( 'mcp-ai crm ticket', 'WP_MCP_AI_Pro_CLI_CRM_Ticket_Command' );
}
