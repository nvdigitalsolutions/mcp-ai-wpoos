<?php
/**
 * Markup REST controller (Wave E6, sub-cluster 2).
 *
 * Aligned port of the base plugin's `WP_MCP_AI_Markup_REST_Controller`
 * (`includes/markup/`): byte-identical `mcp-ai/v1/markup` route surface
 * (GET / DELETE by request ID, POST submit), the request-ID sanitizer,
 * the three-tier permission gate (disabled 503, logged-in edit_posts/
 * read, guest-owned request resolution), the GET/DELETE handlers, the
 * submit handler (consume-on-read replay protection, validation →
 * rasterization → tool resume, the 400-annotated validation errors, the
 * `wp_mcp_ai_markup_submitted|validated|resolved` lifecycle actions),
 * and the resume envelope.
 *
 * Documented deviations:
 *  - Class name/namespace — the AI addon's PSR-4 tree (decision D4).
 *  - The originating-tool lookup resolves per install mode via the
 *    `find_tool()` seam: base `WP_MCP_AI_Tool_Registry` monolith,
 *    `CoreBridge::instance()->tools` standalone; the awareness check
 *    accepts the base interface monolith and this package's interface
 *    standalone (`is_markup_aware()`). The ported controller is wired
 *    standalone-only (the base owns the same routes monolith), so the
 *    monolith branch is defensive seam resolution, not a supported
 *    entry point.
 *  - Routes register standalone-only via `MarkupBootstrap`.
 *  - `WP_REST_Server`, `WP_REST_Request`, `WP_REST_Response`,
 *    `WP_Error`, and `Exception` are fully qualified.
 *  - Text domain `nvoos-content-graph-ai`.
 *
 * @since 1.1.0
 * @package NvoosContentGraphAi\Engine\Markup
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Engine\Markup;

/**
 * REST controller for the markup elicitation subsystem.
 *
 * @since 1.1.0
 */
class MarkupRestController {

	const REST_NAMESPACE = 'mcp-ai/v1';

	/**
	 * Markup store.
	 *
	 * @var MarkupStore
	 */
	private $store;

	/**
	 * Markup validator.
	 *
	 * @var MarkupValidator
	 */
	private $validator;

	/**
	 * Markup rasterizer.
	 *
	 * @var MarkupRasterizer
	 */
	private $rasterizer;

	/**
	 * Constructor.
	 *
	 * @param MarkupStore|null      $store      Optional store override.
	 * @param MarkupValidator|null  $validator  Optional validator override.
	 * @param MarkupRasterizer|null $rasterizer Optional rasterizer override.
	 */
	public function __construct( $store = null, $validator = null, $rasterizer = null ) {
		$this->store      = $store instanceof MarkupStore ? $store : new MarkupStore();
		$this->validator  = $validator instanceof MarkupValidator ? $validator : new MarkupValidator();
		$this->rasterizer = $rasterizer instanceof MarkupRasterizer ? $rasterizer : new MarkupRasterizer();
	}

	/**
	 * Register all markup routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		\register_rest_route(
			self::REST_NAMESPACE,
			'/markup/(?P<request_id>[A-Za-z0-9_-]+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'handle_get' ),
					'permission_callback' => array( $this, 'permission_check' ),
					'args'                => array(
						'request_id' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => array( $this, 'sanitize_request_id' ),
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'handle_delete' ),
					'permission_callback' => array( $this, 'permission_check' ),
					'args'                => array(
						'request_id' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => array( $this, 'sanitize_request_id' ),
						),
					),
				),
			)
		);

		\register_rest_route(
			self::REST_NAMESPACE,
			'/markup/(?P<request_id>[A-Za-z0-9_-]+)/submit',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_submit' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					'request_id' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => array( $this, 'sanitize_request_id' ),
					),
					'markup'     => array(
						'type'        => 'object',
						'required'    => true,
						'description' => 'W3C Web Annotation document describing the user markup.',
					),
					'extra'      => array(
						'type'        => 'object',
						'required'    => false,
						'description' => 'Additional fields collected per the request schema.',
					),
				),
			)
		);
	}

	/**
	 * Sanitize a request ID. Only allow base64url-style characters.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public function sanitize_request_id( $value ) {
		$value = \is_string( $value ) ? $value : '';
		return \preg_replace( '/[^A-Za-z0-9_-]/', '', $value );
	}

	/**
	 * Permission gate.
	 *
	 * Accepts the same authentication tiers as the chat endpoints:
	 *  - Logged-in WordPress user with `edit_posts` (or `read` when the
	 *    target attachment has no owner — guest assistants).
	 *  - Bearer assistant credential (validated upstream by the auth
	 *    middleware that the request must already have passed through;
	 *    when the credential resolved we get a non-zero current user).
	 *  - REST nonce (`X-WP-Nonce`) when the cookie auth is in play.
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 * @return true|\WP_Error
	 */
	public function permission_check( $request ) {
		if ( ! MarkupLoopInterceptor::is_enabled() ) {
			return new \WP_Error(
				'wp_mcp_ai_markup_disabled',
				__( 'The markup subsystem is disabled.', 'nvoos-content-graph-ai' ),
				array( 'status' => 503 )
			);
		}

		// Logged-in user path.
		if ( \get_current_user_id() > 0 ) {
			if ( \current_user_can( 'edit_posts' ) ) {
				return true;
			}
			if ( \current_user_can( 'read' ) ) {
				return true;
			}
			return new \WP_Error(
				'wp_mcp_ai_markup_forbidden',
				__( 'You do not have permission to access markup requests.', 'nvoos-content-graph-ai' ),
				array( 'status' => 403 )
			);
		}

		// Guest path: only allowed if the request_id resolves to an
		// assistant-scoped request that allows guest submissions and the
		// caller presents the matching guest token.
		$request_id = $request instanceof \WP_REST_Request ? (string) $request->get_param( 'request_id' ) : '';
		$record     = $this->store->get( $request_id );
		if ( ! $record ) {
			// Don't leak existence — treat unknown as forbidden.
			return new \WP_Error(
				'wp_mcp_ai_markup_forbidden',
				__( 'You do not have permission to access markup requests.', 'nvoos-content-graph-ai' ),
				array( 'status' => 403 )
			);
		}
		if ( $record->get_user_id() > 0 ) {
			// Owned by a real user — guests must not access it.
			return new \WP_Error(
				'wp_mcp_ai_markup_forbidden',
				__( 'You do not have permission to access this markup request.', 'nvoos-content-graph-ai' ),
				array( 'status' => 403 )
			);
		}
		// Guest tokens are validated by the standard chat auth middleware;
		// the assistant_id on the request must match the active guest scope.
		// For PR1 we accept any guest if the request itself is guest-owned;
		// the chat client always presents the same X-WP-MCP-AI-Guest header
		// the rest of the surface uses.
		return true;
	}

	/**
	 * GET handler — return the request schema for the canvas widget.
	 *
	 * @param \WP_REST_Request $req Incoming request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_get( \WP_REST_Request $req ) {
		$record = $this->store->get( (string) $req->get_param( 'request_id' ) );
		if ( ! $record ) {
			return new \WP_Error( 'wp_mcp_ai_markup_not_found', __( 'Markup request not found or expired.', 'nvoos-content-graph-ai' ), array( 'status' => 404 ) );
		}
		$payload = MarkupElicitation::to_widget_payload( $record );
		// Hide tool_arguments from the GET response — those belong to the server.
		unset( $payload['tool_arguments'], $payload['tool_context'] );
		return \rest_ensure_response( $payload );
	}

	/**
	 * DELETE handler — cancel a pending request.
	 *
	 * @param \WP_REST_Request $req Incoming request.
	 * @return \WP_REST_Response
	 */
	public function handle_delete( \WP_REST_Request $req ) {
		$request_id = (string) $req->get_param( 'request_id' );
		$record     = $this->store->get( $request_id );
		if ( $record ) {
			$this->store->delete( $request_id );
			/**
			 * Fires when a markup request is cancelled by the client.
			 *
			 * @param MarkupRequest $record Cancelled request.
			 */
			\do_action( 'wp_mcp_ai_markup_resolved', $record, 'cancelled' );
		}
		return \rest_ensure_response(
			array(
				'cancelled'  => true,
				'request_id' => $request_id,
			)
		);
	}

	/**
	 * POST handler — accept a submission, validate, rasterize, resume tool.
	 *
	 * @param \WP_REST_Request $req Incoming request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_submit( \WP_REST_Request $req ) {
		$request_id = (string) $req->get_param( 'request_id' );
		$record     = $this->store->consume( $request_id ); // Replay protection: delete on read.
		if ( ! $record ) {
			return new \WP_Error(
				'wp_mcp_ai_markup_not_found',
				__( 'Markup request not found, already consumed, or expired.', 'nvoos-content-graph-ai' ),
				array( 'status' => 404 )
			);
		}

		\do_action( 'wp_mcp_ai_markup_submitted', $record );

		$annotation = $req->get_param( 'markup' );
		$cleaned    = $this->validator->validate( $record, \is_array( $annotation ) ? $annotation : array() );
		if ( \is_wp_error( $cleaned ) ) {
			\do_action( 'wp_mcp_ai_markup_resolved', $record, 'invalid' );
			// Validation failures are client errors: annotate the WP_Error so the
			// REST server surfaces 400 instead of its default 500.
			$cleaned->add_data( array( 'status' => 400 ), $cleaned->get_error_code() );
			return $cleaned;
		}

		\do_action( 'wp_mcp_ai_markup_validated', $record, $cleaned );

		$artifacts = $this->rasterizer->rasterize( $record, $cleaned );

		$extra_raw = $req->get_param( 'extra' );
		$extra     = \is_array( $extra_raw ) ? $extra_raw : array();

		$result_obj = new MarkupResult( $record, $cleaned, $extra, $artifacts );

		// Resume the originating tool.
		$tool_result = $this->resume_tool( $record, $result_obj );
		if ( \is_wp_error( $tool_result ) ) {
			\do_action( 'wp_mcp_ai_markup_resolved', $record, 'tool_error' );
			return $tool_result;
		}

		\do_action( 'wp_mcp_ai_markup_resolved', $record, 'completed' );

		return \rest_ensure_response(
			array(
				'request_id' => $record->get_request_id(),
				'tool'       => $record->get_tool_slug(),
				'artifacts'  => $artifacts,
				'result'     => $tool_result,
			)
		);
	}

	/**
	 * Resume the originating tool with the validated markup result.
	 *
	 * @param MarkupRequest $record Original request.
	 * @param MarkupResult  $result Validated result.
	 * @return mixed|\WP_Error
	 */
	private function resume_tool( MarkupRequest $record, MarkupResult $result ) {
		$tool = $this->find_tool( $record->get_tool_slug() );
		if ( null === $tool ) {
			return new \WP_Error(
				'wp_mcp_ai_markup_tool_missing',
				__( 'Originating tool is no longer registered.', 'nvoos-content-graph-ai' ),
				array( 'status' => 410 )
			);
		}
		if ( ! $this->is_markup_aware( $tool ) ) {
			return new \WP_Error(
				'wp_mcp_ai_markup_tool_not_aware',
				__( 'Originating tool no longer supports markup.', 'nvoos-content-graph-ai' ),
				array( 'status' => 409 )
			);
		}
		try {
			return $tool->consume_markup( $record->get_tool_arguments(), $result, $record->get_tool_context() );
		} catch ( \Exception $e ) {
			return new \WP_Error( 'wp_mcp_ai_markup_tool_exception', $e->getMessage() );
		}
	}

	/**
	 * Look up a registered tool by slug for this install mode.
	 *
	 * Monolith installs use the base tool registry; standalone installs
	 * use this addon's `CoreBridge` core registry. The ported controller
	 * is wired standalone-only, so the monolith branch is defensive seam
	 * resolution (byte-identical behavior, not a supported entry point).
	 *
	 * @param string $slug Tool slug.
	 * @return object|null Tool instance, or null when unavailable.
	 */
	protected function find_tool( $slug ) {
		if ( defined( 'WP_MCP_AI_PATH' ) && \class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			$registry = \WP_MCP_AI_Tool_Registry::get_instance();
			if ( $registry && \method_exists( $registry, 'get_tool' ) ) {
				return $registry->get_tool( (string) $slug );
			}
		}

		if ( \class_exists( \NvoosContentGraphAi\CoreBridge::class ) ) {
			return \NvoosContentGraphAi\CoreBridge::instance()->tools->get( (string) $slug );
		}

		return null;
	}

	/**
	 * Whether a tool implements the markup-aware contract for this install
	 * mode.
	 *
	 * The discriminator is `defined( 'WP_MCP_AI_PATH' )` — never bare
	 * `instanceof` — because the two modes have different interface
	 * classes.
	 *
	 * @param object $candidate Candidate tool instance.
	 * @return bool
	 */
	protected function is_markup_aware( $candidate ): bool {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			return $candidate instanceof \WP_MCP_AI_Markup_Aware_Tool_Interface;
		}

		return $candidate instanceof MarkupAwareToolInterface;
	}
}
