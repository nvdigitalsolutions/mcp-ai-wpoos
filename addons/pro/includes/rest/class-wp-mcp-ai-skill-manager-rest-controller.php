<?php
/**
 * REST API controller for the Pro Skill Manager.
 *
 * Exposes CRUD endpoints for Agent Skills following the
 * agentskills.io specification. All endpoints are admin-only and require the
 * manage_options capability.
 *
 * Namespace : mcp-ai-pro/v1
 * Base route: /skills
 *
 * Endpoints:
 *   GET    /skills           – List all installed skills (index view).
 *   GET    /skills/{name}    – Retrieve a single skill with full SKILL.md content.
 *   POST   /skills           – Install a skill from raw SKILL.md content.
 *   POST   /skills/install-url – Fetch and install a skill from a remote URL.
 *   PUT    /skills/{name}    – Replace an existing skill's SKILL.md content.
 *   DELETE /skills/{name}    – Uninstall (remove) a skill.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.8.0
 * @see     https://agentskills.io/specification
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST controller for Pro Skill Manager operations.
 *
 * @since 1.8.0
 */
class WP_MCP_AI_Skill_Manager_REST_Controller extends WP_REST_Controller {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'mcp-ai-pro/v1';

	/**
	 * Base route for this controller.
	 *
	 * @var string
	 */
	protected $rest_base = 'skills';

	/**
	 * Constructor – registers REST routes on rest_api_init.
	 *
	 * @since 1.8.0
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register all REST routes.
	 *
	 * @since 1.8.0
	 * @return void
	 */
	public function register_routes() {
		// GET /skills | POST /skills.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'content' => array(
							'required'    => true,
							'type'        => 'string',
							'description' => __( 'Raw SKILL.md content to install.', 'mcp-ai-wpoos-pro' ),
						),
					),
				),
			)
		);

		// POST /skills/install-url – install from remote URL.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/install-url',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'install_from_url' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'url' => array(
						'required'          => true,
						'type'              => 'string',
						'format'            => 'uri',
						'sanitize_callback' => 'esc_url_raw',
						'description'       => __( 'URL of a remote SKILL.md file to fetch and install.', 'mcp-ai-wpoos-pro' ),
					),
				),
			)
		);

		// GET /skills/{name} | PUT /skills/{name} | DELETE /skills/{name}.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<name>[a-z0-9][a-z0-9-]{0,62}[a-z0-9]?)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => $this->get_item_args(),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array_merge(
						$this->get_item_args(),
						array(
							'content' => array(
								'required'    => true,
								'type'        => 'string',
								'description' => __( 'New raw SKILL.md content.', 'mcp-ai-wpoos-pro' ),
							),
						)
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => $this->get_item_args(),
				),
			)
		);
	}

	/**
	 * Permission callback – requires manage_options capability.
	 *
	 * @since 1.8.0
	 * @return bool|WP_Error True if the current user has permission.
	 */
	public function permissions_check() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to manage skills.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * GET /skills – list all installed skills.
	 *
	 * Returns a lightweight index containing name, description, license,
	 * and compatibility for each installed skill.
	 *
	 * @since 1.8.0
	 * @param WP_REST_Request $request Full request object.
	 * @return WP_REST_Response
	 */
	public function get_items( $request ) {
		unset( $request ); // Accepted for future pagination support; not used in current implementation.
		$registry = $this->get_registry();
		$skills   = $registry->get_all_skills();

		$data = array();
		foreach ( $skills as $skill ) {
			$data[] = $this->prepare_skill_for_collection( $skill );
		}

		return rest_ensure_response( $data );
	}

	/**
	 * GET /skills/{name} – retrieve a single skill with full content.
	 *
	 * @since 1.8.0
	 * @param WP_REST_Request $request Full request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$name     = sanitize_key( $request->get_param( 'name' ) );
		$registry = $this->get_registry();
		$skill    = $registry->get_skill( $name );

		if ( null === $skill ) {
			return new WP_Error(
				'rest_skill_not_found',
				__( 'Skill not found.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 404 )
			);
		}

		// Also return raw SKILL.md content so the editor can display it.
		$skill_file = trailingslashit( $registry->get_skills_dir() ) . $name . '/SKILL.md';
		$raw        = '';
		if ( file_exists( $skill_file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local uploaded skill file.
			$raw = file_get_contents( $skill_file );
		}

		$data = $this->prepare_skill_for_collection( $skill );
		$data['raw_content']  = false !== $raw ? $raw : '';
		$data['instructions'] = $skill['instructions'];

		return rest_ensure_response( $data );
	}

	/**
	 * POST /skills – install a skill from raw SKILL.md content.
	 *
	 * @since 1.8.0
	 * @param WP_REST_Request $request Full request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		// NOTE: $content is intentionally not run through sanitize_text_field() here.
		// Raw SKILL.md Markdown (including multi-line frontmatter and instructions) is
		// validated by WP_MCP_AI_Skill_Parser::parse() inside install_skill(). Sanitizing
		// would silently truncate newlines and corrupt the skill specification.
		$content  = $request->get_param( 'content' );
		$registry = $this->get_registry();
		$result   = $registry->install_skill( $content );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$response = rest_ensure_response( $this->prepare_skill_for_collection( $result ) );
		$response->set_status( 201 );

		return $response;
	}

	/**
	 * POST /skills/install-url – fetch and install a skill from a remote URL.
	 *
	 * Only http:// and https:// URLs pointing to a SKILL.md file are accepted.
	 * The response body is treated as raw SKILL.md content and passed to
	 * WP_MCP_AI_Skill_Registry::install_skill().
	 *
	 * @since 1.8.0
	 * @param WP_REST_Request $request Full request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function install_from_url( $request ) {
		$url = esc_url_raw( $request->get_param( 'url' ) );

		// Only allow http/https.
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return new WP_Error(
				'rest_skill_invalid_url',
				__( 'Only http and https URLs are supported.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		// Block SSRF: resolve the host to an IP and reject private/reserved ranges.
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( empty( $host ) ) {
			return new WP_Error(
				'rest_skill_invalid_url',
				__( 'Invalid URL: could not determine host.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		// gethostbyname() returns the input unchanged when resolution fails.
		// Treat an unresolvable hostname as a hard rejection: we cannot determine
		// whether it points to a private address, so failing closed is safer.
		$resolved_ip = gethostbyname( $host );
		if ( $resolved_ip === $host && false === filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return new WP_Error(
				'rest_skill_invalid_url',
				__( 'URL hostname could not be resolved. Please verify the URL is correct.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		// Reject private, loopback, link-local, and reserved IP ranges.
		// FILTER_FLAG_NO_PRIV_RANGE covers RFC-1918 (10/8, 172.16/12, 192.168/16),
		// loopback (127/8), and link-local (169.254/16).
		// FILTER_FLAG_NO_RES_RANGE covers IANA-reserved blocks including 0.0.0.0/8.
		if ( false === filter_var( $resolved_ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return new WP_Error(
				'rest_skill_ssrf',
				__( 'URL resolves to a private or reserved address and cannot be fetched.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		// Replace the hostname with its resolved IP in the request URL to prevent
		// DNS rebinding attacks: the IP is pinned here so a second DNS lookup at
		// request time cannot return a different (private) address.
		$scheme   = wp_parse_url( $url, PHP_URL_SCHEME );
		$path     = wp_parse_url( $url, PHP_URL_PATH );
		$query    = wp_parse_url( $url, PHP_URL_QUERY );
		$port     = wp_parse_url( $url, PHP_URL_PORT );
		$host_str = $resolved_ip . ( $port ? ':' . (int) $port : '' );
		$safe_url = $scheme . '://' . $host_str . ( $path ? $path : '' ) . ( $query ? '?' . $query : '' );

		$response = wp_remote_get(
			$safe_url,
			array(
				'timeout'    => 15,
				'user-agent' => 'WP-MCP-AI-Skill-Manager/' . WP_MCP_AI_PRO_VERSION . ' (WordPress/' . get_bloginfo( 'version' ) . ')',
				'headers'    => array(
					'Host' => $host,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'rest_skill_fetch_failed',
				sprintf(
					/* translators: %s: error message from remote fetch */
					__( 'Failed to fetch skill from URL: %s', 'mcp-ai-wpoos-pro' ),
					$response->get_error_message()
				),
				array( 'status' => 502 )
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== (int) $code ) {
			return new WP_Error(
				'rest_skill_fetch_bad_response',
				sprintf(
					/* translators: %d: HTTP response code */
					__( 'Remote URL returned HTTP %d.', 'mcp-ai-wpoos-pro' ),
					$code
				),
				array( 'status' => 502 )
			);
		}

		$content = wp_remote_retrieve_body( $response );
		if ( empty( $content ) ) {
			return new WP_Error(
				'rest_skill_empty_body',
				__( 'Remote URL returned an empty response body.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 502 )
			);
		}

		$registry = $this->get_registry();
		$result   = $registry->install_skill( $content );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$rest_response = rest_ensure_response( $this->prepare_skill_for_collection( $result ) );
		$rest_response->set_status( 201 );

		return $rest_response;
	}

	/**
	 * PUT /skills/{name} – update (replace) an existing skill's SKILL.md content.
	 *
	 * The skill name in the URL must match the name in the new SKILL.md content.
	 *
	 * @since 1.8.0
	 * @param WP_REST_Request $request Full request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		$name = sanitize_key( $request->get_param( 'name' ) );
		// NOTE: $content is intentionally not run through sanitize_text_field() here.
		// Raw SKILL.md Markdown (including multi-line frontmatter and instructions) is
		// validated by WP_MCP_AI_Skill_Parser::parse() inside install_skill(). Sanitizing
		// would silently truncate newlines and corrupt the skill specification.
		$content  = $request->get_param( 'content' );
		$registry = $this->get_registry();

		// Ensure the skill exists.
		if ( null === $registry->get_skill( $name ) ) {
			return new WP_Error(
				'rest_skill_not_found',
				__( 'Skill not found.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 404 )
			);
		}

		// Parse the new content to verify the name matches.
		$parser = new WP_MCP_AI_Skill_Parser();
		$parsed = $parser->parse( $content );

		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		if ( $parsed['name'] !== $name ) {
			return new WP_Error(
				'rest_skill_name_mismatch',
				sprintf(
					/* translators: 1: name from URL, 2: name in SKILL.md frontmatter */
					__( 'Skill name in the URL (%1$s) does not match the name in the SKILL.md frontmatter (%2$s).', 'mcp-ai-wpoos-pro' ),
					$name,
					$parsed['name']
				),
				array( 'status' => 422 )
			);
		}

		// Overwrite by installing (install_skill overwrites existing files).
		$result = $registry->install_skill( $content );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $this->prepare_skill_for_collection( $result ) );
	}

	/**
	 * DELETE /skills/{name} – uninstall a skill.
	 *
	 * @since 1.8.0
	 * @param WP_REST_Request $request Full request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ) {
		$name     = sanitize_key( $request->get_param( 'name' ) );
		$registry = $this->get_registry();
		$result   = $registry->uninstall_skill( $name );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'deleted' => true,
				'name'    => $name,
			)
		);
	}

	/**
	 * Prepare a skill array for collection response (without full instructions).
	 *
	 * @since 1.8.0
	 * @param array $skill Parsed skill data array.
	 * @return array Prepared data for API response.
	 */
	protected function prepare_skill_for_collection( $skill ) {
		return array(
			'name'          => $skill['name'],
			'description'   => $skill['description'],
			'license'       => $skill['license'],
			'compatibility' => $skill['compatibility'],
			'metadata'      => $skill['metadata'],
			'allowed_tools' => $skill['allowed_tools'],
		);
	}

	/**
	 * Common URL parameter args for single-item routes.
	 *
	 * @since 1.8.0
	 * @return array
	 */
	protected function get_item_args() {
		return array(
			'name' => array(
				'description'       => __( 'Skill name (slug).', 'mcp-ai-wpoos-pro' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => function( $value ) {
					return (bool) preg_match( '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $value )
						&& false === strpos( $value, '--' );
				},
			),
		);
	}

	/**
	 * Get the Skill Registry instance, ensuring classes are loaded.
	 *
	 * @since 1.8.0
	 * @return WP_MCP_AI_Skill_Registry
	 */
	protected function get_registry() {
		if ( ! class_exists( 'WP_MCP_AI_Skill_Registry' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-skill-registry.php';
		}

		if ( ! class_exists( 'WP_MCP_AI_Skill_Parser' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-skill-parser.php';
		}

		return WP_MCP_AI_Skill_Registry::instance();
	}
}
