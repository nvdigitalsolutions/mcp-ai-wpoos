<?php
/**
 * Security Training System for ISO 27001 Compliance (Control A.6.3).
 *
 * Manages security awareness, education and training programs with
 * role-based training paths and completion tracking.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Security Training System class.
 *
 * Implements ISO 27001:2022 Control A.6.3 - Information Security Awareness, Education and Training.
 */
class WP_MCP_AI_Security_Training {
	/**
	 * Singleton instance.
	 *
	 * @var WP_MCP_AI_Security_Training
	 */
	protected static $instance = null;

	/**
	 * Training roles.
	 *
	 * @var array
	 */
	const TRAINING_ROLES = array(
		'developer'     => 'Developer',
		'administrator' => 'Administrator',
		'security_team' => 'Security Team',
		'support_staff' => 'Support Staff',
		'all_users'     => 'All Users',
	);

	/**
	 * Training module types.
	 *
	 * @var array
	 */
	const MODULE_TYPES = array(
		'awareness'  => 'Security Awareness',
		'technical'  => 'Technical Security',
		'compliance' => 'Compliance Training',
		'incident'   => 'Incident Response',
		'policy'     => 'Policy Training',
	);

	/**
	 * Get singleton instance.
	 *
	 * @return WP_MCP_AI_Security_Training
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	protected function __construct() {
		$this->init_hooks();
	}

	/**
	 * Initialize WordPress hooks.
	 */
	protected function init_hooks() {
		// Register custom post type for training modules.
		add_action( 'init', array( $this, 'register_post_types' ) );

		// Create default training modules after WordPress is fully loaded.
		add_action( 'init', array( $this, 'create_default_modules' ) );

		// Schedule annual training reminders.
		add_action( 'wp_mcp_ai_annual_training_reminder', array( $this, 'send_training_reminders' ) );

		if ( ! wp_next_scheduled( 'wp_mcp_ai_annual_training_reminder' ) ) {
			wp_schedule_event( time(), 'daily', 'wp_mcp_ai_annual_training_reminder' );
		}
	}

	/**
	 * Register custom post type for training modules.
	 */
	public function register_post_types() {
		register_post_type(
			'mcp_ai_training',
			array(
				'labels'               => array(
					'name'          => __( 'Security Training', 'mcp-ai-wpoos' ),
					'singular_name' => __( 'Training Module', 'mcp-ai-wpoos' ),
					'add_new'       => __( 'Add Training Module', 'mcp-ai-wpoos' ),
					'add_new_item'  => __( 'Add New Training Module', 'mcp-ai-wpoos' ),
					'edit_item'     => __( 'Edit Training Module', 'mcp-ai-wpoos' ),
				),
				'public'               => false,
				'show_ui'              => true,
				'show_in_menu'         => 'nvoos-pro-dashboard',
				'capability_type'      => 'post',
				'capabilities'         => array(
					'edit_post'          => 'manage_options',
					'edit_posts'         => 'manage_options',
					'edit_others_posts'  => 'manage_options',
					'publish_posts'      => 'manage_options',
					'read_post'          => 'read',
					'read_private_posts' => 'manage_options',
					'delete_post'        => 'manage_options',
				),
				'supports'             => array( 'title', 'editor', 'excerpt' ),
				'register_meta_box_cb' => array( $this, 'add_meta_boxes' ),
			)
		);
	}

	/**
	 * Add meta boxes for training modules.
	 */
	public function add_meta_boxes() {
		add_meta_box(
			'mcp_ai_training_details',
			__( 'Training Details', 'mcp-ai-wpoos' ),
			array( $this, 'render_training_details_meta_box' ),
			'mcp_ai_training',
			'normal',
			'high'
		);
	}

	/**
	 * Render training details meta box.
	 *
	 * @param WP_Post $post Post object.
	 */
	public function render_training_details_meta_box( $post ) {
		wp_nonce_field( 'mcp_ai_training_details', 'mcp_ai_training_details_nonce' );

		$role_required = get_post_meta( $post->ID, '_training_role', true );
		$module_type   = get_post_meta( $post->ID, '_training_type', true );
		$duration      = get_post_meta( $post->ID, '_training_duration', true );
		$mandatory     = get_post_meta( $post->ID, '_training_mandatory', true );

		?>
		<table class="form-table">
			<tr>
				<th><label for="training_role"><?php esc_html_e( 'Target Role', 'mcp-ai-wpoos' ); ?></label></th>
				<td>
					<select name="training_role" id="training_role">
						<?php foreach ( self::TRAINING_ROLES as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $role_required, $key ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="training_type"><?php esc_html_e( 'Module Type', 'mcp-ai-wpoos' ); ?></label></th>
				<td>
					<select name="training_type" id="training_type">
						<?php foreach ( self::MODULE_TYPES as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $module_type, $key ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="training_duration"><?php esc_html_e( 'Duration (minutes)', 'mcp-ai-wpoos' ); ?></label></th>
				<td>
					<input type="number" name="training_duration" id="training_duration" value="<?php echo esc_attr( $duration ); ?>" min="1" />
				</td>
			</tr>
			<tr>
				<th><label for="training_mandatory"><?php esc_html_e( 'Mandatory', 'mcp-ai-wpoos' ); ?></label></th>
				<td>
					<input type="checkbox" name="training_mandatory" id="training_mandatory" value="1" <?php checked( $mandatory, '1' ); ?> />
					<span class="description"><?php esc_html_e( 'Required for compliance', 'mcp-ai-wpoos' ); ?></span>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Create default training modules.
	 */
	public function create_default_modules() {
		// Only create once.
		if ( get_option( 'wp_mcp_ai_training_modules_created' ) ) {
			return;
		}

		$modules = array(
			array(
				'title'     => 'ISO 27001 Security Awareness',
				'content'   => $this->get_iso27001_content(),
				'role'      => 'all_users',
				'type'      => 'awareness',
				'duration'  => 30,
				'mandatory' => true,
			),
			array(
				'title'     => 'Secure Coding Practices',
				'content'   => $this->get_secure_coding_content(),
				'role'      => 'developer',
				'type'      => 'technical',
				'duration'  => 60,
				'mandatory' => true,
			),
			array(
				'title'     => 'WordPress Security Best Practices',
				'content'   => $this->get_wordpress_security_content(),
				'role'      => 'administrator',
				'type'      => 'technical',
				'duration'  => 45,
				'mandatory' => true,
			),
			array(
				'title'     => 'Incident Response Procedures',
				'content'   => $this->get_incident_response_content(),
				'role'      => 'security_team',
				'type'      => 'incident',
				'duration'  => 45,
				'mandatory' => true,
			),
			array(
				'title'     => 'Data Protection and Privacy',
				'content'   => $this->get_data_protection_content(),
				'role'      => 'all_users',
				'type'      => 'compliance',
				'duration'  => 30,
				'mandatory' => true,
			),
		);

		foreach ( $modules as $module ) {
			$this->create_training_module( $module );
		}

		update_option( 'wp_mcp_ai_training_modules_created', true );
	}

	/**
	 * Create a training module.
	 *
	 * @param array $data Module data.
	 * @return int|WP_Error Post ID or error.
	 */
	protected function create_training_module( $data ) {
		$post_id = wp_insert_post(
			array(
				'post_type'    => 'mcp_ai_training',
				'post_title'   => $data['title'],
				'post_content' => $data['content'],
				'post_status'  => 'publish',
			)
		);

		if ( ! is_wp_error( $post_id ) ) {
			update_post_meta( $post_id, '_training_role', $data['role'] );
			update_post_meta( $post_id, '_training_type', $data['type'] );
			update_post_meta( $post_id, '_training_duration', $data['duration'] );
			update_post_meta( $post_id, '_training_mandatory', $data['mandatory'] ? '1' : '0' );
		}

		return $post_id;
	}

	/**
	 * Record training completion.
	 *
	 * @param int $user_id User ID.
	 * @param int $module_id Training module ID.
	 * @param int $score Score (optional).
	 * @return bool Success status.
	 */
	public function record_completion( $user_id, $module_id, $score = null ) {
		$completions = get_user_meta( $user_id, 'wp_mcp_ai_training_completions', true );
		if ( ! is_array( $completions ) ) {
			$completions = array();
		}

		$completions[ $module_id ] = array(
			'completed_at' => gmdate( 'Y-m-d H:i:s' ),
			'score'        => $score,
		);

		update_user_meta( $user_id, 'wp_mcp_ai_training_completions', $completions );

		// Log completion.
		if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
			WP_MCP_AI_Logger::log_event(
				'info',
				'Security training completed',
				array(
					'user_id'   => $user_id,
					'module_id' => $module_id,
					'score'     => $score,
				)
			);
		}

		return true;
	}

	/**
	 * Get user training completions.
	 *
	 * @param int $user_id User ID.
	 * @return array Completions array.
	 */
	public function get_user_completions( $user_id ) {
		$completions = get_user_meta( $user_id, 'wp_mcp_ai_training_completions', true );
		return is_array( $completions ) ? $completions : array();
	}

	/**
	 * Check if user completed training.
	 *
	 * @param int $user_id User ID.
	 * @param int $module_id Training module ID.
	 * @return bool Completion status.
	 */
	public function is_training_completed( $user_id, $module_id ) {
		$completions = $this->get_user_completions( $user_id );
		return isset( $completions[ $module_id ] );
	}

	/**
	 * Get training statistics.
	 *
	 * @return array Training statistics.
	 */
	public function get_training_statistics() {
		$total_modules = wp_count_posts( 'mcp_ai_training' )->publish;
		$total_users   = count_users();

		// Get completion counts.
		$completion_count = 0;
		$users            = get_users();
		foreach ( $users as $user ) {
			$completions       = $this->get_user_completions( $user->ID );
			$completion_count += count( $completions );
		}

		return array(
			'total_modules'     => $total_modules,
			'total_users'       => $total_users['total_users'],
			'total_completions' => $completion_count,
			'completion_rate'   => $total_modules > 0 ? round( ( $completion_count / ( $total_modules * $total_users['total_users'] ) ) * 100, 2 ) : 0,
		);
	}

	/**
	 * Send training reminders.
	 */
	public function send_training_reminders() {
		$users = get_users();

		foreach ( $users as $user ) {
			$last_training = get_user_meta( $user->ID, 'wp_mcp_ai_last_training_reminder', true );

			// Send reminder if more than 365 days since last training or never trained.
			if ( ! $last_training || ( time() - strtotime( $last_training ) ) > YEAR_IN_SECONDS ) {
				$this->send_reminder_email( $user );
				update_user_meta( $user->ID, 'wp_mcp_ai_last_training_reminder', gmdate( 'Y-m-d H:i:s' ) );
			}
		}
	}

	/**
	 * Send reminder email to user.
	 *
	 * @param WP_User $user User object.
	 */
	protected function send_reminder_email( $user ) {
		$subject = __( 'Annual Security Training Reminder', 'mcp-ai-wpoos' );
		$message = sprintf(
			/* translators: %s: user display name */
			__(
				'Hi %1$s,

This is a reminder that your annual security training is due. Please complete the required training modules at your earliest convenience.

You can access your training dashboard at: %2$s

Thank you for helping maintain our security standards.

Best regards,
Security Team',
				'mcp-ai-wpoos'
			),
			$user->display_name,
			admin_url( 'admin.php?page=nvoos-security-training' )
		);

		wp_mail( $user->user_email, $subject, $message );
	}

	/**
	 * Get ISO 27001 training content.
	 *
	 * @return string Training content.
	 */
	protected function get_iso27001_content() {
		return '
<h2>ISO 27001 Security Awareness Training</h2>

<h3>Introduction to ISO 27001</h3>
<p>ISO/IEC 27001:2022 is the international standard for information security management systems (ISMS). This training covers the key concepts and your responsibilities.</p>

<h3>Information Security Principles</h3>
<ul>
<li><strong>Confidentiality:</strong> Protecting information from unauthorized access</li>
<li><strong>Integrity:</strong> Maintaining accuracy and completeness of information</li>
<li><strong>Availability:</strong> Ensuring authorized access when needed</li>
</ul>

<h3>Information Classification</h3>
<ul>
<li><strong>Public:</strong> Information intended for public disclosure</li>
<li><strong>Internal:</strong> Information for internal use only</li>
<li><strong>Confidential:</strong> Sensitive business information</li>
<li><strong>Restricted:</strong> Highly sensitive information (API keys, credentials)</li>
</ul>

<h3>Your Responsibilities</h3>
<ul>
<li>Protect API keys and credentials</li>
<li>Use strong, unique passwords</li>
<li>Report security incidents immediately</li>
<li>Follow secure coding practices</li>
<li>Keep software up to date</li>
<li>Be aware of social engineering attacks</li>
</ul>

<h3>Quiz</h3>
<ol>
<li>What are the three principles of information security?</li>
<li>What classification level should API keys be?</li>
<li>What should you do if you suspect a security incident?</li>
</ol>
';
	}

	/**
	 * Get secure coding training content.
	 *
	 * @return string Training content.
	 */
	protected function get_secure_coding_content() {
		return '
<h2>Secure Coding Practices</h2>

<h3>Input Validation and Sanitization</h3>
<ul>
<li>Always sanitize user input: <code>sanitize_text_field()</code>, <code>absint()</code></li>
<li>Validate data types and formats</li>
<li>Use WordPress nonces for form submissions</li>
</ul>

<h3>Output Escaping</h3>
<ul>
<li>Always escape output: <code>esc_html()</code>, <code>esc_url()</code>, <code>esc_attr()</code></li>
<li>Context-aware escaping (HTML, attributes, JavaScript, SQL)</li>
</ul>

<h3>SQL Injection Prevention</h3>
<ul>
<li>Use <code>$wpdb->prepare()</code> for all database queries</li>
<li>Never concatenate user input into SQL queries</li>
</ul>

<h3>Cross-Site Scripting (XSS) Prevention</h3>
<ul>
<li>Escape all output</li>
<li>Use Content Security Policy headers</li>
<li>Sanitize HTML input with <code>wp_kses()</code></li>
</ul>

<h3>Authentication and Authorization</h3>
<ul>
<li>Always check capabilities: <code>current_user_can()</code></li>
<li>Verify nonces for state-changing operations</li>
<li>Use WordPress authentication APIs</li>
</ul>
';
	}

	/**
	 * Get WordPress security training content.
	 *
	 * @return string Training content.
	 */
	protected function get_wordpress_security_content() {
		return '
<h2>WordPress Security Best Practices</h2>

<h3>Core Security</h3>
<ul>
<li>Keep WordPress core updated</li>
<li>Use strong passwords and 2FA</li>
<li>Limit login attempts</li>
<li>Regular backups</li>
</ul>

<h3>Plugin and Theme Security</h3>
<ul>
<li>Only install plugins from trusted sources</li>
<li>Review code before installation</li>
<li>Keep plugins and themes updated</li>
<li>Remove unused plugins and themes</li>
</ul>

<h3>File Permissions</h3>
<ul>
<li>Directories: 755</li>
<li>Files: 644</li>
<li>wp-config.php: 600</li>
</ul>

<h3>Database Security</h3>
<ul>
<li>Use unique database prefix</li>
<li>Regular database backups</li>
<li>Least privilege database user</li>
</ul>
';
	}

	/**
	 * Get incident response training content.
	 *
	 * @return string Training content.
	 */
	protected function get_incident_response_content() {
		return '
<h2>Incident Response Procedures</h2>

<h3>Incident Identification</h3>
<ul>
<li>Unusual system behavior</li>
<li>Unexpected network traffic</li>
<li>Failed login attempts</li>
<li>Data breaches</li>
</ul>

<h3>Response Steps</h3>
<ol>
<li><strong>Contain:</strong> Isolate affected systems</li>
<li><strong>Assess:</strong> Determine scope and impact</li>
<li><strong>Report:</strong> Notify security team and management</li>
<li><strong>Recover:</strong> Restore systems from backups</li>
<li><strong>Review:</strong> Post-incident analysis</li>
</ol>

<h3>Reporting</h3>
<p>All security incidents must be reported to: security@nvdigitalsolutions.com</p>
';
	}

	/**
	 * Get data protection training content.
	 *
	 * @return string Training content.
	 */
	protected function get_data_protection_content() {
		return '
<h2>Data Protection and Privacy</h2>

<h3>Personal Data</h3>
<p>Personal data includes names, email addresses, IP addresses, and any information that can identify an individual.</p>

<h3>Data Protection Principles</h3>
<ul>
<li>Collect only necessary data</li>
<li>Use data only for specified purposes</li>
<li>Keep data secure</li>
<li>Retain data only as long as needed</li>
<li>Respect user rights (access, deletion, portability)</li>
</ul>

<h3>Encryption</h3>
<ul>
<li>Encrypt sensitive data at rest</li>
<li>Use TLS for data in transit</li>
<li>Properly manage encryption keys</li>
</ul>
';
	}

	/**
	 * Prevent cloning.
	 */
	protected function __clone() {}

	/**
	 * Prevent unserialization.
	 */
	public function __wakeup() {} // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore
}
