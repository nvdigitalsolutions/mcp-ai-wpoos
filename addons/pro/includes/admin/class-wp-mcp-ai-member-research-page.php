<?php
/**
 * Member Research & Add Page
 *
 * Provides AI-assisted member creation interface for Health & Wellness.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load base class.
require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-research-add-base.php';

/**
 * Member Research & Add Page
 */
class WP_MCP_AI_Member_Research_Page extends WP_MCP_AI_Research_Add_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->post_type  = 'mcp_ai_member';
		$this->page_title = __( 'Research & Add Members', 'mcp-ai-wpoos-pro' );
		$this->menu_title = __( 'Research & Add', 'mcp-ai-wpoos-pro' );
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
			'members' => __( 'Members', 'mcp-ai-wpoos-pro' ),
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
			'mcp-ai-wpoos-pro'
		);
	}

	/**
	 * Get research prompt suggestions.
	 *
	 * @return array
	 */
	protected function get_research_prompt_suggestions() {
		return array(
			__( 'Create a new family member profile with basic health information', 'mcp-ai-wpoos-pro' ),
			__( 'Add a pet member with breed, age, and health history', 'mcp-ai-wpoos-pro' ),
			__( 'Generate a health summary for existing family members', 'mcp-ai-wpoos-pro' ),
			__( 'Create vaccination schedules for children and pets', 'mcp-ai-wpoos-pro' ),
			__( 'Set up medication reminders for family members', 'mcp-ai-wpoos-pro' ),
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
		?>
		<div class="member-research-tips" style="background: #f0f6fc; border-left: 4px solid #0073aa; padding: 12px 16px; margin: 20px 0;">
			<h4 style="margin-top: 0;"><?php esc_html_e( 'Health & Wellness Tips', 'mcp-ai-wpoos-pro' ); ?></h4>
			<ul style="margin: 8px 0;">
				<li><?php esc_html_e( '✓ Include age, gender, and known health conditions', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( '✓ Track allergies and medication reactions', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( '✓ Record vaccination dates and boosters', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( '✓ Document family medical history', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( '✓ Keep emergency contact information updated', 'mcp-ai-wpoos-pro' ); ?></li>
			</ul>
			<p style="margin-bottom: 0;">
				<strong><?php esc_html_e( 'Privacy Note:', 'mcp-ai-wpoos-pro' ); ?></strong>
				<?php esc_html_e( 'All health data is stored securely and privately. Ensure proper access controls are configured.', 'mcp-ai-wpoos-pro' ); ?>
			</p>
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
		return new WP_Error( 'not_implemented', __( 'Member import processing coming soon', 'mcp-ai-wpoos-pro' ) );
	}

	/**
	 * Get validation schema for member data.
	 *
	 * @return array
	 */
	protected static function get_validation_schema() {
		return array(
			'required_fields'    => array(
				'first_name' => __( 'First Name', 'mcp-ai-wpoos-pro' ),
				'last_name'  => __( 'Last Name', 'mcp-ai-wpoos-pro' ),
				'email'      => __( 'Email Address', 'mcp-ai-wpoos-pro' ),
			),
			'recommended_fields' => array(
				'phone'       => __( 'Phone Number', 'mcp-ai-wpoos-pro' ),
				'address'     => __( 'Address', 'mcp-ai-wpoos-pro' ),
				'member_type' => __( 'Member Type', 'mcp-ai-wpoos-pro' ),
				'join_date'   => __( 'Join Date', 'mcp-ai-wpoos-pro' ),
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
				__( 'Add email addresses to all members', 'mcp-ai-wpoos-pro' ),
				__( 'Include phone numbers for better contact', 'mcp-ai-wpoos-pro' ),
				__( 'Complete member profiles with addresses', 'mcp-ai-wpoos-pro' ),
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
			$issues[] = __( 'Missing or invalid email', 'mcp-ai-wpoos-pro' );
		}

		if ( ! empty( $item['meta']['phone'] ) ) {
			$score += 30;
		} else {
			$issues[] = __( 'Missing phone number', 'mcp-ai-wpoos-pro' );
		}

		if ( ! empty( $item['meta']['type'] ) ) {
			$score += 20;
		} else {
			$issues[] = __( 'Missing member type', 'mcp-ai-wpoos-pro' );
		}

		if ( ! empty( $item['title'] ) && strlen( $item['title'] ) > 5 ) {
			$score += 10;
		} else {
			$issues[] = __( 'Name needs improvement', 'mcp-ai-wpoos-pro' );
		}

		$level = $score >= 80 ? 'high' : ( $score >= 50 ? 'medium' : 'low' );

		return array(
			'score'  => $score,
			'level'  => $level,
			'status' => 'high' === $level ? __( 'Complete', 'mcp-ai-wpoos-pro' ) : __( 'Needs Work', 'mcp-ai-wpoos-pro' ),
			'issues' => $issues,
		);
	}
}

// Check if member research is enabled before initializing.
$member_settings = get_option( 'wp_mcp_ai_member_settings', array() );
if ( ! empty( $member_settings['enable_research'] ) ) {
	new WP_MCP_AI_Member_Research_Page();
}
