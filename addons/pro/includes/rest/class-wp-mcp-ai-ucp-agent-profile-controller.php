<?php
/**
 * UCP Agent Profile REST Controller.
 *
 * Serves this site's UCP (Universal Commerce Protocol) platform profile so
 * Shopify can negotiate catalog capabilities for keyless Storefront Catalog
 * MCP connections. Shopify fetches the profile server-side from the URL
 * carried in each request's meta.ucp-agent.profile field.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.80
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_UCP_Agent_Profile_Controller' ) ) {

	/**
	 * Serves the site's UCP platform profile for Shopify Catalog negotiation.
	 *
	 * The profile declares the UCP protocol version and the catalog
	 * capabilities this agent supports (search + lookup, plus the Shopify
	 * storefront-catalog extension). Shopify intersects these with the
	 * store's own business profile to settle the negotiated capability set.
	 *
	 * @since 1.1.80
	 */
	class WP_MCP_AI_UCP_Agent_Profile_Controller extends WP_REST_Controller {

		/**
		 * UCP protocol version declared by this profile.
		 *
		 * Matches the version Shopify documents for Storefront Catalog MCP
		 * negotiation. Bump together with the client's UCP constants when
		 * Shopify releases a new protocol version.
		 *
		 * @var string
		 */
		const UCP_VERSION = '2026-08-25';

		/**
		 * REST namespace.
		 *
		 * @var string
		 */
		protected $namespace = 'mcp-ai/v1';

		/**
		 * Constructor.
		 *
		 * @since 1.1.80
		 */
		public function __construct() {
			add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		}

		/**
		 * Register the profile route.
		 *
		 * @since 1.1.80
		 *
		 * @return void
		 */
		public function register_routes() {
			register_rest_route(
				$this->namespace,
				'/ucp/agent-profile',
				array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this, 'get_profile' ),
						// Public by design: the profile contains no secrets and
						// is fetched server-side by Shopify for negotiation.
						'permission_callback' => '__return_true',
					),
				)
			);
		}

		/**
		 * Serve the UCP platform profile document.
		 *
		 * @since 1.1.80
		 *
		 * @return WP_REST_Response
		 */
		public function get_profile() {
			$response = new WP_REST_Response( $this->build_profile(), 200 );
			// Shopify may cache the profile, but the site owner edits it
			// through code — never cache so updates take effect promptly.
			$response->header( 'Cache-Control', 'no-store, max-age=0' );
			return $response;
		}

		/**
		 * Build the UCP platform profile document.
		 *
		 * @since 1.1.80
		 *
		 * @return array
		 */
		public function build_profile() {
			return array(
				'ucp' => array(
					'version'          => self::UCP_VERSION,
					'services'         => array(
						'dev.ucp.shopping' => array(
							array(
								'version'   => self::UCP_VERSION,
								'spec'      => 'https://ucp.dev/' . self::UCP_VERSION . '/specification/overview',
								'transport' => 'mcp',
								'schema'    => 'https://ucp.dev/' . self::UCP_VERSION . '/services/shopping/mcp.openrpc.json',
							),
						),
					),
					'capabilities'     => array(
						'dev.ucp.shopping.catalog.search' => array(
							array( 'version' => self::UCP_VERSION ),
						),
						'dev.ucp.shopping.catalog.lookup' => array(
							array( 'version' => self::UCP_VERSION ),
						),
						'dev.shopify.catalog'             => array(
							array(
								'version' => self::UCP_VERSION,
								'spec'    => 'https://shopify.dev/docs/agents/catalog/storefront-catalog',
								'schema'  => 'https://shopify.dev/ucp/schemas/' . self::UCP_VERSION . '/shopify_catalog.json',
								'extends' => array(
									'dev.ucp.shopping.catalog.lookup',
									'dev.ucp.shopping.catalog.search',
								),
							),
						),
						// Cross-merchant Global Catalog extension. Shopify intersects
						// it with the agent profile to settle the negotiated set.
						'dev.shopify.catalog.global'      => array(
							array(
								'version' => self::UCP_VERSION,
								'spec'    => 'https://shopify.dev/docs/agents/catalog/global-catalog',
								'extends' => array(
									'dev.ucp.shopping.catalog.lookup',
									'dev.ucp.shopping.catalog.search',
								),
							),
						),
					),
					'payment_handlers' => array(),
				),
			);
		}
	}
}
