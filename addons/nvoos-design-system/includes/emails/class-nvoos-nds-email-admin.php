<?php
/**
 * NV oOS Design System — Email Admin (picker UI)
 *
 * Renders the Emails tab of the settings page and handles its form actions:
 * activate, per-template settings, test send, AI generation, delete, import.
 *
 * @package NV_oOS_Design_System
 * @since   0.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Email template admin UI.
 *
 * @since 0.2.0
 */
class NV_oOS_Design_System_Email_Admin {

	/**
	 * Render the Emails tab.
	 *
	 * @return void
	 */
	public function render() {
		$active_slug = NV_oOS_Design_System_Email_Template_Registry::get_active_slug();
		$templates   = NV_oOS_Design_System_Email_Template_CPT::get_all_templates();
		$auditor     = NV_oOS_Design_System_Plugin::email_auditor();

		?>
		<div class="nvoos-nds-email-wrap">
			<h2><?php esc_html_e( 'Email Templates', 'nvoos-design-system' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Every outgoing WordPress email is wrapped in the active template, styled by the Email token group on the Tokens tab. Generated templates are saved as drafts and must be audited before activation.', 'nvoos-design-system' ); ?>
			</p>

			<?php $this->render_generate_section(); ?>

			<?php $this->render_wc_rebrand_section(); ?>

			<?php $this->render_active_settings_section( $active_slug, $templates ); ?>

			<h2><?php esc_html_e( 'Template Library', 'nvoos-design-system' ); ?></h2>

			<table class="widefat striped nvoos-nds-email-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Template', 'nvoos-design-system' ); ?></th>
						<th><?php esc_html_e( 'Source', 'nvoos-design-system' ); ?></th>
						<th><?php esc_html_e( 'Status', 'nvoos-design-system' ); ?></th>
						<th><?php esc_html_e( 'Audit', 'nvoos-design-system' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'nvoos-design-system' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $templates ) ) : ?>
						<tr>
							<td colspan="5"><?php esc_html_e( 'No templates found. Seeding runs automatically on the next page load.', 'nvoos-design-system' ); ?></td>
						</tr>
					<?php endif; ?>

					<?php foreach ( $templates as $template ) : ?>
						<?php
						$is_active   = $template->post_name === $active_slug;
						$source      = NV_oOS_Design_System_Email_Template_CPT::get_source( $template->ID );
						$is_draft    = 'draft' === $template->post_status;
						$audit       = $auditor->audit( $template->post_content, array(), NV_oOS_Design_System_Email_Template_CPT::get_scope( $template->ID ) );
						$audit_class = $audit['passes_required'] ? 'good' : 'bad';
						?>
						<tr>
							<td>
								<strong><?php echo esc_html( $template->post_title ); ?></strong>
								<br><code><?php echo esc_html( $template->post_name ); ?></code>
								<?php if ( $is_active ) : ?>
									<span class="nvoos-nds-badge nvoos-nds-badge-active"><?php esc_html_e( 'ACTIVE', 'nvoos-design-system' ); ?></span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $source ); ?></td>
							<td>
								<?php if ( $is_draft ) : ?>
									<span class="nvoos-nds-badge nvoos-nds-badge-draft"><?php esc_html_e( 'DRAFT', 'nvoos-design-system' ); ?></span>
								<?php else : ?>
									<?php esc_html_e( 'Published', 'nvoos-design-system' ); ?>
								<?php endif; ?>
							</td>
							<td>
								<span class="nvoos-nds-audit nvoos-nds-audit-<?php echo esc_attr( $audit_class ); ?>">
									<?php echo esc_html( $audit['score'] ); ?>/100
								</span>
								<?php if ( ! $audit['passes_required'] ) : ?>
									<span class="dashicons dashicons-warning" title="<?php esc_attr_e( 'Required gates failing', 'nvoos-design-system' ); ?>"></span>
								<?php endif; ?>
							</td>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nvoos-nds-inline-form">
									<?php wp_nonce_field( 'nvoos_nds_email', 'nvoos_nds_email_nonce' ); ?>
									<input type="hidden" name="action" value="nvoos_nds_email">
									<input type="hidden" name="nds_action" value="apply">
									<input type="hidden" name="template_slug" value="<?php echo esc_attr( $template->post_name ); ?>">
									<?php if ( ! $is_active ) : ?>
										<button type="submit" class="button button-primary button-small"
											<?php echo $is_draft ? 'disabled title="' . esc_attr__( 'Draft templates cannot be activated', 'nvoos-design-system' ) . '"' : ''; ?>>
											<?php esc_html_e( 'Activate', 'nvoos-design-system' ); ?>
										</button>
									<?php endif; ?>
								</form>
								<?php if ( 'builtin' !== $source ) : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nvoos-nds-inline-form" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this template?', 'nvoos-design-system' ) ); ?>');">
										<?php wp_nonce_field( 'nvoos_nds_email', 'nvoos_nds_email_nonce' ); ?>
										<input type="hidden" name="action" value="nvoos_nds_email">
										<input type="hidden" name="nds_action" value="delete">
										<input type="hidden" name="template_id" value="<?php echo esc_attr( $template->ID ); ?>">
										<button type="submit" class="button button-small button-link-delete"><?php esc_html_e( 'Delete', 'nvoos-design-system' ); ?></button>
									</form>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php $this->render_preview_section( $active_slug ); ?>
			<?php $this->render_import_section(); ?>
			<?php $this->render_paper_store_section(); ?>
		</div>
		<?php
	}

	/**
	 * Render the AI generation form.
	 *
	 * @return void
	 */
	private function render_generate_section() {
		$provider = (string) get_option( NV_oOS_Design_System_Plugin::AI_PROVIDER_KEY, 'auto' );
		?>
		<div class="nvoos-nds-generate postbox">
			<div class="postbox-header"><h2><?php esc_html_e( 'Generate a Template with AI', 'nvoos-design-system' ); ?></h2></div>
			<div class="inside">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'nvoos_nds_email', 'nvoos_nds_email_nonce' ); ?>
					<input type="hidden" name="action" value="nvoos_nds_email">
					<input type="hidden" name="nds_action" value="generate">

					<p>
						<label for="nvoos-nds-generate-prompt"><?php esc_html_e( 'Describe the template:', 'nvoos-design-system' ); ?></label><br>
						<textarea name="prompt" id="nvoos-nds-generate-prompt" rows="3" class="large-text" required
							placeholder="<?php esc_attr_e( 'e.g. A warm welcome email for new subscribers with a single call-to-action button', 'nvoos-design-system' ); ?>"></textarea>
					</p>

					<p>
						<label for="nvoos-nds-generate-base"><?php esc_html_e( 'Structural reference (optional):', 'nvoos-design-system' ); ?></label>
						<select name="base_template" id="nvoos-nds-generate-base">
							<option value=""><?php esc_html_e( '— None —', 'nvoos-design-system' ); ?></option>
							<?php foreach ( NV_oOS_Design_System_Email_Template_Registry::get_builtins() as $slug => $meta ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $meta['title'] ); ?></option>
							<?php endforeach; ?>
						</select>
					</p>

					<p>
						<label for="nvoos-nds-generate-subject"><?php esc_html_e( 'Subject hint (optional):', 'nvoos-design-system' ); ?></label><br>
						<input type="text" name="subject_hint" id="nvoos-nds-generate-subject" class="regular-text">
					</p>

					<p>
						<label for="nvoos-nds-ai-provider"><?php esc_html_e( 'Provider:', 'nvoos-design-system' ); ?></label>
						<select name="ai_provider" id="nvoos-nds-ai-provider">
							<option value="auto" <?php selected( $provider, 'auto' ); ?>><?php esc_html_e( 'Auto-detect (NV oOS keys)', 'nvoos-design-system' ); ?></option>
							<option value="openai" <?php selected( $provider, 'openai' ); ?>>OpenAI</option>
							<option value="gemini" <?php selected( $provider, 'gemini' ); ?>>Gemini</option>
						</select>
					</p>

					<?php submit_button( __( 'Generate Draft', 'nvoos-design-system' ), 'primary', 'submit', false ); ?>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the active template's per-template settings.
	 *
	 * @param string     $active_slug Active template slug.
	 * @param WP_Post[]  $templates   All template posts.
	 * @return void
	 */
	private function render_active_settings_section( $active_slug, $templates ) {
		$active = null;
		foreach ( $templates as $template ) {
			if ( $template->post_name === $active_slug ) {
				$active = $template;
				break;
			}
		}

		$settings = $active ? NV_oOS_Design_System_Email_Template_CPT::get_settings( $active->ID ) : array(
			'logo_url'     => '',
			'sub_brand'    => '',
			'confidential' => '',
			'admin_email'  => '',
		);
		?>
		<div class="nvoos-nds-active-settings postbox">
			<div class="postbox-header">
				<h2>
					<?php
					printf(
						/* translators: %s: active template title */
						esc_html__( 'Active Template Settings — %s', 'nvoos-design-system' ),
						esc_html( $active ? $active->post_title : $active_slug )
					);
					?>
				</h2>
			</div>
			<div class="inside">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'nvoos_nds_email', 'nvoos_nds_email_nonce' ); ?>
					<input type="hidden" name="action" value="nvoos_nds_email">
					<input type="hidden" name="nds_action" value="settings">
					<input type="hidden" name="template_slug" value="<?php echo esc_attr( $active_slug ); ?>">

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="nvoos-nds-logo-url"><?php esc_html_e( 'Logo URL', 'nvoos-design-system' ); ?></label></th>
							<td>
								<input type="url" name="logo_url" id="nvoos-nds-logo-url" class="regular-text" value="<?php echo esc_attr( $settings['logo_url'] ); ?>">
								<p class="description"><?php esc_html_e( 'Leave empty to use the filter → WooCommerce → theme logo chain.', 'nvoos-design-system' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="nvoos-nds-sub-brand"><?php esc_html_e( 'Sub-brand tagline', 'nvoos-design-system' ); ?></label></th>
							<td><input type="text" name="sub_brand" id="nvoos-nds-sub-brand" class="regular-text" value="<?php echo esc_attr( $settings['sub_brand'] ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="nvoos-nds-confidential"><?php esc_html_e( 'Confidentiality marker', 'nvoos-design-system' ); ?></label></th>
							<td><input type="text" name="confidential" id="nvoos-nds-confidential" class="regular-text" value="<?php echo esc_attr( $settings['confidential'] ); ?>" placeholder="<?php esc_attr_e( 'CONFIDENTIAL — leave empty to hide', 'nvoos-design-system' ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="nvoos-nds-admin-email"><?php esc_html_e( 'Admin email override', 'nvoos-design-system' ); ?></label></th>
							<td><input type="email" name="admin_email" id="nvoos-nds-admin-email" class="regular-text" value="<?php echo esc_attr( $settings['admin_email'] ); ?>"></td>
						</tr>
					</table>

					<?php submit_button( __( 'Save Settings', 'nvoos-design-system' ), 'secondary', 'submit', false ); ?>
				</form>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px; border-top:1px solid #e5e5e5; padding-top:12px;">
					<?php wp_nonce_field( 'nvoos_nds_email', 'nvoos_nds_email_nonce' ); ?>
					<input type="hidden" name="action" value="nvoos_nds_email">
					<input type="hidden" name="nds_action" value="test_send">
					<p>
						<label for="nvoos-nds-test-email"><?php esc_html_e( 'Send a test email to:', 'nvoos-design-system' ); ?></label>
						<input type="email" name="to" id="nvoos-nds-test-email" class="regular-text" value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>" required>
						<button type="submit" class="button"><?php esc_html_e( 'Send Test', 'nvoos-design-system' ); ?></button>
					</p>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the live preview of the active template.
	 *
	 * @param string $active_slug Active template slug.
	 * @return void
	 */
	private function render_preview_section( $active_slug ) {
		$template = NV_oOS_Design_System_Email_Template_Registry::get_active();

		if ( empty( $template['html'] ) ) {
			return;
		}

		$sample_body = '<h2>Sample heading</h2><p>This is a preview of how outgoing emails will look with the current design tokens. The colours, typography, and spacing come from the Email token group on the Tokens tab.</p><p>Best regards,<br>Your team</p>';

		$context = array(
			'subject'      => __( 'Sample subject line', 'nvoos-design-system' ),
			'body'         => wpautop( $sample_body ),
			'to_name'      => __( 'Valued Customer', 'nvoos-design-system' ),
			'site_name'    => get_bloginfo( 'name' ),
			'site_url'     => home_url(),
			'site_domain'  => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
			'logo_url'     => '',
			'admin_email'  => (string) get_option( 'admin_email', '' ),
			'sub_brand'    => isset( $template['settings']['sub_brand'] ) ? $template['settings']['sub_brand'] : '',
			'confidential' => isset( $template['settings']['confidential'] ) ? $template['settings']['confidential'] : '',
			'year'         => gmdate( 'Y' ),
		);

		$renderer = new NV_oOS_Design_System_Email_Renderer();
		$preview  = $renderer->render( $template['html'], $context, $active_slug );
		?>
		<div class="nvoos-nds-preview postbox">
			<div class="postbox-header"><h2><?php esc_html_e( 'Live Preview', 'nvoos-design-system' ); ?></h2></div>
			<div class="inside">
				<p class="description"><?php esc_html_e( 'Rendered with the current Email tokens and sample content.', 'nvoos-design-system' ); ?></p>
				<div class="nvoos-nds-preview-frame">
					<?php
					// The preview is rendered from admin-controlled templates and
					// fully escaped/sanitised content; it is the feature's own
					// output surface.
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo $preview;
					?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the JSON import form.
	 *
	 * @return void
	 */
	private function render_import_section() {
		?>
		<div class="nvoos-nds-import postbox">
			<div class="postbox-header"><h2><?php esc_html_e( 'Import Template (JSON)', 'nvoos-design-system' ); ?></h2></div>
			<div class="inside">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'nvoos_nds_email', 'nvoos_nds_email_nonce' ); ?>
					<input type="hidden" name="action" value="nvoos_nds_email">
					<input type="hidden" name="nds_action" value="import">
					<p>
						<textarea name="json" rows="6" class="large-text code" required
							placeholder="<?php esc_attr_e( 'Paste a template JSON export (from the nds_export_email_template tool or another site)', 'nvoos-design-system' ); ?>"></textarea>
					</p>
					<?php submit_button( __( 'Import as Draft', 'nvoos-design-system' ), 'secondary', 'submit', false ); ?>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the WooCommerce rebrand toggle.
	 *
	 * @return void
	 */
	private function render_wc_rebrand_section() {
		$enabled = (bool) get_option( NV_oOS_Design_System_Plugin::WC_REBRAND_KEY, false );
		?>
		<div class="nvoos-nds-wc-rebrand postbox">
			<div class="postbox-header"><h2><?php esc_html_e( 'WooCommerce Emails', 'nvoos-design-system' ); ?></h2></div>
			<div class="inside">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'nvoos_nds_email', 'nvoos_nds_email_nonce' ); ?>
					<input type="hidden" name="action" value="nvoos_nds_email">
					<input type="hidden" name="nds_action" value="wc_rebrand">
					<p>
						<label for="nvoos-nds-wc-rebrand">
							<input type="checkbox" name="wc_rebrand" id="nvoos-nds-wc-rebrand" value="1" <?php checked( $enabled ); ?>>
							<?php esc_html_e( 'Rebrand WooCommerce transactional emails with the Email token palette', 'nvoos-design-system' ); ?>
						</label>
					</p>
					<p class="description">
						<?php esc_html_e( 'Applies the token palette, branded header/footer, and colour options to WooCommerce order emails via WooCommerce hooks — no template overrides, no double-wrapping. Only shown when WooCommerce is active.', 'nvoos-design-system' ); ?>
					</p>
					<?php submit_button( __( 'Save WooCommerce Setting', 'nvoos-design-system' ), 'secondary', 'submit', false ); ?>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the Paper Store mirror/import section.
	 *
	 * @return void
	 */
	private function render_paper_store_section() {
		$available = NV_oOS_Design_System_Email_Paper_Store::is_available();
		?>
		<div class="nvoos-nds-paper-store postbox">
			<div class="postbox-header"><h2><?php esc_html_e( 'Paper Store (template reuse)', 'nvoos-design-system' ); ?></h2></div>
			<div class="inside">
				<?php if ( ! $available ) : ?>
					<p class="notice notice-warning inline">
						<?php esc_html_e( 'Paper Store is unavailable — activate the NV oOS base plugin to mirror templates for cross-site reuse and search.', 'nvoos-design-system' ); ?>
					</p>
				<?php endif; ?>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'nvoos_nds_email', 'nvoos_nds_email_nonce' ); ?>
					<input type="hidden" name="action" value="nvoos_nds_email">
					<input type="hidden" name="nds_action" value="paper_mirror">
					<p>
						<button type="submit" class="button" <?php disabled( ! $available ); ?>>
							<?php esc_html_e( 'Mirror Active Template to Paper Store', 'nvoos-design-system' ); ?>
						</button>
					</p>
				</form>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'nvoos_nds_email', 'nvoos_nds_email_nonce' ); ?>
					<input type="hidden" name="action" value="nvoos_nds_email">
					<input type="hidden" name="nds_action" value="paper_import">
					<p>
						<input type="text" name="collection" class="regular-text" value="email-templates" placeholder="<?php esc_attr_e( 'collection', 'nvoos-design-system' ); ?>">
						<input type="text" name="record_id" class="regular-text" placeholder="<?php esc_attr_e( 'record id (e.g. nds-letterhead)', 'nvoos-design-system' ); ?>" required>
						<button type="submit" class="button" <?php disabled( ! $available ); ?>><?php esc_html_e( 'Import from Paper Store', 'nvoos-design-system' ); ?></button>
					</p>
				</form>
			</div>
		</div>
		<?php
	}

	// -----------------------------------------------------------------------
	// Form handlers (called from admin-post.php).
	// -----------------------------------------------------------------------

	/**
	 * Activate a template.
	 *
	 * @return void
	 */
	public function handle_apply() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in the admin-post dispatcher.
		$slug = isset( $_POST['template_slug'] ) ? sanitize_title( wp_unslash( $_POST['template_slug'] ) ) : '';

		$result = NV_oOS_Design_System_Email_Template_Registry::set_active( $slug );

		if ( is_wp_error( $result ) ) {
			add_settings_error( 'nvoos_nds_email', 'apply_failed', $result->get_error_message(), 'error' );
			return;
		}

		add_settings_error( 'nvoos_nds_email', 'apply_ok', __( 'Email template activated.', 'nvoos-design-system' ), 'success' );
	}

	/**
	 * Save per-template settings.
	 *
	 * @return void
	 */
	public function handle_settings() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in the admin-post dispatcher.
		$slug = isset( $_POST['template_slug'] ) ? sanitize_title( wp_unslash( $_POST['template_slug'] ) ) : '';

		$post = NV_oOS_Design_System_Email_Template_CPT::get_by_slug( $slug );
		if ( ! $post instanceof WP_Post ) {
			add_settings_error( 'nvoos_nds_email', 'settings_failed', __( 'Template not found.', 'nvoos-design-system' ), 'error' );
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in the admin-post dispatcher.
		$settings = array(
			'logo_url'     => isset( $_POST['logo_url'] ) ? esc_url_raw( wp_unslash( $_POST['logo_url'] ) ) : '',
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in the admin-post dispatcher.
			'sub_brand'    => isset( $_POST['sub_brand'] ) ? sanitize_text_field( wp_unslash( $_POST['sub_brand'] ) ) : '',
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in the admin-post dispatcher.
			'confidential' => isset( $_POST['confidential'] ) ? sanitize_text_field( wp_unslash( $_POST['confidential'] ) ) : '',
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in the admin-post dispatcher.
			'admin_email'  => isset( $_POST['admin_email'] ) ? sanitize_email( wp_unslash( $_POST['admin_email'] ) ) : '',
		);

		update_post_meta( $post->ID, NV_oOS_Design_System_Email_Template_CPT::META_SETTINGS, $settings );

		add_settings_error( 'nvoos_nds_email', 'settings_ok', __( 'Template settings saved.', 'nvoos-design-system' ), 'success' );
	}

	/**
	 * Send a test email using the active template.
	 *
	 * @return void
	 */
	public function handle_test_send() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in the admin-post dispatcher.
		$to = isset( $_POST['to'] ) ? sanitize_email( wp_unslash( $_POST['to'] ) ) : '';

		if ( empty( $to ) ) {
			add_settings_error( 'nvoos_nds_email', 'test_send_failed', __( 'A valid recipient address is required.', 'nvoos-design-system' ), 'error' );
			return;
		}

		$sent = wp_mail(
			$to,
			/* translators: %s: site name */
			sprintf( __( '[%s] Email template test', 'nvoos-design-system' ), get_bloginfo( 'name' ) ),
			'This is a test message sent through the active NV oOS Design System email template. If you can read this, the template wrapper is working.'
		);

		if ( $sent ) {
			add_settings_error( 'nvoos_nds_email', 'test_send_ok', __( 'Test email sent.', 'nvoos-design-system' ), 'success' );
		} else {
			add_settings_error( 'nvoos_nds_email', 'test_send_failed', __( 'wp_mail() reported a failure — check your mail/SMTP configuration.', 'nvoos-design-system' ), 'error' );
		}
	}

	/**
	 * Run the AI generator from the admin form.
	 *
	 * @return void
	 */
	public function handle_generate() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in the admin-post dispatcher.
		$prompt = isset( $_POST['prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['prompt'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in the admin-post dispatcher.
		$base_template = isset( $_POST['base_template'] ) ? sanitize_title( wp_unslash( $_POST['base_template'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in the admin-post dispatcher.
		$subject_hint = isset( $_POST['subject_hint'] ) ? sanitize_text_field( wp_unslash( $_POST['subject_hint'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in the admin-post dispatcher.
		$ai_provider = isset( $_POST['ai_provider'] ) ? sanitize_key( wp_unslash( $_POST['ai_provider'] ) ) : 'auto';

		update_option( NV_oOS_Design_System_Plugin::AI_PROVIDER_KEY, in_array( $ai_provider, array( 'auto', 'openai', 'gemini' ), true ) ? $ai_provider : 'auto', false );

		$result = NV_oOS_Design_System_Plugin::email_generator()->generate( $prompt, $base_template, $subject_hint );

		if ( is_wp_error( $result ) ) {
			add_settings_error( 'nvoos_nds_email', 'generate_failed', $result->get_error_message(), 'error' );
			return;
		}

		add_settings_error(
			'nvoos_nds_email',
			'generate_ok',
			sprintf(
				/* translators: 1: template slug, 2: audit score */
				__( 'Template "%1$s" generated as a draft (audit score %2$d/100).', 'nvoos-design-system' ),
				$result['slug'],
				$result['audit']['score']
			),
			'success'
		);
	}

	/**
	 * Delete a non-builtin template.
	 *
	 * @return void
	 */
	public function handle_delete() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in the admin-post dispatcher.
		$post_id = isset( $_POST['template_id'] ) ? absint( wp_unslash( $_POST['template_id'] ) ) : 0;

		if ( ! $post_id ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || NV_oOS_Design_System_Email_Template_CPT::POST_TYPE !== $post->post_type ) {
			return;
		}

		if ( 'builtin' === NV_oOS_Design_System_Email_Template_CPT::get_source( $post_id ) ) {
			add_settings_error( 'nvoos_nds_email', 'delete_failed', __( 'Built-in templates cannot be deleted.', 'nvoos-design-system' ), 'error' );
			return;
		}

		wp_delete_post( $post_id, true );

		add_settings_error( 'nvoos_nds_email', 'delete_ok', __( 'Template deleted.', 'nvoos-design-system' ), 'success' );
	}

	/**
	 * Import a template JSON payload as a draft.
	 *
	 * @return void
	 */
	public function handle_import() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in the admin-post dispatcher.
		$json = isset( $_POST['json'] ) ? wp_unslash( $_POST['json'] ) : '';

		$payload = json_decode( $json, true );

		if ( ! is_array( $payload ) || empty( $payload['html'] ) || empty( $payload['title'] ) ) {
			add_settings_error( 'nvoos_nds_email', 'import_failed', __( 'Invalid template JSON.', 'nvoos-design-system' ), 'error' );
			return;
		}

		$slug    = ! empty( $payload['slug'] ) ? sanitize_title( $payload['slug'] ) : 'imported-' . strtolower( sanitize_title( wp_generate_uuid4() ) );
		$title   = sanitize_text_field( $payload['title'] );
		$html    = $payload['html'];
		$scope   = ! empty( $payload['scope'] ) && 'body' === $payload['scope'] ? 'body' : 'full';
		$settings = ! empty( $payload['settings'] ) && is_array( $payload['settings'] ) ? $payload['settings'] : array();

		$result = NV_oOS_Design_System_Email_Template_CPT::upsert( $slug, $title, $html, 'custom', $scope, $settings, 'draft' );

		if ( is_wp_error( $result ) ) {
			add_settings_error( 'nvoos_nds_email', 'import_failed', $result->get_error_message(), 'error' );
			return;
		}

		add_settings_error( 'nvoos_nds_email', 'import_ok', __( 'Template imported as a draft. Review it before activation.', 'nvoos-design-system' ), 'success' );
	}

	/**
	 * Save the WooCommerce rebrand toggle.
	 *
	 * @return void
	 */
	public function handle_wc_rebrand() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in the admin-post dispatcher.
		$enabled = isset( $_POST['wc_rebrand'] ) ? 1 : 0;
		update_option( NV_oOS_Design_System_Plugin::WC_REBRAND_KEY, $enabled, false );

		add_settings_error(
			'nvoos_nds_email',
			'wc_rebrand_ok',
			$enabled
				? __( 'WooCommerce email rebranding enabled.', 'nvoos-design-system' )
				: __( 'WooCommerce email rebranding disabled.', 'nvoos-design-system' ),
			'success'
		);
	}

	/**
	 * Mirror the active template into the Paper Store.
	 *
	 * @return void
	 */
	public function handle_paper_mirror() {
		$post = NV_oOS_Design_System_Email_Template_CPT::get_by_slug( NV_oOS_Design_System_Email_Template_Registry::get_active_slug() );

		if ( ! $post instanceof WP_Post ) {
			add_settings_error( 'nvoos_nds_email', 'paper_mirror_failed', __( 'Active template not found.', 'nvoos-design-system' ), 'error' );
			return;
		}

		$result = NV_oOS_Design_System_Email_Paper_Store::mirror( $post->ID );

		if ( is_wp_error( $result ) ) {
			add_settings_error( 'nvoos_nds_email', 'paper_mirror_failed', $result->get_error_message(), 'error' );
			return;
		}

		add_settings_error( 'nvoos_nds_email', 'paper_mirror_ok', $result['message'], 'success' );
	}

	/**
	 * Import a template from the Paper Store.
	 *
	 * @return void
	 */
	public function handle_paper_import() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in the admin-post dispatcher.
		$collection = isset( $_POST['collection'] ) ? sanitize_key( wp_unslash( $_POST['collection'] ) ) : 'email-templates';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in the admin-post dispatcher.
		$record_id = isset( $_POST['record_id'] ) ? sanitize_key( wp_unslash( $_POST['record_id'] ) ) : '';

		$result = NV_oOS_Design_System_Email_Paper_Store::import_record( $collection, $record_id );

		if ( is_wp_error( $result ) ) {
			add_settings_error( 'nvoos_nds_email', 'paper_import_failed', $result->get_error_message(), 'error' );
			return;
		}

		add_settings_error( 'nvoos_nds_email', 'paper_import_ok', $result['message'], 'success' );
	}
}
