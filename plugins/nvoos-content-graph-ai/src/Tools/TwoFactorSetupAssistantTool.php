<?php
/**
 * 2FA Setup Assistant tool (D8 Cluster 2c port of the base plugin's
 * WP_MCP_AI_Tool_2FA_Setup_Assistant — byte-identical slug, schema,
 * error codes, envelope, user-meta keys, and QR generation;
 * per-mode hook seams via WordPressNativeTrait).
 *
 * @package NvoosContentGraphAi\Tools
 * @since   1.0.4
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tools;

/**
 * Guides users through two-factor authentication setup with TOTP,
 * email, or SMS methods, QR codes, and backup codes.
 */
class TwoFactorSetupAssistantTool extends AbstractAiTool {

	use WordPressNativeTrait;

	public function getSlug(): string {
		return '2fa_setup_assistant';
	}

	public function getName(): string {
		return __( '2FA Setup Assistant', 'nvoos-content-graph-ai' );
	}

	public function getDescription(): string {
		return __( 'Guides users through two-factor authentication setup with TOTP, email, or SMS methods. Includes QR code generation and backup codes.', 'nvoos-content-graph-ai' );
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'action'       => array(
					'type'        => 'string',
					'description' => __( 'Action to perform: setup, status, enable, disable, generate_backup, or bulk_enforce', 'nvoos-content-graph-ai' ),
					'required'    => true,
					'enum'        => array( 'setup', 'status', 'enable', 'disable', 'generate_backup', 'bulk_enforce' ),
				),
				'user_id'      => array(
					'type'        => 'integer',
					'description' => __( 'User ID (defaults to current user)', 'nvoos-content-graph-ai' ),
					'required'    => false,
				),
				'method'       => array(
					'type'        => 'string',
					'description' => __( 'Authentication method: totp, email, or sms', 'nvoos-content-graph-ai' ),
					'default'     => 'totp',
					'enum'        => array( 'totp', 'email', 'sms' ),
				),
				'role'         => array(
					'type'        => 'string',
					'description' => __( 'User role for bulk enforcement', 'nvoos-content-graph-ai' ),
					'required'    => false,
				),
				'phone_number' => array(
					'type'        => 'string',
					'description' => __( 'Phone number for SMS method', 'nvoos-content-graph-ai' ),
					'required'    => false,
				),
				'force_reset'  => array(
					'type'        => 'boolean',
					'description' => __( 'Force users to set up 2FA on next login', 'nvoos-content-graph-ai' ),
					'default'     => false,
				),
			),
			'required'             => array( 'action' ),
			'additionalProperties' => false,
		);
	}

	public function getRequiredCapability(): string {
		return 'edit_posts';
	}

	public function getCapabilityFlags(): array {
		return array( 'external-api', 'state-changing', 'requires-capability' );
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		// Validate parameters (two-gate: sanitize at entry).
		$action       = isset( $arguments['action'] ) ? sanitize_text_field( $arguments['action'] ) : 'setup';
		$user_id      = isset( $arguments['user_id'] ) ? absint( $arguments['user_id'] ) : get_current_user_id();
		$method       = isset( $arguments['method'] ) ? sanitize_text_field( $arguments['method'] ) : 'totp';
		$role         = isset( $arguments['role'] ) ? sanitize_text_field( $arguments['role'] ) : '';
		$phone_number = isset( $arguments['phone_number'] ) ? sanitize_text_field( $arguments['phone_number'] ) : '';
		$force_reset  = isset( $arguments['force_reset'] ) ? (bool) $arguments['force_reset'] : false;

		// Before execution hook (base-identical).
		$intercepted = $this->do_before_execute( $arguments, $context );
		if ( null !== $intercepted ) {
			return $intercepted;
		}

		// Route to action handler.
		switch ( $action ) {
			case 'setup':
				$result = $this->handle_setup( $user_id, $method, $phone_number, $context );
				break;

			case 'status':
				$result = $this->handle_status( $user_id );
				break;

			case 'enable':
				$result = $this->handle_enable( $user_id, $method );
				break;

			case 'disable':
				$result = $this->handle_disable( $user_id );
				break;

			case 'generate_backup':
				$result = $this->handle_generate_backup( $user_id );
				break;

			case 'bulk_enforce':
				$result = $this->handle_bulk_enforce( $role, $force_reset );
				break;

			default:
				$result = new \WP_Error(
					'wp_mcp_ai_error',
					__( 'Invalid action specified', 'nvoos-content-graph-ai' )
				);
		}

		// After execution hook (base-identical).
		$this->do_after_execute( $result, $arguments, $context );

		return $this->apply_result_filter( $result, $arguments, $context );
	}

	/**
	 * Handle setup action (base-identical).
	 *
	 * @param int    $user_id      User ID.
	 * @param string $method       2FA method.
	 * @param string $phone_number Phone number for SMS.
	 * @param array  $context      Execution context.
	 * @return array Setup result.
	 */
	private function handle_setup( $user_id, $method, $phone_number, $context ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Context reserved for the tool interface.
		// Verify user.
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return new \WP_Error(
				'wp_mcp_ai_error',
				__( 'User not found', 'nvoos-content-graph-ai' )
			);
		}

		// Check permissions.
		if ( get_current_user_id() !== $user_id && ! current_user_can( 'edit_users' ) ) {
			return new \WP_Error(
				'wp_mcp_ai_error',
				__( 'Permission denied', 'nvoos-content-graph-ai' )
			);
		}

		$result = array(
			'success' => true,
			'user_id' => $user_id,
			'method'  => $method,
		);

		switch ( $method ) {
			case 'totp':
				$totp_data = $this->setup_totp( $user_id, $user );
				$result    = array_merge( $result, $totp_data );
				break;

			case 'email':
				$email_data = $this->setup_email( $user_id, $user );
				$result     = array_merge( $result, $email_data );
				break;

			case 'sms':
				$sms_data = $this->setup_sms( $user_id, $user, $phone_number );
				if ( is_wp_error( $sms_data ) ) {
					return $sms_data;
				}
				$result = array_merge( $result, $sms_data );
				break;
		}

		// Generate backup codes.
		$backup_codes           = $this->generate_backup_codes( $user_id );
		$result['backup_codes'] = $backup_codes;

		// Check for plugin integration.
		$result['plugin_support'] = $this->check_plugin_support();

		// Add setup instructions.
		$result['instructions'] = $this->get_setup_instructions( $method );

		return $result;
	}

	/**
	 * Setup TOTP (base-identical: secret, QR data URI, manual entry).
	 *
	 * @param int      $user_id User ID.
	 * @param \WP_User $user    User object.
	 * @return array TOTP setup data.
	 */
	private function setup_totp( $user_id, $user ) {
		// Generate secret key.
		$secret = $this->generate_totp_secret();

		// Store secret (base-identical user-meta keys).
		update_user_meta( $user_id, 'wp_mcp_ai_2fa_totp_secret', $secret );
		update_user_meta( $user_id, 'wp_mcp_ai_2fa_method', 'totp' );
		update_user_meta( $user_id, 'wp_mcp_ai_2fa_enabled', false ); // Not enabled until verified.

		// Generate QR code data.
		$issuer  = get_bloginfo( 'name' );
		$account = $user->user_email;
		$qr_data = sprintf(
			'otpauth://totp/%s:%s?secret=%s&issuer=%s',
			rawurlencode( $issuer ),
			rawurlencode( $account ),
			$secret,
			rawurlencode( $issuer )
		);

		// Server-side QR fetch keeps the TOTP secret out of the browser
		// request to the external QR service.
		$qr_code_url = $this->fetch_qr_code_as_data_uri( $qr_data );

		return array(
			'secret'       => $secret,
			'qr_code_url'  => $qr_code_url,
			'qr_data'      => $qr_data,
			'manual_entry' => sprintf(
				/* translators: %s: secret key */
				__( 'Manual entry key: %s', 'nvoos-content-graph-ai' ),
				$secret
			),
		);
	}

	/**
	 * Setup email 2FA (base-identical).
	 *
	 * @param int      $user_id User ID.
	 * @param \WP_User $user    User object.
	 * @return array Email setup data.
	 */
	private function setup_email( $user_id, $user ) {
		update_user_meta( $user_id, 'wp_mcp_ai_2fa_method', 'email' );
		update_user_meta( $user_id, 'wp_mcp_ai_2fa_enabled', false );
		update_user_meta( $user_id, 'wp_mcp_ai_2fa_email', $user->user_email );

		return array(
			'email_address' => $user->user_email,
			'status'        => __( 'Email 2FA configured', 'nvoos-content-graph-ai' ),
		);
	}

	/**
	 * Setup SMS 2FA (base-identical).
	 *
	 * @param int      $user_id      User ID.
	 * @param \WP_User $user         User object.
	 * @param string   $phone_number Phone number.
	 * @return array|\WP_Error SMS setup data.
	 */
	private function setup_sms( $user_id, $user, $phone_number ) {
		if ( empty( $phone_number ) ) {
			return new \WP_Error(
				'wp_mcp_ai_error',
				__( 'Phone number required for SMS 2FA', 'nvoos-content-graph-ai' )
			);
		}

		// Validate phone number format.
		$phone_number = preg_replace( '/[^0-9+]/', '', $phone_number );

		update_user_meta( $user_id, 'wp_mcp_ai_2fa_method', 'sms' );
		update_user_meta( $user_id, 'wp_mcp_ai_2fa_enabled', false );
		update_user_meta( $user_id, 'wp_mcp_ai_2fa_phone', $phone_number );

		return array(
			'phone_number' => $phone_number,
			'status'       => __( 'SMS 2FA configured', 'nvoos-content-graph-ai' ),
			'note'         => __( 'SMS 2FA requires a third-party SMS service integration', 'nvoos-content-graph-ai' ),
		);
	}

	/**
	 * Handle status action (base-identical).
	 *
	 * @param int $user_id User ID.
	 * @return array|\WP_Error Status result.
	 */
	private function handle_status( $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return new \WP_Error(
				'wp_mcp_ai_error',
				__( 'User not found', 'nvoos-content-graph-ai' )
			);
		}

		$enabled = (bool) get_user_meta( $user_id, 'wp_mcp_ai_2fa_enabled', true );
		$method  = get_user_meta( $user_id, 'wp_mcp_ai_2fa_method', true );

		return array(
			'success'          => true,
			'user_id'          => $user_id,
			'username'         => $user->user_login,
			'2fa_enabled'      => $enabled,
			'2fa_method'       => $method ? $method : __( 'Not configured', 'nvoos-content-graph-ai' ),
			'has_backup_codes' => $this->has_backup_codes( $user_id ),
		);
	}

	/**
	 * Handle enable action (base-identical).
	 *
	 * @param int    $user_id User ID.
	 * @param string $method  2FA method.
	 * @return array|\WP_Error Enable result.
	 */
	private function handle_enable( $user_id, $method ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Method parameter reserved for future method-specific enable flows.
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return new \WP_Error(
				'wp_mcp_ai_error',
				__( 'User not found', 'nvoos-content-graph-ai' )
			);
		}

		// Check if method is configured.
		$configured_method = get_user_meta( $user_id, 'wp_mcp_ai_2fa_method', true );
		if ( empty( $configured_method ) ) {
			return new \WP_Error(
				'wp_mcp_ai_error',
				__( '2FA not configured. Please run setup first.', 'nvoos-content-graph-ai' )
			);
		}

		update_user_meta( $user_id, 'wp_mcp_ai_2fa_enabled', true );

		return array(
			'success' => true,
			'user_id' => $user_id,
			'status'  => __( '2FA enabled successfully', 'nvoos-content-graph-ai' ),
			'method'  => $configured_method,
		);
	}

	/**
	 * Handle disable action (base-identical).
	 *
	 * @param int $user_id User ID.
	 * @return array|\WP_Error Disable result.
	 */
	private function handle_disable( $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return new \WP_Error(
				'wp_mcp_ai_error',
				__( 'User not found', 'nvoos-content-graph-ai' )
			);
		}

		update_user_meta( $user_id, 'wp_mcp_ai_2fa_enabled', false );

		return array(
			'success' => true,
			'user_id' => $user_id,
			'status'  => __( '2FA disabled', 'nvoos-content-graph-ai' ),
		);
	}

	/**
	 * Handle generate backup codes (base-identical).
	 *
	 * @param int $user_id User ID.
	 * @return array|\WP_Error Backup codes result.
	 */
	private function handle_generate_backup( $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return new \WP_Error(
				'wp_mcp_ai_error',
				__( 'User not found', 'nvoos-content-graph-ai' )
			);
		}

		$backup_codes = $this->generate_backup_codes( $user_id );

		return array(
			'success'      => true,
			'user_id'      => $user_id,
			'backup_codes' => $backup_codes,
			'message'      => __( 'Backup codes generated. Store them in a safe place.', 'nvoos-content-graph-ai' ),
		);
	}

	/**
	 * Handle bulk enforcement (base-identical).
	 *
	 * @param string $role        User role.
	 * @param bool   $force_reset Force reset.
	 * @return array|\WP_Error Bulk enforce result.
	 */
	private function handle_bulk_enforce( $role, $force_reset ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'wp_mcp_ai_error',
				__( 'Permission denied', 'nvoos-content-graph-ai' )
			);
		}

		if ( empty( $role ) ) {
			return new \WP_Error(
				'wp_mcp_ai_error',
				__( 'Role required for bulk enforcement', 'nvoos-content-graph-ai' )
			);
		}

		$users = get_users( array( 'role' => $role ) );

		$enforced = 0;
		foreach ( $users as $user ) {
			update_user_meta( $user->ID, 'wp_mcp_ai_2fa_required', true );

			if ( $force_reset ) {
				update_user_meta( $user->ID, 'wp_mcp_ai_2fa_force_setup', true );
			}

			++$enforced;
		}

		return array(
			'success'        => true,
			'role'           => $role,
			'users_affected' => $enforced,
			'force_reset'    => $force_reset,
			'message'        => sprintf(
				/* translators: 1: number of users, 2: role name */
				__( '2FA enforcement enabled for %1$d users with role: %2$s', 'nvoos-content-graph-ai' ),
				$enforced,
				$role
			),
		);
	}

	/**
	 * Fetch a QR code image from api.qrserver.com server-side and return
	 * it as a base64 data URI (base-identical privacy-preserving flow).
	 *
	 * @param string $data The data to encode in the QR code.
	 * @return string|null Base64 data URI on success, or null on failure.
	 */
	private function fetch_qr_code_as_data_uri( $data ) {
		$api_url = add_query_arg(
			array(
				'size' => '200x200',
				'data' => rawurlencode( $data ),
			),
			'https://api.qrserver.com/v1/create-qr-code/'
		);

		$response = wp_remote_get(
			$api_url,
			array(
				'timeout' => 10,
				'headers' => array( 'Accept' => 'image/png, image/*' ),
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			$error_info = is_wp_error( $response )
				? $response->get_error_message()
				: 'HTTP ' . wp_remote_retrieve_response_code( $response );
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- WP_DEBUG-gated diagnostic; 2FA QR fetch failure should be surfaced in dev/staging.
				error_log( '[NVOOS_CGAI] 2FA QR code fetch from api.qrserver.com failed: ' . $error_info );
			}
			return null;
		}

		$image_data = wp_remote_retrieve_body( $response );
		$mime_type  = wp_remote_retrieve_header( $response, 'content-type' );

		if ( empty( $image_data ) || empty( $mime_type ) ) {
			return null;
		}

		// Strip any parameters (e.g. "image/png; charset=...") from the MIME type.
		$mime_type = strtok( $mime_type, ';' );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Standard base64 encoding for RFC 2397 data URI generation; no obfuscation involved.
		return 'data:' . $mime_type . ';base64,' . base64_encode( $image_data );
	}

	/**
	 * Generate TOTP secret (base-identical Base32 alphabet).
	 *
	 * @return string Secret key.
	 */
	private function generate_totp_secret() {
		$chars  = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; // Base32 chars.
		$secret = '';

		for ( $i = 0; $i < 16; $i++ ) {
			$secret .= $chars[ random_int( 0, strlen( $chars ) - 1 ) ];
		}

		return $secret;
	}

	/**
	 * Generate backup codes (base-identical format and storage).
	 *
	 * @param int $user_id User ID.
	 * @return array Backup codes.
	 */
	private function generate_backup_codes( $user_id ) {
		$codes = array();

		for ( $i = 0; $i < 10; $i++ ) {
			$codes[] = sprintf(
				'%04d-%04d-%04d',
				random_int( 1000, 9999 ),
				random_int( 1000, 9999 ),
				random_int( 1000, 9999 )
			);
		}

		// Store hashed codes.
		$hashed_codes = array_map( 'wp_hash_password', $codes );
		update_user_meta( $user_id, 'wp_mcp_ai_2fa_backup_codes', $hashed_codes );

		return $codes;
	}

	/**
	 * Check if user has backup codes (base-identical).
	 *
	 * @param int $user_id User ID.
	 * @return bool True if has backup codes.
	 */
	private function has_backup_codes( $user_id ) {
		$codes = get_user_meta( $user_id, 'wp_mcp_ai_2fa_backup_codes', true );
		return ! empty( $codes ) && is_array( $codes );
	}

	/**
	 * Check plugin support (base-identical).
	 *
	 * @return array Plugin support status.
	 */
	private function check_plugin_support() {
		$plugins = array();

		if ( class_exists( 'wordfence' ) ) {
			$plugins[] = 'Wordfence Security';
		}

		if ( class_exists( 'Two_Factor_Core' ) ) {
			$plugins[] = 'Two Factor (WordPress.org)';
		}

		if ( class_exists( 'WP_2FA\Plugin' ) ) {
			$plugins[] = 'WP 2FA';
		}

		return array(
			'available_plugins' => $plugins,
			'note'              => empty( $plugins )
				? __( 'No 2FA plugins detected. Native implementation will be used.', 'nvoos-content-graph-ai' )
				: __( '2FA plugins detected. Consider using their native features.', 'nvoos-content-graph-ai' ),
		);
	}

	/**
	 * Get setup instructions (base-identical).
	 *
	 * @param string $method 2FA method.
	 * @return array Setup instructions.
	 */
	private function get_setup_instructions( $method ) {
		$instructions = array();

		switch ( $method ) {
			case 'totp':
				$instructions = array(
					__( '1. Install an authenticator app (Google Authenticator, Authy, or 1Password)', 'nvoos-content-graph-ai' ),
					__( '2. Scan the QR code with your authenticator app', 'nvoos-content-graph-ai' ),
					__( '3. Enter the 6-digit code from your app to verify', 'nvoos-content-graph-ai' ),
					__( '4. Save your backup codes in a secure location', 'nvoos-content-graph-ai' ),
					__( '5. Enable 2FA to complete setup', 'nvoos-content-graph-ai' ),
				);
				break;

			case 'email':
				$instructions = array(
					__( '1. Verify your email address is correct', 'nvoos-content-graph-ai' ),
					__( '2. Enable 2FA to start receiving codes via email', 'nvoos-content-graph-ai' ),
					__( '3. Check your inbox for verification code on next login', 'nvoos-content-graph-ai' ),
					__( '4. Save your backup codes in a secure location', 'nvoos-content-graph-ai' ),
				);
				break;

			case 'sms':
				$instructions = array(
					__( '1. Verify your phone number is correct', 'nvoos-content-graph-ai' ),
					__( '2. Ensure SMS service is configured (requires third-party integration)', 'nvoos-content-graph-ai' ),
					__( '3. Enable 2FA to start receiving codes via SMS', 'nvoos-content-graph-ai' ),
					__( '4. Save your backup codes in a secure location', 'nvoos-content-graph-ai' ),
				);
				break;
		}

		return $instructions;
	}

	/**
	 * Whether the tool holds privacy-relevant user data (base-identical).
	 *
	 * @return bool True.
	 */
	public function has_privacy_data() {
		return true; // Stores 2FA secrets and phone numbers.
	}

	/**
	 * Export privacy data (base-identical).
	 *
	 * @param int $user_id User ID.
	 * @return array Privacy data.
	 */
	public function export_privacy_data( $user_id ) {
		$method  = get_user_meta( $user_id, 'wp_mcp_ai_2fa_method', true );
		$enabled = get_user_meta( $user_id, 'wp_mcp_ai_2fa_enabled', true );

		return array(
			'group_label' => __( 'Two-Factor Authentication', 'nvoos-content-graph-ai' ),
			'items'       => array(
				array(
					'name'  => __( '2FA Status', 'nvoos-content-graph-ai' ),
					'value' => $enabled ? __( 'Enabled', 'nvoos-content-graph-ai' ) : __( 'Disabled', 'nvoos-content-graph-ai' ),
				),
				array(
					'name'  => __( '2FA Method', 'nvoos-content-graph-ai' ),
					'value' => $method ? $method : __( 'Not configured', 'nvoos-content-graph-ai' ),
				),
			),
		);
	}

	/**
	 * Erase privacy data (base-identical).
	 *
	 * @param int $user_id User ID.
	 * @return bool True.
	 */
	public function erase_privacy_data( $user_id ) {
		// Remove 2FA configuration.
		delete_user_meta( $user_id, 'wp_mcp_ai_2fa_enabled' );
		delete_user_meta( $user_id, 'wp_mcp_ai_2fa_method' );
		delete_user_meta( $user_id, 'wp_mcp_ai_2fa_totp_secret' );
		delete_user_meta( $user_id, 'wp_mcp_ai_2fa_phone' );
		delete_user_meta( $user_id, 'wp_mcp_ai_2fa_backup_codes' );

		return true;
	}
}
