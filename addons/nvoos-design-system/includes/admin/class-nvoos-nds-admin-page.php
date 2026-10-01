<?php
/**
 * NV oOS Design System — Admin Settings Page
 *
 * @package NV_oOS_Design_System
 * @since   0.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and renders the admin settings page for the NV oOS Design System.
 *
 * The page lives under Settings → Design System and provides two tabs:
 *   - Tokens: visual token editor (colour pickers, range sliders), preset
 *     selector, @property toggle, legacy-alias toggle, DTCG export, preview.
 *   - Emails: template picker, per-template settings, AI generation,
 *     test send, import/export surfaces.
 *
 * @since 0.2.0
 */
class NV_oOS_Design_System_Admin_Page {

	/**
	 * Token registry instance.
	 *
	 * @var NV_oOS_Design_System_Token_Registry
	 */
	private $registry;

	/**
	 * Registered presets for the preset selector.
	 *
	 * @var array<string, string>
	 */
	private $presets;

	/**
	 * Human-readable group labels.
	 *
	 * @var array<string, string>
	 */
	private $group_labels;

	/**
	 * Constructor.
	 *
	 * @param NV_oOS_Design_System_Token_Registry $registry Token registry.
	 */
	public function __construct( $registry ) {
		$this->registry = $registry;

		$this->presets = array(
			'NV_oOS_Design_System_Preset_Minimal'   => __( 'Minimal (Default)', 'nvoos-design-system' ),
			'NV_oOS_Design_System_Preset_Ecommerce' => __( 'Ecommerce', 'nvoos-design-system' ),
			'NV_oOS_Design_System_Preset_Directory' => __( 'Directory', 'nvoos-design-system' ),
		);

		$this->group_labels = array(
			'colors'      => __( 'Colors', 'nvoos-design-system' ),
			'typography'  => __( 'Typography', 'nvoos-design-system' ),
			'spacing'     => __( 'Spacing', 'nvoos-design-system' ),
			'borders'     => __( 'Borders', 'nvoos-design-system' ),
			'shadows'     => __( 'Shadows', 'nvoos-design-system' ),
			'sizing'      => __( 'Sizing', 'nvoos-design-system' ),
			'transitions' => __( 'Transitions', 'nvoos-design-system' ),
			'emails'      => __( 'Email', 'nvoos-design-system' ),
		);
	}

	/**
	 * Register the admin menu page.
	 *
	 * @return void
	 */
	public function register() {
		add_options_page(
			__( 'NV oOS Design System', 'nvoos-design-system' ),
			__( 'Design System', 'nvoos-design-system' ),
			'manage_options',
			'nvoos-nds-settings',
			array( $this, 'render' )
		);
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render() {
		// Process form submissions before any output.
		$this->maybe_handle_post();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tab selection is a read-only UI state.
		$tab = isset( $_GET['tab'] ) && 'emails' === sanitize_key( wp_unslash( $_GET['tab'] ) ) ? 'emails' : 'tokens';

		?>
		<div class="wrap nvoos-nds-wrap">
			<h1><?php echo esc_html__( 'NV oOS Design System', 'nvoos-design-system' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Design tokens for JetEngine listings, JetSmartFilters, JetFormBuilder, and Elementor — plus token-driven email templates for every outgoing WordPress email.', 'nvoos-design-system' ); ?>
			</p>

			<nav class="nav-tab-wrapper">
				<a href="<?php echo esc_url( admin_url( 'options-general.php?page=nvoos-nds-settings&tab=tokens' ) ); ?>"
					class="nav-tab <?php echo 'tokens' === $tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Tokens', 'nvoos-design-system' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'options-general.php?page=nvoos-nds-settings&tab=emails' ) ); ?>"
					class="nav-tab <?php echo 'emails' === $tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Emails', 'nvoos-design-system' ); ?>
				</a>
			</nav>

			<hr class="wp-header-end">

			<?php $this->render_notices(); ?>

			<?php if ( 'emails' === $tab ) : ?>
				<?php
				$email_admin = new NV_oOS_Design_System_Email_Admin();
				$email_admin->render();
				?>
			<?php else : ?>
				<?php $this->render_tokens_tab(); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the Tokens tab.
	 *
	 * @return void
	 */
	private function render_tokens_tab() {
		$grouped  = $this->registry->get_grouped();
		$css_vars = $this->get_css_preview();
		?>
		<div class="nvoos-nds-layout">
			<div class="nvoos-nds-main">
				<form method="post" action="" id="nvoos-nds-form">
					<?php wp_nonce_field( 'nvoos_nds_save', 'nvoos_nds_nonce' ); ?>

					<?php $this->render_preset_selector(); ?>

					<?php $this->render_settings_bar(); ?>

					<?php
					foreach ( $this->group_labels as $group_key => $group_label ) {
						if ( isset( $grouped[ $group_key ] ) ) {
							$this->render_group_section( $group_key, $group_label, $grouped[ $group_key ] );
						}
					}
					?>

					<?php submit_button( __( 'Save Changes', 'nvoos-design-system' ) ); ?>
				</form>
			</div>

			<div class="nvoos-nds-sidebar">
				<?php $this->render_preview_pane( $css_vars ); ?>
				<?php $this->render_export_section(); ?>
			</div>
		</div>
		<?php
	}

	// -----------------------------------------------------------------------
	// Section renderers.
	// -----------------------------------------------------------------------

	/**
	 * Render the preset selector dropdown.
	 *
	 * @return void
	 */
	private function render_preset_selector() {
		?>
		<div class="nvoos-nds-preset-bar">
			<label for="nvoos-nds-preset">
				<?php esc_html_e( 'Apply Preset:', 'nvoos-design-system' ); ?>
			</label>
			<select name="nvoos_nds_preset" id="nvoos-nds-preset">
				<option value=""><?php esc_html_e( '— Select a preset —', 'nvoos-design-system' ); ?></option>
				<?php foreach ( $this->presets as $class => $label ) : ?>
					<option value="<?php echo esc_attr( $class ); ?>">
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<button type="submit" name="nvoos_nds_action" value="apply_preset" class="button">
				<?php esc_html_e( 'Apply', 'nvoos-design-system' ); ?>
			</button>
		</div>
		<?php
	}

	/**
	 * Render the settings bar (@property toggle, legacy aliases, DTCG export).
	 *
	 * @return void
	 */
	private function render_settings_bar() {
		$typed_enabled  = NV_oOS_Design_System_Plugin::is_typed_properties_enabled();
		$legacy_enabled = NV_oOS_Design_System_Plugin::is_legacy_aliases_enabled();
		$dtcg_url       = wp_nonce_url(
			admin_url( 'admin-post.php?action=nvoos_nds_export_dtcg' ),
			'nvoos_nds_dtcg_export',
			'nvoos_nds_dtcg_nonce'
		);
		?>
		<div class="nvoos-nds-settings-bar">
			<div class="nvoos-nds-settings-row">
				<label for="nvoos-nds-typed-props">
					<input
						type="checkbox"
						name="nvoos_nds_use_typed_properties"
						id="nvoos-nds-typed-props"
						value="1"
						<?php checked( $typed_enabled ); ?>
					>
					<?php esc_html_e( 'Generate typed CSS custom properties (@property)', 'nvoos-design-system' ); ?>
				</label>
				<p class="description">
					<?php esc_html_e( 'Enables browser type-checking, DevTools colour pickers, and animatable tokens. Requires modern browser support (Chrome 85+, Firefox 128+, Safari 16.4+).', 'nvoos-design-system' ); ?>
				</p>
			</div>

			<div class="nvoos-nds-settings-row">
				<label for="nvoos-nds-legacy-aliases">
					<input
						type="checkbox"
						name="nvoos_nds_legacy_aliases"
						id="nvoos-nds-legacy-aliases"
						value="1"
						<?php checked( $legacy_enabled ); ?>
					>
					<?php esc_html_e( 'Legacy Crocoblock DS aliases (--cds-* variables and .cds-* classes)', 'nvoos-design-system' ); ?>
				</label>
				<p class="description">
					<?php esc_html_e( 'Keeps existing builds that reference the old Crocoblock DS naming working after the rename. Scheduled for removal in v1.0.0.', 'nvoos-design-system' ); ?>
				</p>
			</div>

			<div class="nvoos-nds-settings-row">
				<a href="<?php echo esc_url( $dtcg_url ); ?>" class="button">
					<?php esc_html_e( 'Export as DTCG (Design Tokens JSON)', 'nvoos-design-system' ); ?>
				</a>
				<p class="description">
					<?php esc_html_e( 'Download tokens in the W3C Design Tokens Community Group format. Compatible with Tokens Studio for Figma, Style Dictionary, and Terrazzo.', 'nvoos-design-system' ); ?>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Render a token group section with input fields.
	 *
	 * @param string                                         $group_key   Group identifier.
	 * @param string                                         $group_label Human-readable label.
	 * @param array<string, NV_oOS_Design_System_Data_Token> $tokens      Tokens in this group.
	 * @return void
	 */
	private function render_group_section( $group_key, $group_label, $tokens ) {
		?>
		<div class="nvoos-nds-group" data-group="<?php echo esc_attr( $group_key ); ?>">
			<h2 class="nvoos-nds-group-title">
				<?php echo esc_html( $group_label ); ?>
			</h2>
			<table class="form-table nvoos-nds-tokens-table">
				<tbody>
					<?php foreach ( $tokens as $token ) : ?>
						<tr class="nvoos-nds-token-row" data-token-id="<?php echo esc_attr( $token->id ); ?>">
							<th scope="row">
								<label for="nvoos-nds-<?php echo esc_attr( $token->id ); ?>">
									<?php echo esc_html( $token->label ); ?>
								</label>
								<?php if ( $token->description ) : ?>
									<p class="description">
										<?php echo esc_html( $token->description ); ?>
									</p>
								<?php endif; ?>
							</th>
							<td>
								<?php $this->render_token_input( $token ); ?>
								<code class="nvoos-nds-css-var">
									<?php echo esc_html( $token->css_var() ); ?>
								</code>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Render the appropriate input for a token's type.
	 *
	 * @param NV_oOS_Design_System_Data_Token $token Token definition.
	 * @return void
	 */
	private function render_token_input( $token ) {
		$name  = 'nvoos_nds_tokens[' . esc_attr( $token->id ) . ']';
		$value = esc_attr( $token->value );
		$id    = 'nvoos-nds-' . esc_attr( $token->id );

		switch ( $token->type ) {
			case 'color':
				printf(
					'<input type="color" name="%s" id="%s" value="%s" class="nvoos-nds-color-picker" data-default="%s">',
					esc_attr( $name ),
					esc_attr( $id ),
					esc_attr( $value ),
					esc_attr( $token->default )
				);
				break;

			case 'size':
			case 'font':
			case 'shadow':
			case 'transition':
			default:
				printf(
					'<input type="text" name="%s" id="%s" value="%s" class="regular-text nvoos-nds-text-input" data-default="%s">',
					esc_attr( $name ),
					esc_attr( $id ),
					esc_attr( $value ),
					esc_attr( $token->default )
				);
				break;
		}

		// Reset to default link.
		if ( $token->is_modified() ) {
			printf(
				' <button type="button" class="button button-small nvoos-nds-reset-token" data-target="%s" data-default="%s">%s</button>',
				esc_attr( $id ),
				esc_attr( $token->default ),
				esc_html__( 'Reset', 'nvoos-design-system' )
			);
		}
	}

	/**
	 * Render the live preview pane.
	 *
	 * @param string $css_vars The compiled CSS block (for display).
	 * @return void
	 */
	private function render_preview_pane( $css_vars ) {
		?>
		<div class="nvoos-nds-preview-pane postbox">
			<div class="postbox-header">
				<h2><?php esc_html_e( 'Live Preview', 'nvoos-design-system' ); ?></h2>
			</div>
			<div class="inside">
				<div class="nvoos-nds-preview-sample">
					<div class="nds-preview-card">
						<div class="nds-preview-card-image"></div>
						<div class="nds-preview-card-body">
							<span class="nds-preview-card-category">Category</span>
							<h3 class="nds-preview-card-title">Sample Product</h3>
							<div class="nds-preview-card-meta">Location • In Stock</div>
							<div class="nds-preview-card-price">$99.00</div>
						</div>
					</div>

					<div class="nds-preview-filter-bar">
						<span class="nds-preview-filter-pill active">All</span>
						<span class="nds-preview-filter-pill">Option A</span>
						<span class="nds-preview-filter-pill">Option B</span>
					</div>
				</div>

				<h3><?php esc_html_e( 'Generated CSS', 'nvoos-design-system' ); ?></h3>
				<pre class="nvoos-nds-css-output"><code><?php echo esc_html( $css_vars ); ?></code></pre>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the export section (JSON + DTCG download).
	 *
	 * @return void
	 */
	private function render_export_section() {
		$export_json = wp_json_encode( $this->registry->get_values_map(), JSON_PRETTY_PRINT );
		$dtcg_url    = wp_nonce_url(
			admin_url( 'admin-post.php?action=nvoos_nds_export_dtcg' ),
			'nvoos_nds_dtcg_export',
			'nvoos_nds_dtcg_nonce'
		);
		?>
		<div class="nvoos-nds-export postbox">
			<div class="postbox-header">
				<h2><?php esc_html_e( 'Export Tokens', 'nvoos-design-system' ); ?></h2>
			</div>
			<div class="inside">
				<p class="description">
					<?php esc_html_e( 'Copy the JSON below to back up your configuration, or download in DTCG format for use with Figma and Style Dictionary.', 'nvoos-design-system' ); ?>
				</p>
				<textarea readonly rows="8" class="large-text code" id="nvoos-nds-export-json"><?php echo esc_textarea( $export_json ); ?></textarea>
				<p>
					<button type="button" class="button" id="nvoos-nds-copy-export">
						<?php esc_html_e( 'Copy to Clipboard', 'nvoos-design-system' ); ?>
					</button>
					<a href="<?php echo esc_url( $dtcg_url ); ?>" class="button">
						<?php esc_html_e( 'Download DTCG', 'nvoos-design-system' ); ?>
					</a>
				</p>
			</div>
		</div>
		<?php
	}

	// -----------------------------------------------------------------------
	// Form handling.
	// -----------------------------------------------------------------------

	/**
	 * Process form submissions.
	 *
	 * @return void
	 */
	private function maybe_handle_post() {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			return;
		}

		if ( ! isset( $_POST['nvoos_nds_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nvoos_nds_nonce'] ) ), 'nvoos_nds_save' ) ) {
			wp_die( esc_html__( 'Security check failed. Please try again.', 'nvoos-design-system' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'nvoos-design-system' ) );
		}

		// Always process the toggles (checkbox state).
		$this->handle_settings_save();

		$action = isset( $_POST['nvoos_nds_action'] ) ? sanitize_text_field( wp_unslash( $_POST['nvoos_nds_action'] ) ) : '';

		switch ( $action ) {
			case 'apply_preset':
				$this->handle_apply_preset();
				break;

			default:
				$this->handle_save_tokens();
				break;
		}
	}

	/**
	 * Handle the @property toggle and other non-token settings.
	 *
	 * @return void
	 */
	private function handle_settings_save() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in maybe_handle_post.
		$typed = isset( $_POST['nvoos_nds_use_typed_properties'] ) ? 1 : 0;
		update_option( NV_oOS_Design_System_Plugin::TYPED_PROPERTY_KEY, $typed );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in maybe_handle_post.
		$legacy = isset( $_POST['nvoos_nds_legacy_aliases'] ) ? 1 : 0;
		update_option( NV_oOS_Design_System_Plugin::LEGACY_ALIAS_KEY, $legacy );

		NV_oOS_Design_System_Plugin::reset_css_generator();
		delete_transient( NV_oOS_Design_System_Plugin::CSS_CACHE_KEY );
	}

	/**
	 * Handle preset application.
	 *
	 * @return void
	 */
	private function handle_apply_preset() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in maybe_handle_post.
		$class = isset( $_POST['nvoos_nds_preset'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- same as above.
			? sanitize_text_field( wp_unslash( $_POST['nvoos_nds_preset'] ) )
			: '';

		if ( ! isset( $this->presets[ $class ] ) ) {
			add_settings_error(
				'nvoos_nds',
				'invalid_preset',
				__( 'Invalid preset selected.', 'nvoos-design-system' ),
				'error'
			);
			return;
		}

		$this->registry->apply_preset( $class );
		$this->registry->save();

		add_settings_error(
			'nvoos_nds',
			'preset_applied',
			sprintf(
				/* translators: %s: preset name */
				__( 'Preset "%s" applied successfully.', 'nvoos-design-system' ),
				$this->presets[ $class ]
			),
			'success'
		);
	}

	/**
	 * Handle token value save.
	 *
	 * @return void
	 */
	private function handle_save_tokens() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in maybe_handle_post.
		if ( ! isset( $_POST['nvoos_nds_tokens'] ) || ! is_array( $_POST['nvoos_nds_tokens'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing -- values sanitised per-token in Token_Registry.
		$raw     = wp_unslash( $_POST['nvoos_nds_tokens'] );
		$updated = $this->registry->set_all( $raw );
		$this->registry->save();

		if ( $updated > 0 ) {
			add_settings_error(
				'nvoos_nds',
				'tokens_saved',
				sprintf(
					/* translators: %d: number of tokens updated */
					_n(
						'%d token updated.',
						'%d tokens updated.',
						$updated,
						'nvoos-design-system'
					),
					$updated
				),
				'success'
			);
		}
	}

	// -----------------------------------------------------------------------
	// Utility.
	// -----------------------------------------------------------------------

	/**
	 * Render any queued admin notices.
	 *
	 * @return void
	 */
	private function render_notices() {
		settings_errors( 'nvoos_nds' );
		settings_errors( 'nvoos_nds_email' );
	}

	/**
	 * Get a compact CSS string for the preview pane.
	 *
	 * @return string
	 */
	private function get_css_preview() {
		$generator = NV_oOS_Design_System_Plugin::css_generator();
		$css       = $generator->generate();

		// Pretty-print for the code view.
		$css = str_replace( ';', ";\n  ", $css );
		$css = str_replace( '{', "{\n  ", $css );
		$css = str_replace( '}', "}\n", $css );

		return $css;
	}
}
