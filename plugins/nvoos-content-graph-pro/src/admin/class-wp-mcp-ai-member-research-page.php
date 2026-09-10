<?php
/**
 * class-wp-mcp-ai-member-research-page.php (ecosystem port — Wave F4, healthcare admin slice).
 *
 * Ported from the base Pro addon's `addons/pro/includes/admin/class-wp-mcp-ai-member-research-page.php` for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs — the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined
 * (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * `NVOOS_CONTENT_GRAPH_PRO_*` constant swaps (incl. the `defined( 'WP_MCP_AI_PRO_VERSION' )`
 * base-mode gates — the addon constant is always defined standalone, so the ternary yields
 * the addon version); the base-owned `__DIR__` trait requires and the
 * cpt-settings-base/research-add-base requires resolve from the addon's already-ported
 * `src/admin/` copies.
 *
 * @package NvoosContentGraphPro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load base class.
require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-research-add-base.php';

/**
 * Member Research & Add Page
 */
class WP_MCP_AI_Member_Research_Page extends WP_MCP_AI_Research_Add_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->post_type  = 'mcp_ai_member';
		$this->page_title = __( 'Research & Add Members', 'nvoos-content-graph-pro' );
		$this->menu_title = __( 'Research & Add', 'nvoos-content-graph-pro' );
		$this->page_slug  = 'member-research';
		$this->capability = 'edit_posts';

		parent::__construct( 'health' );
	}

	/**
	 * Get entity types for this toolkit.
	 *
	 * @return array Entity types.
	 */
	protected function get_entity_types() {
		return array(
			'members' => __( 'Members', 'nvoos-content-graph-pro' ),
		);
	}

	/**
	 * Get research instructions.
	 *
	 * @return string
	 */
	protected function get_research_instructions() {
		return __(
			'Use AI assistance to gather and organize health information for family members and pets. The AI can help you create comprehensive health profiles, track medical history, and manage wellness data.',
			'nvoos-content-graph-pro'
		);
	}

	/**
	 * Get research prompt suggestions.
	 *
	 * @return array
	 */
	protected function get_research_prompt_suggestions() {
		return array(
			__( 'Create a new family member profile with basic health information', 'nvoos-content-graph-pro' ),
			__( 'Add a pet member with breed, age, and health history', 'nvoos-content-graph-pro' ),
			__( 'Generate a health summary for existing family members', 'nvoos-content-graph-pro' ),
			__( 'Create vaccination schedules for children and pets', 'nvoos-content-graph-pro' ),
			__( 'Set up medication reminders for family members', 'nvoos-content-graph-pro' ),
			__( 'Log today\'s vital signs (BP, HR, temperature, SpO2) for a member', 'nvoos-content-graph-pro' ),
			__( 'Retrieve the latest vital sign readings for a member from the CCT', 'nvoos-content-graph-pro' ),
			__( 'Analyze blood pressure and kidney health trends over the last 30 days', 'nvoos-content-graph-pro' ),
			__( 'Extract vital signs from an uploaded lab report and save them to the CCT', 'nvoos-content-graph-pro' ),
		);
	}

	/**
	 * Get available tools for this research page.
	 *
	 * @return array
	 */
	protected function get_available_tools() {
		return array(
			// Core member management.
			'create_member',
			'update_member',
			'delete_member',
			'get_member',
			'list_members',
			// Health & wellness.
			'get_member_health_summary',
			'research_health_member',
			'generate_health_chart',
			'guide_health_record_creation',
			'parse_health_information',
			'analyze_loop_health',
			// Vital signs (logs directly to JetEngine CCT when available).
			'log_vital_signs',
			// Research → Paper Store pipeline.
			'generate_research_report',
			'create_post_from_research',
			// General research tools.
			'web_search',
			'search_content',
			'semantic_content_search',
		);
	}

	/**
	 * Render additional page content.
	 */
	protected function render_additional_content() {
		$has_cct     = class_exists( 'WP_MCP_AI_JetEngine_Vitals_Log_CCT' ) && WP_MCP_AI_JetEngine_Vitals_Log_CCT::table_exists();
		$consolidate = admin_url( 'edit.php?post_type=mcp_ai_member&page=health-records-consolidate' );
		?>
		<div class="member-research-tips" style="background: #f0f6fc; border-left: 4px solid #0073aa; padding: 12px 16px; margin: 20px 0;">
			<h4 style="margin-top: 0;"><?php esc_html_e( 'Health & Wellness Tips', 'nvoos-content-graph-pro' ); ?></h4>
			<ul style="margin: 8px 0;">
				<li><?php esc_html_e( '✓ Include age, gender, and known health conditions', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( '✓ Track allergies and medication reactions', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( '✓ Record vaccination dates and boosters', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( '✓ Document family medical history', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( '✓ Keep emergency contact information updated', 'nvoos-content-graph-pro' ); ?></li>
			</ul>
			<p style="margin-bottom: 0;">
				<strong><?php esc_html_e( 'Privacy Note:', 'nvoos-content-graph-pro' ); ?></strong>
				<?php esc_html_e( 'All health data is stored securely and privately. Ensure proper access controls are configured.', 'nvoos-content-graph-pro' ); ?>
			</p>
		</div>

		<div class="member-vitals-tip" style="background: #f0fdf4; border-left: 4px solid #16a34a; padding: 12px 16px; margin: 20px 0;">
			<h4 style="margin-top: 0; color: #15803d;">
				<span class="dashicons dashicons-heart" style="vertical-align: middle; margin-right: 4px;"></span>
				<?php esc_html_e( 'Vital Signs — CCT Integration', 'nvoos-content-graph-pro' ); ?>
			</h4>
			<?php if ( $has_cct ) : ?>
				<p style="margin: 0 0 8px;">
					<?php esc_html_e( 'JetEngine CCT is active. You can log, retrieve, and analyse vital sign measurements (BP, HR, SpO2, temperature, glucose, kidney indicators) directly from the AI assistant.', 'nvoos-content-graph-pro' ); ?>
				</p>
				<ul style="margin: 8px 0;">
					<li><em><?php esc_html_e( '"Log today\'s vitals for member ID 42: BP 118/76, HR 68, SpO2 99%, Temp 98.4°F"', 'nvoos-content-graph-pro' ); ?></em></li>
					<li><em><?php esc_html_e( '"Show the last 10 vital readings for Jane Doe"', 'nvoos-content-graph-pro' ); ?></em></li>
					<li><em><?php esc_html_e( '"Analyse eGFR and creatinine trends for member 17 over 90 days"', 'nvoos-content-graph-pro' ); ?></em></li>
				</ul>
				<p style="margin: 8px 0 0;">
					<a href="<?php echo esc_url( $consolidate ); ?>" class="button button-small">
						<?php esc_html_e( 'Open Consolidate & Add (Vital Signs tab)', 'nvoos-content-graph-pro' ); ?>
					</a>
				</p>
			<?php else : ?>
				<p style="margin: 0;">
					<?php
					echo wp_kses_post(
						sprintf(
							/* translators: %s: JetEngine URL */
							__( 'Install <a href="%s" target="_blank">JetEngine</a> to enable structured CCT storage for vital sign measurements. Without JetEngine, vitals are stored in WordPress options as a lightweight fallback.', 'nvoos-content-graph-pro' ),
							'https://crocoblock.com/plugins/jetengine/'
						)
					);
					?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Get supported import formats.
	 *
	 * @return array
	 */
	protected static function get_import_formats() {
		return array(
			'csv'  => 'CSV',
			'vcf'  => 'vCard',
			'json' => 'JSON',
		);
	}

	/**
	 * Process imported data based on format.
	 *
	 * @param mixed  $data   The imported data.
	 * @param string $format The import format.
	 * @return array|WP_Error Processed data or error.
	 */
	protected static function process_import_data( $data, $format ) {
		return new WP_Error( 'not_implemented', __( 'Member import processing coming soon', 'nvoos-content-graph-pro' ) );
	}

	/**
	 * Get validation schema for member data.
	 *
	 * @return array
	 */
	protected static function get_validation_schema() {
		return array(
			'required_fields'    => array(
				'first_name' => __( 'First Name', 'nvoos-content-graph-pro' ),
				'last_name'  => __( 'Last Name', 'nvoos-content-graph-pro' ),
				'email'      => __( 'Email Address', 'nvoos-content-graph-pro' ),
			),
			'recommended_fields' => array(
				'phone'       => __( 'Phone Number', 'nvoos-content-graph-pro' ),
				'address'     => __( 'Address', 'nvoos-content-graph-pro' ),
				'member_type' => __( 'Member Type', 'nvoos-content-graph-pro' ),
				'join_date'   => __( 'Join Date', 'nvoos-content-graph-pro' ),
			),
			'validation_rules'   => array(
				'email'     => array( 'type' => 'email' ),
				'join_date' => array( 'type' => 'datetime' ),
			),
			'quality_dimensions' => array(
				'data_completeness',
				'contact_accuracy',
				'profile_richness',
				'compliance',
			),
		);
	}

	/**
	 * Calculate data completeness percentage.
	 *
	 * @return array
	 */
	protected static function calculate_completeness() {
		$members = get_posts(
			array(
				'post_type'      => 'mcp_ai_member',
				'post_status'    => 'any',
				'posts_per_page' => -1,
			)
		);

		$total    = count( $members );
		$complete = 0;

		foreach ( $members as $member ) {
			$email = get_post_meta( $member->ID, 'email', true );
			$phone = get_post_meta( $member->ID, 'phone', true );
			if ( ! empty( $email ) && ! empty( $phone ) ) {
				++$complete;
			}
		}

		$percentage = $total > 0 ? round( ( $complete / $total ) * 100 ) : 0;

		return array(
			'percentage'  => $percentage,
			'missing'     => array(),
			'suggestions' => array(
				__( 'Add email addresses to all members', 'nvoos-content-graph-pro' ),
				__( 'Include phone numbers for better contact', 'nvoos-content-graph-pro' ),
				__( 'Complete member profiles with addresses', 'nvoos-content-graph-pro' ),
			),
		);
	}

	/**
	 * Get items for data quality review.
	 *
	 * @return array
	 */
	protected static function get_items_for_review() {
		$members = get_posts(
			array(
				'post_type'      => 'mcp_ai_member',
				'post_status'    => 'any',
				'posts_per_page' => 20,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$items = array();
		foreach ( $members as $member ) {
			$items[] = array(
				'id'    => $member->ID,
				'title' => $member->post_title,
				'meta'  => array(
					'email' => get_post_meta( $member->ID, 'email', true ),
					'phone' => get_post_meta( $member->ID, 'phone', true ),
					'type'  => get_post_meta( $member->ID, 'member_type', true ),
				),
			);
		}

		return $items;
	}

	/**
	 * Calculate quality score for an item.
	 *
	 * @param array $item The item to score.
	 * @return array
	 */
	protected static function calculate_quality_score( $item ) {
		$score  = 0;
		$issues = array();

		if ( ! empty( $item['meta']['email'] ) && is_email( $item['meta']['email'] ) ) {
			$score += 40;
		} else {
			$issues[] = __( 'Missing or invalid email', 'nvoos-content-graph-pro' );
		}

		if ( ! empty( $item['meta']['phone'] ) ) {
			$score += 30;
		} else {
			$issues[] = __( 'Missing phone number', 'nvoos-content-graph-pro' );
		}

		if ( ! empty( $item['meta']['type'] ) ) {
			$score += 20;
		} else {
			$issues[] = __( 'Missing member type', 'nvoos-content-graph-pro' );
		}

		if ( ! empty( $item['title'] ) && strlen( $item['title'] ) > 5 ) {
			$score += 10;
		} else {
			$issues[] = __( 'Name needs improvement', 'nvoos-content-graph-pro' );
		}

		$level = $score >= 80 ? 'high' : ( $score >= 50 ? 'medium' : 'low' );

		return array(
			'score'  => $score,
			'level'  => $level,
			'status' => 'high' === $level ? __( 'Complete', 'nvoos-content-graph-pro' ) : __( 'Needs Work', 'nvoos-content-graph-pro' ),
			'issues' => $issues,
		);
	}
}

// Check if member research is enabled before initializing.
$member_settings = get_option( 'wp_mcp_ai_member_settings', array() );
if ( ! empty( $member_settings['enable_research'] ) ) {
	new WP_MCP_AI_Member_Research_Page();
}
