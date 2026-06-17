<?php
/**
 * Tests for the Scheduled Result PHP renderer.
 *
 * @package WP_MCP_AI
 */

/**
 * Class Test_Pro_Schedule_Result_Renderer.
 */
class Test_Pro_Schedule_Result_Renderer extends WP_UnitTestCase {

	/**
	 * Skip when manager is unavailable.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		require_once dirname( __DIR__, 3 ) . '/includes/renderers/class-wp-mcp-ai-scheduled-result-renderer.php';
	}

	/**
	 * Returns notice when no schedule is selected.
	 */
	public function test_empty_schedule_id_yields_notice() {
		$html = WP_MCP_AI_Scheduled_Result_Renderer::render( '' );
		$this->assertStringContainsString( 'mcp-ai-scheduled-result--notice', $html );
	}

	/**
	 * When Pro is absent, the renderer surfaces a Pro-required notice.
	 *
	 * This test only meaningfully runs when the Pro addon is NOT loaded.
	 */
	public function test_no_pro_yields_pro_required_notice() {
		if ( class_exists( 'WP_MCP_AI_Pro_Schedule_Manager' ) ) {
			$this->markTestSkipped( 'Pro is loaded — Pro-required notice cannot be triggered here.' );
		}
		$html = WP_MCP_AI_Scheduled_Result_Renderer::render( 'whatever' );
		$this->assertStringContainsString( 'requires the NV oOS Pro', $html );
	}

	/**
	 * The summary-card mode produces escaped output.
	 */
	public function test_summary_card_escapes_summary() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Schedule_Manager' ) ) {
			$this->markTestSkipped( 'Pro Schedule Manager not available.' );
		}
		$schedule_id = 'rendrr_' . uniqid();
		$schedule    = array(
			'id'            => $schedule_id,
			'name'          => 'Test Renderer',
			'schedule_type' => 'task',
			'display'       => WP_MCP_AI_Pro_Schedule_Manager::sanitize_display_fields(
				array(
					'public_render' => true,
					'public_fields' => array( 'summary' ),
				)
			),
		);
		update_option(
			WP_MCP_AI_Pro_Schedule_Manager::SCHEDULES_OPTION,
			array( $schedule_id => $schedule )
		);

		// Inject an envelope.
		update_option(
			WP_MCP_AI_Pro_Schedule_Manager::RESULTS_OPTION,
			array(
				$schedule_id => array(
					array(
						'summary'      => '<script>alert(1)</script>danger',
						'data'         => array(),
						'render'       => 'text',
						'status'       => 'success',
						'error'        => '',
						'generated_at' => time(),
					),
				),
			)
		);

		wp_set_current_user( 0 );
		$html = WP_MCP_AI_Scheduled_Result_Renderer::render( $schedule_id );
		$this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
		$this->assertStringContainsString( 'danger', $html );
	}

	/**
	 * The list mode renders items as escaped <li>s.
	 */
	public function test_list_mode_renders_items() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Schedule_Manager' ) ) {
			$this->markTestSkipped( 'Pro Schedule Manager not available.' );
		}
		$schedule_id = 'rendrr_list_' . uniqid();
		$schedule    = array(
			'id'            => $schedule_id,
			'name'          => 'Digest',
			'schedule_type' => 'assistant_run',
			'display'       => WP_MCP_AI_Pro_Schedule_Manager::sanitize_display_fields(
				array(
					'public_render' => true,
					'public_fields' => array( 'summary', 'data.items' ),
				)
			),
		);
		update_option(
			WP_MCP_AI_Pro_Schedule_Manager::SCHEDULES_OPTION,
			array( $schedule_id => $schedule )
		);
		update_option(
			WP_MCP_AI_Pro_Schedule_Manager::RESULTS_OPTION,
			array(
				$schedule_id => array(
					array(
						'summary'      => '3 items',
						'data'         => array( 'items' => array( 'Alpha', 'Beta', '<b>Gamma</b>' ) ),
						'render'       => 'list',
						'status'       => 'success',
						'error'        => '',
						'generated_at' => time(),
					),
				),
			)
		);

		wp_set_current_user( 0 );
		$html = WP_MCP_AI_Scheduled_Result_Renderer::render( $schedule_id, array( 'render_mode' => 'list' ) );
		$this->assertStringContainsString( '<ol class="mcp-ai-scheduled-result__list"', $html );
		$this->assertStringContainsString( 'Alpha', $html );
		$this->assertStringNotContainsString( '<b>Gamma</b>', $html );
	}
}
