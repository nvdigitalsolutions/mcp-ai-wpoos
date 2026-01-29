<?php
/**
 * Security Audit Management System
 *
 * Implements ISO 27001:2022 Control A.5.35 - Independent Review of Information Security
 * Provides quarterly internal audit scheduling, finding tracking, and management review process.
 *
 * @package    WP_MCP_AI
 * @subpackage WP_MCP_AI/includes
 * @since      1.0.0
 */

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Security Audit Management Class
 *
 * Manages internal security audits, findings, and management reviews.
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Security_Audit {

	/**
	 * Singleton instance
	 *
	 * @var WP_MCP_AI_Security_Audit
	 */
	private static $instance = null;

	/**
	 * Audit status constants
	 */
	public const STATUS_SCHEDULED   = 'scheduled';
	public const STATUS_IN_PROGRESS = 'in_progress';
	public const STATUS_COMPLETED   = 'completed';
	public const STATUS_OVERDUE     = 'overdue';

	/**
	 * Finding severity constants
	 */
	public const SEVERITY_CRITICAL    = 'critical';
	public const SEVERITY_HIGH        = 'high';
	public const SEVERITY_MEDIUM      = 'medium';
	public const SEVERITY_LOW         = 'low';
	public const SEVERITY_OBSERVATION = 'observation';

	/**
	 * Finding status constants
	 */
	public const FINDING_OPEN        = 'open';
	public const FINDING_IN_PROGRESS = 'in_progress';
	public const FINDING_RESOLVED    = 'resolved';
	public const FINDING_ACCEPTED    = 'accepted';

	/**
	 * Audit type constants
	 */
	public const TYPE_INTERNAL          = 'internal';
	public const TYPE_EXTERNAL          = 'external';
	public const TYPE_MANAGEMENT_REVIEW = 'management_review';

	/**
	 * Get singleton instance
	 *
	 * @return WP_MCP_AI_Security_Audit
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 */
	private function __construct() {
		$this->register_post_type();
		$this->schedule_audits();
		add_action( 'wp_mcp_ai_quarterly_audit', array( $this, 'trigger_quarterly_audit' ) );
		add_action( 'admin_init', array( $this, 'register_meta_boxes' ) );
		add_action( 'save_post_mcp_ai_audit', array( $this, 'save_audit_meta' ), 10, 2 );
	}

	/**
	 * Register audit custom post type
	 *
	 * @return void
	 */
	public function register_post_type() {
		$labels = array(
			'name'               => __( 'Security Audits', 'mcp-ai-wpoos' ),
			'singular_name'      => __( 'Security Audit', 'mcp-ai-wpoos' ),
			'add_new'            => __( 'Add New Audit', 'mcp-ai-wpoos' ),
			'add_new_item'       => __( 'Add New Security Audit', 'mcp-ai-wpoos' ),
			'edit_item'          => __( 'Edit Security Audit', 'mcp-ai-wpoos' ),
			'new_item'           => __( 'New Security Audit', 'mcp-ai-wpoos' ),
			'view_item'          => __( 'View Security Audit', 'mcp-ai-wpoos' ),
			'search_items'       => __( 'Search Audits', 'mcp-ai-wpoos' ),
			'not_found'          => __( 'No audits found', 'mcp-ai-wpoos' ),
			'not_found_in_trash' => __( 'No audits found in trash', 'mcp-ai-wpoos' ),
			'parent_item_colon'  => __( 'Parent Audit:', 'mcp-ai-wpoos' ),
			'menu_name'          => __( 'Security Audits', 'mcp-ai-wpoos' ),
		);

		$args = array(
			'labels'           => $labels,
			'public'           => false,
			'show_ui'          => true,
			'show_in_menu'     => 'nvoos-pro-dashboard',
			'capability_type'  => 'post',
			'capabilities'     => array(
				'edit_post'          => 'manage_options',
				'read_post'          => 'manage_options',
				'delete_post'        => 'manage_options',
				'edit_posts'         => 'manage_options',
				'edit_others_posts'  => 'manage_options',
				'delete_posts'       => 'manage_options',
				'publish_posts'      => 'manage_options',
				'read_private_posts' => 'manage_options',
			),
			'has_archive'      => false,
			'hierarchical'     => false,
			'menu_position'    => null,
			'menu_icon'        => 'dashicons-shield-alt',
			'supports'         => array( 'title', 'editor', 'author' ),
			'rewrite'          => false,
			'query_var'        => false,
			'can_export'       => true,
			'delete_with_user' => false,
		);

		register_post_type( 'mcp_ai_audit', $args );
	}

	/**
	 * Register meta boxes
	 *
	 * @return void
	 */
	public function register_meta_boxes() {
		add_meta_box(
			'wp_mcp_ai_audit_details',
			__( 'Audit Details', 'mcp-ai-wpoos' ),
			array( $this, 'render_audit_details_meta_box' ),
			'mcp_ai_audit',
			'normal',
			'high'
		);

		add_meta_box(
			'wp_mcp_ai_audit_findings',
			__( 'Audit Findings', 'mcp-ai-wpoos' ),
			array( $this, 'render_audit_findings_meta_box' ),
			'mcp_ai_audit',
			'normal',
			'high'
		);
	}

	/**
	 * Render audit details meta box
	 *
	 * @param WP_Post $post Current post object.
	 * @return void
	 */
	public function render_audit_details_meta_box( $post ) {
		wp_nonce_field( 'wp_mcp_ai_audit_meta', 'wp_mcp_ai_audit_meta_nonce' );

		$audit_date        = get_post_meta( $post->ID, '_wp_mcp_ai_audit_date', true );
		$audit_type        = get_post_meta( $post->ID, '_wp_mcp_ai_audit_type', true );
		$audit_status      = get_post_meta( $post->ID, '_wp_mcp_ai_audit_status', true );
		$auditor           = get_post_meta( $post->ID, '_wp_mcp_ai_auditor', true );
		$scope             = get_post_meta( $post->ID, '_wp_mcp_ai_audit_scope', true );
		$controls_reviewed = get_post_meta( $post->ID, '_wp_mcp_ai_controls_reviewed', true );
		?>
		<table class="form-table">
			<tr>
				<th><label for="wp_mcp_ai_audit_date"><?php esc_html_e( 'Audit Date', 'mcp-ai-wpoos' ); ?></label></th>
				<td>
					<input type="date" id="wp_mcp_ai_audit_date" name="wp_mcp_ai_audit_date"
						value="<?php echo esc_attr( $audit_date ); ?>" class="regular-text" required />
				</td>
			</tr>
			<tr>
				<th><label for="wp_mcp_ai_audit_type"><?php esc_html_e( 'Audit Type', 'mcp-ai-wpoos' ); ?></label></th>
				<td>
					<select id="wp_mcp_ai_audit_type" name="wp_mcp_ai_audit_type" class="regular-text" required>
						<option value=""><?php esc_html_e( 'Select Type', 'mcp-ai-wpoos' ); ?></option>
						<option value="<?php echo esc_attr( self::TYPE_INTERNAL ); ?>" <?php selected( $audit_type, self::TYPE_INTERNAL ); ?>>
							<?php esc_html_e( 'Internal Audit', 'mcp-ai-wpoos' ); ?>
						</option>
						<option value="<?php echo esc_attr( self::TYPE_EXTERNAL ); ?>" <?php selected( $audit_type, self::TYPE_EXTERNAL ); ?>>
							<?php esc_html_e( 'External Audit', 'mcp-ai-wpoos' ); ?>
						</option>
						<option value="<?php echo esc_attr( self::TYPE_MANAGEMENT_REVIEW ); ?>" <?php selected( $audit_type, self::TYPE_MANAGEMENT_REVIEW ); ?>>
							<?php esc_html_e( 'Management Review', 'mcp-ai-wpoos' ); ?>
						</option>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="wp_mcp_ai_audit_status"><?php esc_html_e( 'Status', 'mcp-ai-wpoos' ); ?></label></th>
				<td>
					<select id="wp_mcp_ai_audit_status" name="wp_mcp_ai_audit_status" class="regular-text" required>
						<option value=""><?php esc_html_e( 'Select Status', 'mcp-ai-wpoos' ); ?></option>
						<option value="<?php echo esc_attr( self::STATUS_SCHEDULED ); ?>" <?php selected( $audit_status, self::STATUS_SCHEDULED ); ?>>
							<?php esc_html_e( 'Scheduled', 'mcp-ai-wpoos' ); ?>
						</option>
						<option value="<?php echo esc_attr( self::STATUS_IN_PROGRESS ); ?>" <?php selected( $audit_status, self::STATUS_IN_PROGRESS ); ?>>
							<?php esc_html_e( 'In Progress', 'mcp-ai-wpoos' ); ?>
						</option>
						<option value="<?php echo esc_attr( self::STATUS_COMPLETED ); ?>" <?php selected( $audit_status, self::STATUS_COMPLETED ); ?>>
							<?php esc_html_e( 'Completed', 'mcp-ai-wpoos' ); ?>
						</option>
						<option value="<?php echo esc_attr( self::STATUS_OVERDUE ); ?>" <?php selected( $audit_status, self::STATUS_OVERDUE ); ?>>
							<?php esc_html_e( 'Overdue', 'mcp-ai-wpoos' ); ?>
						</option>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="wp_mcp_ai_auditor"><?php esc_html_e( 'Auditor', 'mcp-ai-wpoos' ); ?></label></th>
				<td>
					<input type="text" id="wp_mcp_ai_auditor" name="wp_mcp_ai_auditor"
						value="<?php echo esc_attr( $auditor ); ?>" class="regular-text" />
					<p class="description"><?php esc_html_e( 'Name of the internal or external auditor', 'mcp-ai-wpoos' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="wp_mcp_ai_audit_scope"><?php esc_html_e( 'Audit Scope', 'mcp-ai-wpoos' ); ?></label></th>
				<td>
					<textarea id="wp_mcp_ai_audit_scope" name="wp_mcp_ai_audit_scope"
						class="large-text" rows="4"><?php echo esc_textarea( $scope ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Controls, processes, or areas included in this audit', 'mcp-ai-wpoos' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="wp_mcp_ai_controls_reviewed"><?php esc_html_e( 'Controls Reviewed', 'mcp-ai-wpoos' ); ?></label></th>
				<td>
					<input type="number" id="wp_mcp_ai_controls_reviewed" name="wp_mcp_ai_controls_reviewed"
						value="<?php echo esc_attr( $controls_reviewed ); ?>" min="0" max="93" class="small-text" />
					<p class="description"><?php esc_html_e( 'Number of ISO 27001 controls reviewed', 'mcp-ai-wpoos' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Render audit findings meta box
	 *
	 * @param WP_Post $post Current post object.
	 * @return void
	 */
	public function render_audit_findings_meta_box( $post ) {
		$findings = get_post_meta( $post->ID, '_wp_mcp_ai_audit_findings', true );
		if ( ! is_array( $findings ) ) {
			$findings = array();
		}
		?>
		<div id="wp-mcp-ai-audit-findings-container">
			<p>
				<button type="button" class="button" id="wp-mcp-ai-add-finding">
					<?php esc_html_e( 'Add Finding', 'mcp-ai-wpoos' ); ?>
				</button>
			</p>
			<div id="wp-mcp-ai-findings-list">
				<?php foreach ( $findings as $index => $finding ) : ?>
					<?php $this->render_finding_row( $index, $finding ); ?>
				<?php endforeach; ?>
			</div>
		</div>
		<script type="text/javascript">
		jQuery(document).ready(function($) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Integer count value is safe.
			let findingIndex = <?php echo count( $findings ); ?>;

			$('#wp-mcp-ai-add-finding').on('click', function() {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Template HTML is properly escaped in get_finding_template().
				const template = `<?php echo $this->get_finding_template(); ?>`.replace(/INDEX/g, findingIndex);
				$('#wp-mcp-ai-findings-list').append(template);
				findingIndex++;
			});

			$(document).on('click', '.wp-mcp-ai-remove-finding', function() {
				if (confirm('<?php esc_html_e( 'Are you sure you want to remove this finding?', 'mcp-ai-wpoos' ); ?>')) {
					$(this).closest('.wp-mcp-ai-finding-row').remove();
				}
			});
		});
		</script>
		<style>
		.wp-mcp-ai-finding-row {
			border: 1px solid #ccd0d4;
			padding: 15px;
			margin-bottom: 15px;
			background: #f9f9f9;
		}
		.wp-mcp-ai-finding-row h4 {
			margin-top: 0;
		}
		.wp-mcp-ai-finding-field {
			margin-bottom: 10px;
		}
		.wp-mcp-ai-finding-field label {
			display: inline-block;
			width: 150px;
			font-weight: 600;
		}
		</style>
		<?php
	}

	/**
	 * Render a single finding row
	 *
	 * @param int   $index Index of the finding.
	 * @param array $finding Finding data.
	 * @return void
	 */
	private function render_finding_row( $index, $finding ) {
		$control        = isset( $finding['control'] ) ? $finding['control'] : '';
		$severity       = isset( $finding['severity'] ) ? $finding['severity'] : '';
		$status         = isset( $finding['status'] ) ? $finding['status'] : '';
		$description    = isset( $finding['description'] ) ? $finding['description'] : '';
		$recommendation = isset( $finding['recommendation'] ) ? $finding['recommendation'] : '';
		$due_date       = isset( $finding['due_date'] ) ? $finding['due_date'] : '';
		?>
		<div class="wp-mcp-ai-finding-row">
			<h4>
				<?php /* translators: %d: Finding number */ ?>
				<?php printf( esc_html__( 'Finding #%d', 'mcp-ai-wpoos' ), $index + 1 ); ?>
				<button type="button" class="button button-small wp-mcp-ai-remove-finding" style="float: right;">
					<?php esc_html_e( 'Remove', 'mcp-ai-wpoos' ); ?>
				</button>
			</h4>
			<div class="wp-mcp-ai-finding-field">
				<label><?php esc_html_e( 'Control ID', 'mcp-ai-wpoos' ); ?></label>
				<input type="text" name="wp_mcp_ai_findings[<?php echo esc_attr( $index ); ?>][control]"
					value="<?php echo esc_attr( $control ); ?>" class="regular-text"
					placeholder="A.5.1" />
			</div>
			<div class="wp-mcp-ai-finding-field">
				<label><?php esc_html_e( 'Severity', 'mcp-ai-wpoos' ); ?></label>
				<select name="wp_mcp_ai_findings[<?php echo esc_attr( $index ); ?>][severity]" class="regular-text">
					<option value=""><?php esc_html_e( 'Select Severity', 'mcp-ai-wpoos' ); ?></option>
					<option value="<?php echo esc_attr( self::SEVERITY_CRITICAL ); ?>" <?php selected( $severity, self::SEVERITY_CRITICAL ); ?>>
						<?php esc_html_e( 'Critical', 'mcp-ai-wpoos' ); ?>
					</option>
					<option value="<?php echo esc_attr( self::SEVERITY_HIGH ); ?>" <?php selected( $severity, self::SEVERITY_HIGH ); ?>>
						<?php esc_html_e( 'High', 'mcp-ai-wpoos' ); ?>
					</option>
					<option value="<?php echo esc_attr( self::SEVERITY_MEDIUM ); ?>" <?php selected( $severity, self::SEVERITY_MEDIUM ); ?>>
						<?php esc_html_e( 'Medium', 'mcp-ai-wpoos' ); ?>
					</option>
					<option value="<?php echo esc_attr( self::SEVERITY_LOW ); ?>" <?php selected( $severity, self::SEVERITY_LOW ); ?>>
						<?php esc_html_e( 'Low', 'mcp-ai-wpoos' ); ?>
					</option>
					<option value="<?php echo esc_attr( self::SEVERITY_OBSERVATION ); ?>" <?php selected( $severity, self::SEVERITY_OBSERVATION ); ?>>
						<?php esc_html_e( 'Observation', 'mcp-ai-wpoos' ); ?>
					</option>
				</select>
			</div>
			<div class="wp-mcp-ai-finding-field">
				<label><?php esc_html_e( 'Status', 'mcp-ai-wpoos' ); ?></label>
				<select name="wp_mcp_ai_findings[<?php echo esc_attr( $index ); ?>][status]" class="regular-text">
					<option value=""><?php esc_html_e( 'Select Status', 'mcp-ai-wpoos' ); ?></option>
					<option value="<?php echo esc_attr( self::FINDING_OPEN ); ?>" <?php selected( $status, self::FINDING_OPEN ); ?>>
						<?php esc_html_e( 'Open', 'mcp-ai-wpoos' ); ?>
					</option>
					<option value="<?php echo esc_attr( self::FINDING_IN_PROGRESS ); ?>" <?php selected( $status, self::FINDING_IN_PROGRESS ); ?>>
						<?php esc_html_e( 'In Progress', 'mcp-ai-wpoos' ); ?>
					</option>
					<option value="<?php echo esc_attr( self::FINDING_RESOLVED ); ?>" <?php selected( $status, self::FINDING_RESOLVED ); ?>>
						<?php esc_html_e( 'Resolved', 'mcp-ai-wpoos' ); ?>
					</option>
					<option value="<?php echo esc_attr( self::FINDING_ACCEPTED ); ?>" <?php selected( $status, self::FINDING_ACCEPTED ); ?>>
						<?php esc_html_e( 'Accepted Risk', 'mcp-ai-wpoos' ); ?>
					</option>
				</select>
			</div>
			<div class="wp-mcp-ai-finding-field">
				<label><?php esc_html_e( 'Description', 'mcp-ai-wpoos' ); ?></label>
				<textarea name="wp_mcp_ai_findings[<?php echo esc_attr( $index ); ?>][description]"
					class="large-text" rows="3"><?php echo esc_textarea( $description ); ?></textarea>
			</div>
			<div class="wp-mcp-ai-finding-field">
				<label><?php esc_html_e( 'Recommendation', 'mcp-ai-wpoos' ); ?></label>
				<textarea name="wp_mcp_ai_findings[<?php echo esc_attr( $index ); ?>][recommendation]"
					class="large-text" rows="3"><?php echo esc_textarea( $recommendation ); ?></textarea>
			</div>
			<div class="wp-mcp-ai-finding-field">
				<label><?php esc_html_e( 'Due Date', 'mcp-ai-wpoos' ); ?></label>
				<input type="date" name="wp_mcp_ai_findings[<?php echo esc_attr( $index ); ?>][due_date]"
					value="<?php echo esc_attr( $due_date ); ?>" class="regular-text" />
			</div>
		</div>
		<?php
	}

	/**
	 * Get finding template for JavaScript
	 *
	 * @return string
	 */
	private function get_finding_template() {
		ob_start();
		?>
		<div class="wp-mcp-ai-finding-row">
			<h4>
				Finding #<span class="finding-number">INDEX+1</span>
				<button type="button" class="button button-small wp-mcp-ai-remove-finding" style="float: right;">
					Remove
				</button>
			</h4>
			<div class="wp-mcp-ai-finding-field">
				<label>Control ID</label>
				<input type="text" name="wp_mcp_ai_findings[INDEX][control]" class="regular-text" placeholder="A.5.1" />
			</div>
			<div class="wp-mcp-ai-finding-field">
				<label>Severity</label>
				<select name="wp_mcp_ai_findings[INDEX][severity]" class="regular-text">
					<option value="">Select Severity</option>
					<option value="critical">Critical</option>
					<option value="high">High</option>
					<option value="medium">Medium</option>
					<option value="low">Low</option>
					<option value="observation">Observation</option>
				</select>
			</div>
			<div class="wp-mcp-ai-finding-field">
				<label>Status</label>
				<select name="wp_mcp_ai_findings[INDEX][status]" class="regular-text">
					<option value="">Select Status</option>
					<option value="open">Open</option>
					<option value="in_progress">In Progress</option>
					<option value="resolved">Resolved</option>
					<option value="accepted">Accepted Risk</option>
				</select>
			</div>
			<div class="wp-mcp-ai-finding-field">
				<label>Description</label>
				<textarea name="wp_mcp_ai_findings[INDEX][description]" class="large-text" rows="3"></textarea>
			</div>
			<div class="wp-mcp-ai-finding-field">
				<label>Recommendation</label>
				<textarea name="wp_mcp_ai_findings[INDEX][recommendation]" class="large-text" rows="3"></textarea>
			</div>
			<div class="wp-mcp-ai-finding-field">
				<label>Due Date</label>
				<input type="date" name="wp_mcp_ai_findings[INDEX][due_date]" class="regular-text" />
			</div>
		</div>
		<?php
		$template = ob_get_clean();
		return str_replace( array( "\n", "\r", "\t" ), '', $template );
	}

	/**
	 * Save audit meta data
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @return void
	 */
	public function save_audit_meta( $post_id, $post ) {
		// Verify nonce.
		if ( ! isset( $_POST['wp_mcp_ai_audit_meta_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_audit_meta_nonce'] ) ), 'wp_mcp_ai_audit_meta' ) ) {
			return;
		}

		// Check autosave.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Check permissions.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Save audit date.
		if ( isset( $_POST['wp_mcp_ai_audit_date'] ) ) {
			update_post_meta( $post_id, '_wp_mcp_ai_audit_date', sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_audit_date'] ) ) );
		}

		// Save audit type.
		if ( isset( $_POST['wp_mcp_ai_audit_type'] ) ) {
			update_post_meta( $post_id, '_wp_mcp_ai_audit_type', sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_audit_type'] ) ) );
		}

		// Save audit status.
		if ( isset( $_POST['wp_mcp_ai_audit_status'] ) ) {
			update_post_meta( $post_id, '_wp_mcp_ai_audit_status', sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_audit_status'] ) ) );
		}

		// Save auditor.
		if ( isset( $_POST['wp_mcp_ai_auditor'] ) ) {
			update_post_meta( $post_id, '_wp_mcp_ai_auditor', sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_auditor'] ) ) );
		}

		// Save audit scope.
		if ( isset( $_POST['wp_mcp_ai_audit_scope'] ) ) {
			update_post_meta( $post_id, '_wp_mcp_ai_audit_scope', sanitize_textarea_field( wp_unslash( $_POST['wp_mcp_ai_audit_scope'] ) ) );
		}

		// Save controls reviewed.
		if ( isset( $_POST['wp_mcp_ai_controls_reviewed'] ) ) {
			update_post_meta( $post_id, '_wp_mcp_ai_controls_reviewed', absint( $_POST['wp_mcp_ai_controls_reviewed'] ) );
		}

		// Save findings.
		if ( isset( $_POST['wp_mcp_ai_findings'] ) && is_array( $_POST['wp_mcp_ai_findings'] ) ) {
			$findings = array();
			foreach ( $_POST['wp_mcp_ai_findings'] as $finding ) {
				$findings[] = array(
					'control'        => isset( $finding['control'] ) ? sanitize_text_field( wp_unslash( $finding['control'] ) ) : '',
					'severity'       => isset( $finding['severity'] ) ? sanitize_text_field( wp_unslash( $finding['severity'] ) ) : '',
					'status'         => isset( $finding['status'] ) ? sanitize_text_field( wp_unslash( $finding['status'] ) ) : '',
					'description'    => isset( $finding['description'] ) ? sanitize_textarea_field( wp_unslash( $finding['description'] ) ) : '',
					'recommendation' => isset( $finding['recommendation'] ) ? sanitize_textarea_field( wp_unslash( $finding['recommendation'] ) ) : '',
					'due_date'       => isset( $finding['due_date'] ) ? sanitize_text_field( wp_unslash( $finding['due_date'] ) ) : '',
				);
			}
			update_post_meta( $post_id, '_wp_mcp_ai_audit_findings', $findings );
		}
	}

	/**
	 * Schedule quarterly audits
	 *
	 * @return void
	 */
	private function schedule_audits() {
		if ( ! wp_next_scheduled( 'wp_mcp_ai_quarterly_audit' ) ) {
			// Schedule for first day of next quarter.
			$next_quarter = $this->get_next_quarter_start();
			wp_schedule_event( $next_quarter, 'quarterly', 'wp_mcp_ai_quarterly_audit' );
		}
	}

	/**
	 * Get next quarter start timestamp
	 *
	 * @return int
	 */
	private function get_next_quarter_start() {
		$current_month = (int) gmdate( 'n' );
		$current_year  = (int) gmdate( 'Y' );

		// Determine next quarter start month.
		if ( $current_month <= 3 ) {
			$next_quarter_month = 4;
			$next_quarter_year  = $current_year;
		} elseif ( $current_month <= 6 ) {
			$next_quarter_month = 7;
			$next_quarter_year  = $current_year;
		} elseif ( $current_month <= 9 ) {
			$next_quarter_month = 10;
			$next_quarter_year  = $current_year;
		} else {
			$next_quarter_month = 1;
			$next_quarter_year  = $current_year + 1;
		}

		return strtotime( sprintf( '%d-%02d-01 00:00:00', $next_quarter_year, $next_quarter_month ) );
	}

	/**
	 * Trigger quarterly audit notification
	 *
	 * @return void
	 */
	public function trigger_quarterly_audit() {
		// Create scheduled audit post.
		$audit_id = wp_insert_post(
			array(
				'post_title'   => sprintf(
					/* translators: %s: Quarter and year */
					__( 'Quarterly Security Audit - %s', 'mcp-ai-wpoos' ),
					gmdate( 'Q Y' )
				),
				'post_type'    => 'mcp_ai_audit',
				'post_status'  => 'draft',
				'post_content' => __( 'Scheduled quarterly internal security audit as per ISO 27001:2022 Control A.5.35', 'mcp-ai-wpoos' ),
			)
		);

		if ( $audit_id && ! is_wp_error( $audit_id ) ) {
			update_post_meta( $audit_id, '_wp_mcp_ai_audit_date', gmdate( 'Y-m-d' ) );
			update_post_meta( $audit_id, '_wp_mcp_ai_audit_type', self::TYPE_INTERNAL );
			update_post_meta( $audit_id, '_wp_mcp_ai_audit_status', self::STATUS_SCHEDULED );

			// Send notification to administrators.
			$this->notify_audit_scheduled( $audit_id );
		}
	}

	/**
	 * Notify administrators of scheduled audit
	 *
	 * @param int $audit_id Audit post ID.
	 * @return void
	 */
	private function notify_audit_scheduled( $audit_id ) {
		$admin_email = get_option( 'admin_email' );
		$subject     = sprintf(
			/* translators: %s: Site name */
			__( '[%s] Quarterly Security Audit Scheduled', 'mcp-ai-wpoos' ),
			get_bloginfo( 'name' )
		);
		$message = sprintf(
			/* translators: 1: Edit URL */
			__( "A quarterly security audit has been scheduled.\n\nPlease review and complete the audit at:\n%s", 'mcp-ai-wpoos' ),
			get_edit_post_link( $audit_id )
		);

		wp_mail( $admin_email, $subject, $message );
	}

	/**
	 * Get audit statistics
	 *
	 * @return array
	 */
	public function get_audit_statistics() {
		$args = array(
			'post_type'      => 'mcp_ai_audit',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		);

		$audits         = get_posts( $args );
		$total_audits   = count( $audits );
		$completed      = 0;
		$in_progress    = 0;
		$scheduled      = 0;
		$overdue        = 0;
		$total_findings = 0;
		$open_findings  = 0;

		foreach ( $audits as $audit_id ) {
			$status = get_post_meta( $audit_id, '_wp_mcp_ai_audit_status', true );

			switch ( $status ) {
				case self::STATUS_COMPLETED:
					++$completed;
					break;
				case self::STATUS_IN_PROGRESS:
					++$in_progress;
					break;
				case self::STATUS_SCHEDULED:
					++$scheduled;
					break;
				case self::STATUS_OVERDUE:
					++$overdue;
					break;
			}

			$findings = get_post_meta( $audit_id, '_wp_mcp_ai_audit_findings', true );
			if ( is_array( $findings ) ) {
				$total_findings += count( $findings );
				foreach ( $findings as $finding ) {
					if ( isset( $finding['status'] ) && self::FINDING_OPEN === $finding['status'] ) {
						++$open_findings;
					}
				}
			}
		}

		return array(
			'total_audits'   => $total_audits,
			'completed'      => $completed,
			'in_progress'    => $in_progress,
			'scheduled'      => $scheduled,
			'overdue'        => $overdue,
			'total_findings' => $total_findings,
			'open_findings'  => $open_findings,
		);
	}

	/**
	 * Get recent audits
	 *
	 * @param int $limit Number of audits to retrieve.
	 * @return array
	 */
	public function get_recent_audits( $limit = 5 ) {
		$args = array(
			'post_type'      => 'mcp_ai_audit',
			'post_status'    => 'any',
			'posts_per_page' => $limit,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		return get_posts( $args );
	}
}
