<?php
/**
 * Admin Transparency Settings Page
 *
 * Registers transparency and compliance settings in the NV oOS Settings Dashboard.
 * Provides UI for configuring AI disclosure, consent, provenance logging,
 * and viewing generation logs.
 *
 * @package WP_MCP_AI
 * @since   1.1.45
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin Transparency Settings class.
 *
 * Handles registration of transparency settings and the admin page.
 */
class WP_MCP_AI_Admin_Transparency_Settings {

	/**
	 * Option group for transparency settings.
	 *
	 * @var string
	 */
	const OPTION_GROUP = 'wp_mcp_ai_transparency';

	/**
	 * Settings section ID.
	 *
	 * @var string
	 */
	const SECTION_ID = 'wp_mcp_ai_transparency_section';

	/**
	 * Initialize hooks.
	 *
	 * @since 1.1.45
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	/**
	 * Register transparency settings with the WordPress Settings API.
	 *
	 * Settings are stored in the main wp_mcp_ai_settings option array
	 * for consistency with the plugin's existing settings architecture.
	 *
	 * @since 1.1.45
	 * @return void
	 */
	public static function register_settings() {
		// Settings are registered via the Settings Dashboard system.
		// This method provides hooks for extensions to add transparency fields.
		// The defaults are defined in WP_MCP_AI_Admin_Settings_Base::get_default_settings().

		/**
		 * Fires when transparency admin settings are being registered.
		 *
		 * Extensions can use this hook to add custom transparency settings.
		 *
		 * @since 1.1.45
		 */
		do_action( 'wp_mcp_ai_register_transparency_settings' );
	}

	/**
	 * Render the transparency settings form.
	 *
	 * Outputs the admin UI for all transparency-related configuration.
	 *
	 * @since 1.1.45
	 * @return void
	 */
	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'mcp-ai-wpoos' ) );
		}

		$settings = class_exists( 'WP_MCP_AI_Admin_Settings_Base' )
			? WP_MCP_AI_Admin_Settings_Base::get_settings()
			: get_option( 'wp_mcp_ai_settings', array() );

		?>
		<div class="wrap wp-mcp-ai-transparency-settings">
			<h2><?php esc_html_e( 'Transparency & Compliance', 'mcp-ai-wpoos' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Configure AI transparency and compliance settings to meet EU AI Act Article 50, India IT Rules 2026 (SGI), and related regulatory requirements.', 'mcp-ai-wpoos' ); ?>
			</p>

			<div class="wp-mcp-ai-settings-card">
				<h3><?php esc_html_e( 'AI Disclosure', 'mcp-ai-wpoos' ); ?></h3>
				<p class="description">
					<?php esc_html_e( 'The AI disclosure badge informs users they are interacting with an AI assistant, as required by Article 50(1) of the EU AI Act.', 'mcp-ai-wpoos' ); ?>
				</p>

				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="enable_ai_disclosure"><?php esc_html_e( 'Show AI Disclosure', 'mcp-ai-wpoos' ); ?></label>
						</th>
						<td>
							<input type="checkbox" name="wp_mcp_ai_settings[enable_ai_disclosure]" id="enable_ai_disclosure" value="1"
								<?php checked( ! empty( $settings['enable_ai_disclosure'] ) ); ?>>
							<p class="description">
								<?php esc_html_e( 'Display an AI disclosure banner in the chat interface to inform users they are interacting with artificial intelligence.', 'mcp-ai-wpoos' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="ai_disclosure_position"><?php esc_html_e( 'Disclosure Position', 'mcp-ai-wpoos' ); ?></label>
						</th>
						<td>
							<select name="wp_mcp_ai_settings[ai_disclosure_position]" id="ai_disclosure_position">
								<option value="banner" <?php selected( isset( $settings['ai_disclosure_position'] ) ? $settings['ai_disclosure_position'] : 'banner', 'banner' ); ?>>
									<?php esc_html_e( 'Banner (top of chat)', 'mcp-ai-wpoos' ); ?>
								</option>
								<option value="header" <?php selected( isset( $settings['ai_disclosure_position'] ) ? $settings['ai_disclosure_position'] : 'banner', 'header' ); ?>>
									<?php esc_html_e( 'Header Badge (chat header)', 'mcp-ai-wpoos' ); ?>
								</option>
								<option value="both" <?php selected( isset( $settings['ai_disclosure_position'] ) ? $settings['ai_disclosure_position'] : 'banner', 'both' ); ?>>
									<?php esc_html_e( 'Both Banner and Badge', 'mcp-ai-wpoos' ); ?>
								</option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="ai_disclosure_message"><?php esc_html_e( 'Disclosure Message', 'mcp-ai-wpoos' ); ?></label>
						</th>
						<td>
							<textarea name="wp_mcp_ai_settings[ai_disclosure_message]" id="ai_disclosure_message"
								rows="3" class="large-text"
								placeholder="<?php echo esc_attr( WP_MCP_AI_Transparency_Service::DEFAULT_DISCLOSURE_MESSAGE ); ?>"><?php echo esc_textarea( isset( $settings['ai_disclosure_message'] ) ? $settings['ai_disclosure_message'] : '' ); ?></textarea>
							<p class="description">
								<?php esc_html_e( 'Custom disclosure message. Leave blank to use the default message shown above.', 'mcp-ai-wpoos' ); ?>
							</p>
						</td>
					</tr>
				</table>
			</div>

			<div class="wp-mcp-ai-settings-card">
				<h3><?php esc_html_e( 'User Consent', 'mcp-ai-wpoos' ); ?></h3>
				<p class="description">
					<?php esc_html_e( 'Require users to explicitly consent before interacting with AI assistants.', 'mcp-ai-wpoos' ); ?>
				</p>

				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="enable_consent_modal"><?php esc_html_e( 'Require Consent', 'mcp-ai-wpoos' ); ?></label>
						</th>
						<td>
							<input type="checkbox" name="wp_mcp_ai_settings[enable_consent_modal]" id="enable_consent_modal" value="1"
								<?php checked( ! empty( $settings['enable_consent_modal'] ) ); ?>>
							<p class="description">
								<?php esc_html_e( 'Show a consent modal before allowing the first AI chat interaction. Users must acknowledge they are chatting with AI.', 'mcp-ai-wpoos' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="consent_message"><?php esc_html_e( 'Consent Message', 'mcp-ai-wpoos' ); ?></label>
						</th>
						<td>
							<textarea name="wp_mcp_ai_settings[consent_message]" id="consent_message"
								rows="3" class="large-text"
								placeholder="<?php echo esc_attr( WP_MCP_AI_Transparency_Service::DEFAULT_CONSENT_MESSAGE ); ?>"><?php echo esc_textarea( isset( $settings['consent_message'] ) ? $settings['consent_message'] : '' ); ?></textarea>
							<p class="description">
								<?php esc_html_e( 'Custom consent message shown in the modal. Leave blank for default.', 'mcp-ai-wpoos' ); ?>
							</p>
						</td>
					</tr>
				</table>
			</div>

			<div class="wp-mcp-ai-settings-card">
				<h3><?php esc_html_e( 'API Transparency Headers', 'mcp-ai-wpoos' ); ?></h3>
				<p class="description">
					<?php esc_html_e( 'Add machine-readable transparency headers to REST API responses for AI-generated content provenance.', 'mcp-ai-wpoos' ); ?>
				</p>

				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="enable_transparency_headers"><?php esc_html_e( 'Enable Transparency Headers', 'mcp-ai-wpoos' ); ?></label>
						</th>
						<td>
							<input type="checkbox" name="wp_mcp_ai_settings[enable_transparency_headers]" id="enable_transparency_headers" value="1"
								<?php checked( ! empty( $settings['enable_transparency_headers'] ) ); ?>>
							<p class="description">
								<?php esc_html_e( 'Add X-AI-Generated, X-AI-Provider, and X-AI-Model headers to chat REST API responses. This enables downstream tools to detect AI-generated content.', 'mcp-ai-wpoos' ); ?>
							</p>
						</td>
					</tr>
				</table>
			</div>

			<div class="wp-mcp-ai-settings-card">
				<h3><?php esc_html_e( 'Generation Provenance', 'mcp-ai-wpoos' ); ?></h3>
				<p class="description">
					<?php esc_html_e( 'Maintain immutable, cryptographically verifiable records of all AI interactions for compliance auditing.', 'mcp-ai-wpoos' ); ?>
				</p>

				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="enable_generation_logging"><?php esc_html_e( 'Enable Provenance Logging', 'mcp-ai-wpoos' ); ?></label>
						</th>
						<td>
							<input type="checkbox" name="wp_mcp_ai_settings[enable_generation_logging]" id="enable_generation_logging" value="1"
								<?php checked( ! empty( $settings['enable_generation_logging'] ) ); ?>>
							<p class="description">
								<?php esc_html_e( 'Record every AI generation event in an immutable hash-chain database table. This provides a tamper-evident audit trail for regulatory compliance.', 'mcp-ai-wpoos' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="generation_log_retention_days"><?php esc_html_e( 'Log Retention (Days)', 'mcp-ai-wpoos' ); ?></label>
						</th>
						<td>
							<input type="number" name="wp_mcp_ai_settings[generation_log_retention_days]" id="generation_log_retention_days"
								value="<?php echo esc_attr( isset( $settings['generation_log_retention_days'] ) ? absint( $settings['generation_log_retention_days'] ) : 365 ); ?>"
								min="1" max="3650" step="1" class="small-text">
							<p class="description">
								<?php esc_html_e( 'Number of days to retain generation provenance records. Older records are automatically pruned daily.', 'mcp-ai-wpoos' ); ?>
							</p>
						</td>
					</tr>
					<?php if ( class_exists( 'WP_MCP_AI_Generation_Provenance' ) && WP_MCP_AI_Generation_Provenance::table_exists() ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Records Stored', 'mcp-ai-wpoos' ); ?></th>
							<td>
								<p><strong><?php echo esc_html( number_format_i18n( WP_MCP_AI_Generation_Provenance::get_log_count() ) ); ?></strong>
								<?php esc_html_e( 'generation records in the provenance database.', 'mcp-ai-wpoos' ); ?></p>
							</td>
						</tr>
					<?php endif; ?>
				</table>
			</div>

			<div class="wp-mcp-ai-settings-card">
				<h3><?php esc_html_e( 'Compliance Reference', 'mcp-ai-wpoos' ); ?></h3>
				<p class="description">
					<?php esc_html_e( 'These settings help your site comply with the following regulations:', 'mcp-ai-wpoos' ); ?>
				</p>
				<ul style="list-style: disc; padding-left: 20px;">
					<li><strong><?php esc_html_e( 'EU AI Act Article 50', 'mcp-ai-wpoos' ); ?></strong> — <?php esc_html_e( 'Transparency obligations for providers and deployers of AI systems (effective 2 August 2026)', 'mcp-ai-wpoos' ); ?></li>
					<li><strong><?php esc_html_e( 'India IT Rules 2026 (SGI)', 'mcp-ai-wpoos' ); ?></strong> — <?php esc_html_e( 'Synthetically Generated Information regulation — labelling, watermarking, due diligence', 'mcp-ai-wpoos' ); ?></li>
					<li><strong><?php esc_html_e( 'California SB 942', 'mcp-ai-wpoos' ); ?></strong> — <?php esc_html_e( 'AI Transparency Act — mandatory latent disclosure and public detection tools', 'mcp-ai-wpoos' ); ?></li>
					<li><strong><?php esc_html_e( 'New York S.8420', 'mcp-ai-wpoos' ); ?></strong> — <?php esc_html_e( 'Synthetic Performer Law — conspicuous disclosure for AI-generated replicas in ads', 'mcp-ai-wpoos' ); ?></li>
				</ul>
			</div>
		</div>

		<style>
			.wp-mcp-ai-settings-card {
				background: #fff;
				border: 1px solid #c3c4c7;
				border-radius: 4px;
				padding: 20px 24px;
				margin: 20px 0;
				box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
			}
			.wp-mcp-ai-settings-card h3 {
				margin-top: 0;
				padding-bottom: 8px;
				border-bottom: 1px solid #eee;
			}
		</style>
		<?php
	}
}
