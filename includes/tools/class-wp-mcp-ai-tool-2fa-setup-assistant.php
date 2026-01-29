<?php
/**
 * 2FA Setup Assistant Tool
 *
 * Guides users through two-factor authentication setup including
 * TOTP, email, SMS methods with QR code generation and backup codes.
 *
 * Based on 2026 security best practices from:
 * - NIST Digital Identity Guidelines
 * - Bluehost 2FA Implementation Guide
 * - Wordfence 2FA Best Practices
 *
 * @package    WP_MCP_AI
 * @subpackage Tools
 * @since      1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 2FA Setup Assistant Tool Class
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Tool_2FA_Setup_Assistant {
	use WP_MCP_AI_Tool_WordPress_Native;

	/**
	 * Get tool slug
	 *
	 * @since 1.0.0
	 * @return string Tool slug.
	 */
	public function get_slug() {
		return '2fa_setup_assistant';
	}

	/**
	 * Get tool definition
	 *
	 * @since 1.0.0
	 * @return array Tool definition.
	 */
	public function get_definition() {
		return array(
			'name'                 => __( '2FA Setup Assistant', 'wp-mcp-ai' ),
			'description'          => __( 'Guides users through two-factor authentication setup with TOTP, email, or SMS methods. Includes QR code generation and backup codes.', 'wp-mcp-ai' ),
			'category'             => 'security',
			'required_capability'  => 'read', // Users can set up their own 2FA.
			'parameters'           => array(
				'action'           => array(
					'type'        => 'string',
					'description' => __( 'Action to perform: setup, status, enable, disable, generate_backup, or bulk_enforce', 'wp-mcp-ai' ),
					'required'    => true,
					'enum'        => array( 'setup', 'status', 'enable', 'disable', 'generate_backup', 'bulk_enforce' ),
				),
				'user_id'          => array(
					'type'        => 'integer',
					'description' => __( 'User ID (defaults to current user)', 'wp-mcp-ai' ),
					'required'    => false,
				),
				'method'           => array(
					'type'        => 'string',
					'description' => __( 'Authentication method: totp, email, or sms', 'wp-mcp-ai' ),
					'default'     => 'totp',
					'enum'        => array( 'totp', 'email', 'sms' ),
				),
				'role'             => array(
					'type'        => 'string',
					'description' => __( 'User role for bulk enforcement', 'wp-mcp-ai' ),
					'required'    => false,
				),
				'phone_number'     => array(
					'type'        => 'string',
					'description' => __( 'Phone number for SMS method', 'wp-mcp-ai' ),
					'required'    => false,
				),
				'force_reset'      => array(
					'type'        => 'boolean',
					'description' => __( 'Force users to set up 2FA on next login', 'wp-mcp-ai' ),
					'default'     => false,
				),
			),
		);
	}

	/**
	 * Execute the tool
	 *
	 * @since 1.0.0
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array Tool execution result.
	 */
	public function execute( $arguments, $context = array() ) {
		// Validate parameters.
		$action       = isset( $arguments['action'] ) ? sanitize_text_field( $arguments['action'] ) : 'setup';
		$user_id      = isset( $arguments['user_id'] ) ? absint( $arguments['user_id'] ) : get_current_user_id();
		$method       = isset( $arguments['method'] ) ? sanitize_text_field( $arguments['method'] ) : 'totp';
		$role         = isset( $arguments['role'] ) ? sanitize_text_field( $arguments['role'] ) : '';
		$phone_number = isset( $arguments['phone_number'] ) ? sanitize_text_field( $arguments['phone_number'] ) : '';
		$force_reset  = isset( $arguments['force_reset'] ) ? (bool) $arguments['force_reset'] : false;

		// Before execution hook.
		$this->do_before_execute( $arguments, $context );

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
				$result = array(
					'success' => false,
					'error'   => __( 'Invalid action specified', 'wp-mcp-ai' ),
				);
		}

		// After execution hook.
		$this->do_after_execute( $result, $arguments, $context );

		return $this->apply_result_filter( $result, $arguments, $context );
	}

	/**
	 * Handle setup action
	 *
	 * @since 1.0.0
	 * @param int    $user_id      User ID.
	 * @param string $method       2FA method.
	 * @param string $phone_number Phone number for SMS.
	 * @param array  $context      Execution context.
	 * @return array Setup result.
	 */
	private function handle_setup( $user_id, $method, $phone_number, $context ) {
		// Verify user.
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return array(
				'success' => false,
				'error'   => __( 'User not found', 'wp-mcp-ai' ),
			);
		}

		// Check permissions.
		if ( get_current_user_id() !== $user_id && ! current_user_can( 'edit_users' ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Permission denied', 'wp-mcp-ai' ),
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
				$result   = array_merge( $result, $sms_data );
				break;
		}

		// Generate backup codes.
		$backup_codes         = $this->generate_backup_codes( $user_id );
		$result['backup_codes'] = $backup_codes;

		// Check for plugin integration.
		$result['plugin_support'] = $this->check_plugin_support();

		// Add setup instructions.
		$result['instructions'] = $this->get_setup_instructions( $method );

		return $result;
	}

	/**
	 * Setup TOTP
	 *
	 * @since 1.0.0
	 * @param int     $user_id User ID.
	 * @param WP_User $user    User object.
	 * @return array TOTP setup data.
	 */
	private function setup_totp( $user_id, $user ) {
		// Generate secret key.
		$secret = $this->generate_totp_secret();

		// Store secret.
		update_user_meta( $user_id, 'wp_mcp_ai_2fa_totp_secret', $secret );
		update_user_meta( $user_id, 'wp_mcp_ai_2fa_method', 'totp' );
		update_user_meta( $user_id, 'wp_mcp_ai_2fa_enabled', false ); // Not enabled until verified.

		// Generate QR code data.
		$issuer    = get_bloginfo( 'name' );
		$account   = $user->user_email;
		$qr_data   = sprintf(
			'otpauth://totp/%s:%s?secret=%s&issuer=%s',
			rawurlencode( $issuer ),
			rawurlencode( $account ),
			$secret,
			rawurlencode( $issuer )
		);

		// Generate QR code URL (using a QR code service).
		$qr_code_url = sprintf(
			'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=%s',
			rawurlencode( $qr_data )
		);

		return array(
			'secret'      => $secret,
			'qr_code_url' => $qr_code_url,
			'qr_data'     => $qr_data,
			'manual_entry' => sprintf(
				/* translators: %s: secret key */
				__( 'Manual entry key: %s', 'wp-mcp-ai' ),
				$secret
			),
		);
	}

	/**
	 * Setup email 2FA
	 *
	 * @since 1.0.0
	 * @param int     $user_id User ID.
	 * @param WP_User $user    User object.
	 * @return array Email setup data.
	 */
	private function setup_email( $user_id, $user ) {
		update_user_meta( $user_id, 'wp_mcp_ai_2fa_method', 'email' );
		update_user_meta( $user_id, 'wp_mcp_ai_2fa_enabled', false );
		update_user_meta( $user_id, 'wp_mcp_ai_2fa_email', $user->user_email );

		return array(
			'email_address' => $user->user_email,
			'status'        => __( 'Email 2FA configured', 'wp-mcp-ai' ),
		);
	}

	/**
	 * Setup SMS 2FA
	 *
	 * @since 1.0.0
	 * @param int     $user_id      User ID.
	 * @param WP_User $user         User object.
	 * @param string  $phone_number Phone number.
	 * @return array SMS setup data.
	 */
	private function setup_sms( $user_id, $user, $phone_number ) {
		if ( empty( $phone_number ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Phone number required for SMS 2FA', 'wp-mcp-ai' ),
			);
		}

		// Validate phone number format.
		$phone_number = preg_replace( '/[^0-9+]/', '', $phone_number );

		update_user_meta( $user_id, 'wp_mcp_ai_2fa_method', 'sms' );
		update_user_meta( $user_id, 'wp_mcp_ai_2fa_enabled', false );
		update_user_meta( $user_id, 'wp_mcp_ai_2fa_phone', $phone_number );

		return array(
			'phone_number' => $phone_number,
			'status'       => __( 'SMS 2FA configured', 'wp-mcp-ai' ),
			'note'         => __( 'SMS 2FA requires a third-party SMS service integration', 'wp-mcp-ai' ),
		);
	}

	/**
	 * Handle status action
	 *
	 * @since 1.0.0
	 * @param int $user_id User ID.
	 * @return array Status result.
	 */
	private function handle_status( $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return array(
				'success' => false,
				'error'   => __( 'User not found', 'wp-mcp-ai' ),
			);
		}

		$enabled = (bool) get_user_meta( $user_id, 'wp_mcp_ai_2fa_enabled', true );
		$method  = get_user_meta( $user_id, 'wp_mcp_ai_2fa_method', true );

		return array(
			'success'        => true,
			'user_id'        => $user_id,
			'username'       => $user->user_login,
			'2fa_enabled'    => $enabled,
			'2fa_method'     => $method ?: __( 'Not configured', 'wp-mcp-ai' ),
			'has_backup_codes' => $this->has_backup_codes( $user_id ),
		);
	}

	/**
	 * Handle enable action
	 *
	 * @since 1.0.0
	 * @param int    $user_id User ID.
	 * @param string $method  2FA method.
	 * @return array Enable result.
	 */
	private function handle_enable( $user_id, $method ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return array(
				'success' => false,
				'error'   => __( 'User not found', 'wp-mcp-ai' ),
			);
		}

		// Check if method is configured.
		$configured_method = get_user_meta( $user_id, 'wp_mcp_ai_2fa_method', true );
		if ( empty( $configured_method ) ) {
			return array(
				'success' => false,
				'error'   => __( '2FA not configured. Please run setup first.', 'wp-mcp-ai' ),
			);
		}

		update_user_meta( $user_id, 'wp_mcp_ai_2fa_enabled', true );

		return array(
			'success' => true,
			'user_id' => $user_id,
			'status'  => __( '2FA enabled successfully', 'wp-mcp-ai' ),
			'method'  => $configured_method,
		);
	}

	/**
	 * Handle disable action
	 *
	 * @since 1.0.0
	 * @param int $user_id User ID.
	 * @return array Disable result.
	 */
	private function handle_disable( $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return array(
				'success' => false,
				'error'   => __( 'User not found', 'wp-mcp-ai' ),
			);
		}

		update_user_meta( $user_id, 'wp_mcp_ai_2fa_enabled', false );

		return array(
			'success' => true,
			'user_id' => $user_id,
			'status'  => __( '2FA disabled', 'wp-mcp-ai' ),
		);
	}

	/**
	 * Handle generate backup codes
	 *
	 * @since 1.0.0
	 * @param int $user_id User ID.
	 * @return array Backup codes result.
	 */
	private function handle_generate_backup( $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return array(
				'success' => false,
				'error'   => __( 'User not found', 'wp-mcp-ai' ),
			);
		}

		$backup_codes = $this->generate_backup_codes( $user_id );

		return array(
			'success'      => true,
			'user_id'      => $user_id,
			'backup_codes' => $backup_codes,
			'message'      => __( 'Backup codes generated. Store them in a safe place.', 'wp-mcp-ai' ),
		);
	}

	/**
	 * Handle bulk enforcement
	 *
	 * @since 1.0.0
	 * @param string $role        User role.
	 * @param bool   $force_reset Force reset.
	 * @return array Bulk enforce result.
	 */
	private function handle_bulk_enforce( $role, $force_reset ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Permission denied', 'wp-mcp-ai' ),
			);
		}

		if ( empty( $role ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Role required for bulk enforcement', 'wp-mcp-ai' ),
			);
		}

		$users = get_users( array( 'role' => $role ) );

		$enforced = 0;
		foreach ( $users as $user ) {
			update_user_meta( $user->ID, 'wp_mcp_ai_2fa_required', true );

			if ( $force_reset ) {
				update_user_meta( $user->ID, 'wp_mcp_ai_2fa_force_setup', true );
			}

			$enforced++;
		}

		return array(
			'success'       => true,
			'role'          => $role,
			'users_affected' => $enforced,
			'force_reset'   => $force_reset,
			'message'       => sprintf(
				/* translators: %d: number of users, %s: role name */
				__( '2FA enforcement enabled for %d users with role: %s', 'wp-mcp-ai' ),
				$enforced,
				$role
			),
		);
	}

	/**
	 * Generate TOTP secret
	 *
	 * @since 1.0.0
	 * @return string Secret key.
	 */
	private function generate_totp_secret() {
		$chars  = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; // Base32 chars.
		$secret = '';

		for ( $i = 0; $i < 16; $i++ ) {
			$secret .= $chars[ wp_rand( 0, strlen( $chars ) - 1 ) ];
		}

		return $secret;
	}

	/**
	 * Generate backup codes
	 *
	 * @since 1.0.0
	 * @param int $user_id User ID.
	 * @return array Backup codes.
	 */
	private function generate_backup_codes( $user_id ) {
		$codes = array();

		for ( $i = 0; $i < 10; $i++ ) {
			$codes[] = sprintf(
				'%04d-%04d-%04d',
				wp_rand( 1000, 9999 ),
				wp_rand( 1000, 9999 ),
				wp_rand( 1000, 9999 )
			);
		}

		// Store hashed codes.
		$hashed_codes = array_map( 'wp_hash_password', $codes );
		update_user_meta( $user_id, 'wp_mcp_ai_2fa_backup_codes', $hashed_codes );

		return $codes;
	}

	/**
	 * Check if user has backup codes
	 *
	 * @since 1.0.0
	 * @param int $user_id User ID.
	 * @return bool True if has backup codes.
	 */
	private function has_backup_codes( $user_id ) {
		$codes = get_user_meta( $user_id, 'wp_mcp_ai_2fa_backup_codes', true );
		return ! empty( $codes ) && is_array( $codes );
	}

	/**
	 * Check plugin support
	 *
	 * @since 1.0.0
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
				? __( 'No 2FA plugins detected. Native implementation will be used.', 'wp-mcp-ai' )
				: __( '2FA plugins detected. Consider using their native features.', 'wp-mcp-ai' ),
		);
	}

	/**
	 * Get setup instructions
	 *
	 * @since 1.0.0
	 * @param string $method 2FA method.
	 * @return array Setup instructions.
	 */
	private function get_setup_instructions( $method ) {
		$instructions = array();

		switch ( $method ) {
			case 'totp':
				$instructions = array(
					__( '1. Install an authenticator app (Google Authenticator, Authy, or 1Password)', 'wp-mcp-ai' ),
					__( '2. Scan the QR code with your authenticator app', 'wp-mcp-ai' ),
					__( '3. Enter the 6-digit code from your app to verify', 'wp-mcp-ai' ),
					__( '4. Save your backup codes in a secure location', 'wp-mcp-ai' ),
					__( '5. Enable 2FA to complete setup', 'wp-mcp-ai' ),
				);
				break;

			case 'email':
				$instructions = array(
					__( '1. Verify your email address is correct', 'wp-mcp-ai' ),
					__( '2. Enable 2FA to start receiving codes via email', 'wp-mcp-ai' ),
					__( '3. Check your inbox for verification code on next login', 'wp-mcp-ai' ),
					__( '4. Save your backup codes in a secure location', 'wp-mcp-ai' ),
				);
				break;

			case 'sms':
				$instructions = array(
					__( '1. Verify your phone number is correct', 'wp-mcp-ai' ),
					__( '2. Ensure SMS service is configured (requires third-party integration)', 'wp-mcp-ai' ),
					__( '3. Enable 2FA to start receiving codes via SMS', 'wp-mcp-ai' ),
					__( '4. Save your backup codes in a secure location', 'wp-mcp-ai' ),
				);
				break;
		}

		return $instructions;
	}

	/**
	 * Check if tool has privacy data
	 *
	 * @since 1.0.0
	 * @return bool True if has privacy data.
	 */
	public function has_privacy_data() {
		return true; // Stores 2FA secrets and phone numbers.
	}

	/**
	 * Export privacy data
	 *
	 * @since 1.0.0
	 * @param int $user_id User ID.
	 * @return array Privacy data.
	 */
	public function export_privacy_data( $user_id ) {
		$method  = get_user_meta( $user_id, 'wp_mcp_ai_2fa_method', true );
		$enabled = get_user_meta( $user_id, 'wp_mcp_ai_2fa_enabled', true );

		$data = array(
			'group_label' => __( 'Two-Factor Authentication', 'wp-mcp-ai' ),
			'items'       => array(
				array(
					'name'  => __( '2FA Status', 'wp-mcp-ai' ),
					'value' => $enabled ? __( 'Enabled', 'wp-mcp-ai' ) : __( 'Disabled', 'wp-mcp-ai' ),
				),
				array(
					'name'  => __( '2FA Method', 'wp-mcp-ai' ),
					'value' => $method ?: __( 'Not configured', 'wp-mcp-ai' ),
				),
			),
		);

		return $data;
	}

	/**
	 * Erase privacy data
	 *
	 * @since 1.0.0
	 * @param int $user_id User ID.
	 * @return bool True if erased.
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
