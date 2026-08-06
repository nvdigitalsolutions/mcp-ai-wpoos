<?php
/**
 * Abstract Export Provider Base.
 *
 * Provides shared utilities used by all export providers:
 *   - Cache-busted option reads
 *   - Pre-import backup helper
 *   - Sensitive value decrypt/encrypt delegation
 *   - Audit logging
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract base for export providers.
 *
 * @since 1.2.0
 */
abstract class WP_MCP_AI_Export_Provider_Base implements WP_MCP_AI_Export_Provider {

	/**
	 * Read a WordPress option with cache busting.
	 *
	 * @since 1.2.0
	 *
	 * @param string $option_name Option name.
	 * @param mixed  $default     Default value if option not set.
	 * @return mixed
	 */
	protected function get_option_safe( string $option_name, $default = array() ) {
		wp_cache_delete( $option_name, 'options' );
		return get_option( $option_name, $default );
	}

	/**
	 * Check whether a key name refers to a sensitive setting.
	 *
	 * Delegates to the existing settings base class logic.
	 *
	 * @since 1.2.0
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	protected function is_sensitive_key( string $key ): bool {
		if ( class_exists( 'WP_MCP_AI_Admin_Settings_Base' ) ) {
			return WP_MCP_AI_Admin_Settings_Base::is_sensitive_setting_key( $key );
		}
		// Fallback: common credential key patterns.
		$sensitive_patterns = array( 'api_key', 'api_secret', 'token', 'password', 'secret', 'credential' );
		foreach ( $sensitive_patterns as $pattern ) {
			if ( false !== stripos( $key, $pattern ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Maybe decrypt a sensitive setting value.
	 *
	 * @since 1.2.0
	 *
	 * @param mixed $value The stored value.
	 * @return mixed Decrypted value or original if not encrypted.
	 */
	protected function maybe_decrypt_value( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return $value;
		}
		if ( class_exists( 'WP_MCP_AI_Admin_Settings_Base' ) ) {
			return WP_MCP_AI_Admin_Settings_Base::maybe_decrypt_sensitive_setting_value( $value );
		}
		return $value;
	}

	/**
	 * Log an import action for audit purposes.
	 *
	 * @since 1.2.0
	 *
	 * @param string $action  'imported' or 'validated'.
	 * @param mixed  $result  Result data or error.
	 * @return void
	 */
	protected function log_action( string $action, $result ): void {
		if ( ! class_exists( 'WP_MCP_AI_Admin_Settings_Base' ) ) {
			return;
		}
		$log_entry = array(
			'provider' => $this->get_id(),
			'action'   => $action,
			'time'     => current_time( 'mysql' ),
			'user'     => wp_get_current_user()->user_login,
			'result'   => is_wp_error( $result ) ? $result->get_error_message() : 'success',
		);
		$log       = get_option( 'wp_mcp_ai_export_import_log', array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		$log[] = $log_entry;
		// Keep only last 50 entries.
		if ( count( $log ) > 50 ) {
			$log = array_slice( $log, -50 );
		}
		update_option( 'wp_mcp_ai_export_import_log', $log, false );
	}

	/**
	 * Render a provider checkbox row for the UI.
	 *
	 * @since 1.2.0
	 *
	 * @param bool $checked Whether the checkbox should be checked by default.
	 * @return void
	 */
	public function render_checkbox( bool $checked = false ): void {
		$count = $this->get_count();
		?>
		<label style="display: block; margin: 8px 0; padding: 8px; background: #f8f9fa; border-radius: 4px;">
			<input type="checkbox"
				class="wp-mcp-ai-export-provider-checkbox"
				value="<?php echo esc_attr( $this->get_id() ); ?>"
				<?php checked( $checked ); ?>
				<?php disabled( ! $this->is_available() ); ?> />
			<strong><?php echo esc_html( $this->get_label() ); ?></strong>
			<span class="count-badge" style="background: #e0e0e0; padding: 1px 8px; border-radius: 10px; font-size: 12px; margin-left: 6px;">
				<?php echo esc_html( (string) $count ); ?>
			</span>
			<?php if ( $this->contains_sensitive_data() ) : ?>
				<span class="dashicons dashicons-warning" style="color: #d63638; vertical-align: middle;"
					title="<?php esc_attr_e( 'Contains sensitive data (API keys, tokens). Secure this file.', 'mcp-ai-wpoos' ); ?>"></span>
			<?php endif; ?>
			<br>
			<small style="color: #666;"><?php echo esc_html( $this->get_description() ); ?></small>
		</label>
		<?php
	}
}
