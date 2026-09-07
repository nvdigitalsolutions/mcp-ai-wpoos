<?php
/**
 * Characterization tests for the Wave F2 CRM REST slice — the ported
 * `WP_MCP_AI_CRM_REST_Controller`.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   class (classmap-autoloaded); the byte-identical route surface, gate,
 *   and response shapes are asserted via server dispatch.
 * - Standalone matrix (base plugin absent): the ported copy in
 *   `src/rest/` is asserted in full, including the serving source and the
 *   init wiring.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * CRM REST controller tests.
 */
class Test_Crm_Rest_Controller extends WP_UnitTestCase {

	/**
	 * The serving source must follow the ownership boundary.
	 */
	public function test_controller_serving_source(): void {
		$reflection = new ReflectionClass( 'WP_MCP_AI_CRM_REST_Controller' );
		$file       = str_replace( '\\', '/', (string) $reflection->getFileName() );

		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->assertStringContainsString( 'addons/pro/includes/rest/class-wp-mcp-ai-crm-rest-controller.php', $file );
		} else {
			$this->assertStringContainsString( 'nvoos-content-graph-pro/src/rest/class-wp-mcp-ai-crm-rest-controller.php', $file );
		}
	}

	/**
	 * The six CRM routes must register under the byte-identical namespace.
	 */
	public function test_routes_register(): void {
		$controller = WP_MCP_AI_CRM_REST_Controller::get_instance();
		$controller->init();
		do_action( 'rest_api_init' );

		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/mcp-ai-pro/v1/crm/contacts', $routes );
		$this->assertArrayHasKey( '/mcp-ai-pro/v1/crm/deals', $routes );
		$this->assertArrayHasKey( '/mcp-ai-pro/v1/crm/pipeline', $routes );
		$this->assertArrayHasKey( '/mcp-ai-pro/v1/crm/kpis', $routes );

		// The {id} routes normalize their regex in the route key — probe
		// for them by prefix.
		$has_contact_single = false;
		$has_deal_single    = false;
		foreach ( array_keys( $routes ) as $key ) {
			if ( 0 === strpos( $key, '/mcp-ai-pro/v1/crm/contacts/' ) ) {
				$has_contact_single = true;
			}
			if ( 0 === strpos( $key, '/mcp-ai-pro/v1/crm/deals/' ) ) {
				$has_deal_single = true;
			}
		}
		$this->assertTrue( $has_contact_single );
		$this->assertTrue( $has_deal_single );
	}

	/**
	 * The permission gate must reject subscribers and admit editors.
	 */
	public function test_permission_gate(): void {
		$controller = WP_MCP_AI_CRM_REST_Controller::get_instance();
		$controller->init();
		do_action( 'rest_api_init' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/mcp-ai-pro/v1/crm/contacts' ) );
		$this->assertSame( 403, $response->get_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/mcp-ai-pro/v1/crm/contacts' ) );
		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * The contacts collection must expose the byte-identical field shape.
	 */
	public function test_contacts_collection_and_single(): void {
		$controller = WP_MCP_AI_CRM_REST_Controller::get_instance();
		$controller->init();
		do_action( 'rest_api_init' );

		$lead_id = self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_lead',
				'post_status' => 'publish',
				'post_title'  => 'Jane Lead',
			)
		);
		update_post_meta( $lead_id, 'email', 'jane@example.com' );
		update_post_meta( $lead_id, 'contact_owner', 7 );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$collection = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/mcp-ai-pro/v1/crm/contacts' ) );
		$this->assertSame( 200, $collection->get_status() );
		$data = $collection->get_data();
		$this->assertArrayHasKey( 'items', $data );
		$this->assertNotEmpty( $data['items'] );
		$this->assertSame( 'Jane Lead', $data['items'][0]['full_name'] );
		$this->assertSame( 'jane@example.com', $data['items'][0]['email'] );
		$this->assertSame( 7, $data['items'][0]['owner_id'] );
		$this->assertSame( 'new', $data['items'][0]['stage'] );

		$single = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/mcp-ai-pro/v1/crm/contacts/' . $lead_id ) );
		$this->assertSame( 200, $single->get_status() );
		$this->assertSame( 'jane@example.com', $single->get_data()['email'] );

		// A non-lead post must 404.
		$other = self::factory()->post->create( array( 'post_type' => 'post' ) );
		$ghost = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/mcp-ai-pro/v1/crm/contacts/' . $other ) );
		$this->assertSame( 404, $ghost->get_status() );
	}

	/**
	 * The deals collection/single must expose the byte-identical shape.
	 */
	public function test_deals_collection_and_single(): void {
		$controller = WP_MCP_AI_CRM_REST_Controller::get_instance();
		$controller->init();
		do_action( 'rest_api_init' );

		$deal_id = self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_deal',
				'post_status' => 'publish',
				'post_title'  => 'Big Deal',
			)
		);
		update_post_meta( $deal_id, 'deal_amount', '12000' );
		update_post_meta( $deal_id, 'deal_stage', 'proposal' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$collection = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/mcp-ai-pro/v1/crm/deals' ) );
		$this->assertSame( 200, $collection->get_status() );
		$data = $collection->get_data();
		$this->assertNotEmpty( $data['items'] );
		$this->assertSame( 'Big Deal', $data['items'][0]['title'] );
		$this->assertSame( 12000.0, $data['items'][0]['amount'] );
		$this->assertSame( 'proposal', $data['items'][0]['stage'] );

		$single = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/mcp-ai-pro/v1/crm/deals/' . $deal_id ) );
		$this->assertSame( 200, $single->get_status() );
		$this->assertSame( 12000.0, $single->get_data()['amount'] );
	}

	/**
	 * The pipeline aggregation must bucket deals by stage with values.
	 */
	public function test_pipeline_aggregation(): void {
		$controller = WP_MCP_AI_CRM_REST_Controller::get_instance();
		$controller->init();
		do_action( 'rest_api_init' );

		$deal_id = self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_deal',
				'post_status' => 'publish',
				'post_title'  => 'Stage Deal',
			)
		);
		update_post_meta( $deal_id, 'deal_stage', 'negotiation' );
		update_post_meta( $deal_id, 'deal_amount', '30000' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/mcp-ai-pro/v1/crm/pipeline' ) );
		$this->assertSame( 200, $response->get_status() );

		$data  = $response->get_data();
		$found = null;
		foreach ( $data as $bucket ) {
			if ( 'negotiation' === $bucket['stage'] ) {
				$found = $bucket;
			}
		}
		$this->assertNotNull( $found );
		$this->assertSame( 1, $found['count'] );
		$this->assertSame( 30000.0, $found['value'] );
	}

	/**
	 * The KPIs endpoint must aggregate the byte-identical totals (requires
	 * the CPTs registered — wp_count_posts() returns empty counts for
	 * unregistered types; the CRM init registers them in production).
	 */
	public function test_kpis(): void {
		$controller = WP_MCP_AI_CRM_REST_Controller::get_instance();
		$controller->init();
		do_action( 'rest_api_init' );

		foreach ( array( 'lead', 'deal', 'company' ) as $cpt_slug ) {
			if ( defined( 'WP_MCP_AI_PATH' ) ) {
				require_once WP_MCP_AI_PATH . 'addons/pro/includes/class-wp-mcp-ai-' . $cpt_slug . '-cpt.php';
			} else {
				require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-' . $cpt_slug . '-cpt.php';
			}
		}
		WP_MCP_AI_Lead_CPT::register_post_type();
		WP_MCP_AI_Deal_CPT::register_post_type();
		WP_MCP_AI_Company_CPT::register_post_type();

		self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_lead',
				'post_status' => 'publish',
			)
		);
		self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_deal',
				'post_status' => 'publish',
			)
		);
		self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_company',
				'post_status' => 'publish',
			)
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/mcp-ai-pro/v1/crm/kpis' ) );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertGreaterThanOrEqual( 1, $data['total_leads'] );
		$this->assertGreaterThanOrEqual( 1, $data['total_deals'] );
		$this->assertGreaterThanOrEqual( 1, $data['total_companies'] );
	}

	/**
	 * Standalone only: the CRM init must wire the controller's
	 * rest_api_init hook.
	 */
	public function test_init_wiring_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base CRM init wires the controller at boot.' );
		}

		$settings                       = get_option( 'wp_mcp_ai_settings', array() );
		$settings                       = is_array( $settings ) ? $settings : array();
		$settings['enable_crm_toolkit'] = 1;
		update_option( 'wp_mcp_ai_settings', $settings );

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/init.php';

		$this->assertNotFalse( has_action( 'rest_api_init' ) );
		do_action( 'rest_api_init' );
		$this->assertArrayHasKey( '/mcp-ai-pro/v1/crm/contacts', rest_get_server()->get_routes() );
	}
}
