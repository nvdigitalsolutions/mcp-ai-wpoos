<?php
/**
 * NV oOS Crocoblock DS — Admin Settings Page
 *
 * @package NV_oOS_Crocoblock_DS
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and renders the admin settings page for the Crocoblock Design System.
 *
 * The page lives under Settings → Crocoblock Design System and provides:
 *   - Visual token editor (colour pickers, range sliders)
 *   - Preset selector (apply a predefined token set)
 *   - @property toggle (typed CSS custom properties)
 *   - DTCG JSON export (W3C Design Tokens format)
 *   - Live preview pane
 *
 * @since 0.1.0
 */
class NV_oOS_Crocoblock_DS_Admin_Page {

	/**
	 * Token registry instance.
	 *
	 * @var NV_oOS_Crocoblock_DS_Token_Registry
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
	 * @param NV_oOS_Crocoblock_DS_Token_Registry $registry Token registry.
	 */
	public function __construct( $registry ) {
		$this->registry = $registry;

		$this->presets = array(
			'NV_oOS_Crocoblock_DS_Preset_Minimal'   => __( 'Minimal (Default)', 'nvoos-crocoblock-ds' ),
			'NV_oOS_Crocoblock_DS_Preset_Ecommerce' => __( 'Ecommerce', 'nvoos-crocoblock-ds' ),
			'NV_oOS_Crocoblock_DS_Preset_Directory' => __( 'Directory', 'nvoos-crocoblock-ds' ),
		);

		$this->group_labels = array(
			'colors'      => __( 'Colors', 'nvoos-crocoblock-ds' ),
			'typography'  => __( 'Typography', 'nvoos-crocoblock-ds' ),
			'spacing'     => __( 'Spacing', 'nvoos-crocoblock-ds' ),
			'borders'     => __( 'Borders', 'nvoos-crocoblock-ds' ),
			'shadows'     => __( 'Shadows', 'nvoos-crocoblock-ds' ),
			'sizing'      => __( 'Sizing', 'nvoos-crocoblock-ds' ),
			'transitions' => __( 'Transitions', 'nvoos-crocoblock-ds' ),
		);
	}

	/**
	 * Register the admin menu page.
	 *
	 * @return void
	 */
	public function register() {
		add_options_page(
			__( 'Crocoblock Design System', 'nvoos-crocoblock-ds' ),
			__( 'Crocoblock DS', 'nvoos-crocoblock-ds' ),
			'manage_options',
			'nvoos-cds-settings',
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

		$grouped  = $this->registry->get_grouped();
		$css_vars = $this->get_css_preview();

		?>
		<div class="wrap nvoos-cds-wrap">
			<h1><?php echo esc_html__( 'Crocoblock Design System', 'nvoos-crocoblock-ds' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Configure design tokens that apply across JetEngine listings, JetSmartFilters, and JetFormBuilder.', 'nvoos-crocoblock-ds' ); ?>
			</p>

			<hr class="wp-header-end">

			<?php $this->render_notices(); ?>

			<div class="nvoos-cds-layout">
				<div class="nvoos-cds-main">
					<form method="post" action="" id="nvoos-cds-form">
						<?php wp_nonce_field( 'nvoos_cds_save', 'nvoos_cds_nonce' ); ?>

						<?php $this->render_preset_selector(); ?>

						<?php $this->render_settings_bar(); ?>

						<?php
						foreach ( $this->group_labels as $group_key => $group_label ) {
							if ( isset( $grouped[ $group_key ] ) ) {
								$this->render_group_section( $group_key, $group_label, $grouped[ $group_key ] );
							}
						}
						?>

						<?php submit_button( __( 'Save Changes', 'nvoos-crocoblock-ds' ) ); ?>
					</form>
				</div>

				<div class="nvoos-cds-sidebar">
					<?php $this->render_preview_pane( $css_vars ); ?>
					<?php $this->render_export_section(); ?>
				</div>
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
		<div class="nvoos-cds-preset-bar">
			<label for="nvoos-cds-preset">
				<?php esc_html_e( 'Apply Preset:', 'nvoos-crocoblock-ds' ); ?>
			</label>
			<select name="nvoos_cds_preset" id="nvoos-cds-preset">
				<option value=""><?php esc_html_e( '— Select a preset —', 'nvoos-crocoblock-ds' ); ?></option>
				<?php foreach ( $this->presets as $class => $label ) : ?>
					<option value="<?php echo esc_attr( $class ); ?>">
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<button type="submit" name="nvoos_cds_action" value="apply_preset" class="button">
				<?php esc_html_e( 'Apply', 'nvoos-crocoblock-ds' ); ?>
			</button>
		</div>
		<?php
	}

	/**
	 * Render the settings bar (@property toggle, DTCG export).
	 *
	 * @return void
	 */
	private function render_settings_bar() {
		$typed_enabled = NV_oOS_Crocoblock_DS_Plugin::is_typed_properties_enabled();
		$dtcg_url      = wp_nonce_url(
			admin_url( 'admin-post.php?action=nvoos_cds_export_dtcg' ),
			'nvoos_cds_dtcg_export',
			'nvoos_cds_dtcg_nonce'
		);
		?>
		<div class="nvoos-cds-settings-bar">
			<div class="nvoos-cds-settings-row">
				<label for="nvoos-cds-typed-props">
					<input
						type="checkbox"
						name="nvoos_cds_use_typed_properties"
						id="nvoos-cds-typed-props"
						value="1"
						<?php checked( $typed_enabled ); ?>
					>
					<?php esc_html_e( 'Generate typed CSS custom properties (@property)', 'nvoos-crocoblock-ds' ); ?>
				</label>
				<p class="description">
					<?php esc_html_e( 'Enables browser type-checking, DevTools colour pickers, and animatable tokens. Requires modern browser support (Chrome 85+, Firefox 128+, Safari 16.4+).', 'nvoos-crocoblock-ds' ); ?>
				</p>
			</div>

			<div class="nvoos-cds-settings-row">
				<a href="<?php echo esc_url( $dtcg_url ); ?>" class="button">
					<?php esc_html_e( 'Export as DTCG (Design Tokens JSON)', 'nvoos-crocoblock-ds' ); ?>
				</a>
				<p class="description">
					<?php esc_html_e( 'Download tokens in the W3C Design Tokens Community Group format. Compatible with Tokens Studio for Figma, Style Dictionary, and Terrazzo.', 'nvoos-crocoblock-ds' ); ?>
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
	 * @param array<string, NV_oOS_Crocoblock_DS_Data_Token> $tokens      Tokens in this group.
	 * @return void
	 */
	private function render_group_section( $group_key, $group_label, $tokens ) {
		?>
		<div class="nvoos-cds-group" data-group="<?php echo esc_attr( $group_key ); ?>">
			<h2 class="nvoos-cds-group-title">
				<?php echo esc_html( $group_label ); ?>
			</h2>
			<table class="form-table nvoos-cds-tokens-table">
				<tbody>
					<?php foreach ( $tokens as $token ) : ?>
						<tr class="nvoos-cds-token-row" data-token-id="<?php echo esc_attr( $token->id ); ?>">
							<th scope="row">
								<label for="nvoos-cds-<?php echo esc_attr( $token->id ); ?>">
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
								<code class="nvoos-cds-css-var">
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
	 * @param NV_oOS_Crocoblock_DS_Data_Token $token Token definition.
	 * @return void
	 */
	private function render_token_input( $token ) {
		$name  = 'nvoos_cds_tokens[' . esc_attr( $token->id ) . ']';
		$value = esc_attr( $token->value );
		$id    = 'nvoos-cds-' . esc_attr( $token->id );

		switch ( $token->type ) {
			case 'color':
				printf(
					'<input type="color" name="%s" id="%s" value="%s" class="nvoos-cds-color-picker" data-default="%s">',
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
					'<input type="text" name="%s" id="%s" value="%s" class="regular-text nvoos-cds-text-input" data-default="%s">',
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
				' <button type="button" class="button button-small nvoos-cds-reset-token" data-target="%s" data-default="%s">%s</button>',
				esc_attr( $id ),
				esc_attr( $token->default ),
				esc_html__( 'Reset', 'nvoos-crocoblock-ds' )
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
		<div class="nvoos-cds-preview-pane postbox">
			<div class="postbox-header">
				<h2><?php esc_html_e( 'Live Preview', 'nvoos-crocoblock-ds' ); ?></h2>
			</div>
			<div class="inside">
				<div class="nvoos-cds-preview-sample">
					<div class="cds-preview-card">
						<div class="cds-preview-card-image"></div>
						<div class="cds-preview-card-body">
							<span class="cds-preview-card-category">Category</span>
							<h3 class="cds-preview-card-title">Sample Product</h3>
							<div class="cds-preview-card-meta">Location • In Stock</div>
							<div class="cds-preview-card-price">$99.00</div>
						</div>
					</div>

					<div class="cds-preview-filter-bar">
						<span class="cds-preview-filter-pill active">All</span>
						<span class="cds-preview-filter-pill">Option A</span>
						<span class="cds-preview-filter-pill">Option B</span>
					</div>
				</div>

				<h3><?php esc_html_e( 'Generated CSS', 'nvoos-crocoblock-ds' ); ?></h3>
				<pre class="nvoos-cds-css-output"><code><?php echo esc_html( $css_vars ); ?></code></pre>
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
			admin_url( 'admin-post.php?action=nvoos_cds_export_dtcg' ),
			'nvoos_cds_dtcg_export',
			'nvoos_cds_dtcg_nonce'
		);
		?>
		<div class="nvoos-cds-export postbox">
			<div class="postbox-header">
				<h2><?php esc_html_e( 'Export Tokens', 'nvoos-crocoblock-ds' ); ?></h2>
			</div>
			<div class="inside">
				<p class="description">
					<?php esc_html_e( 'Copy the JSON below to back up your configuration, or download in DTCG format for use with Figma and Style Dictionary.', 'nvoos-crocoblock-ds' ); ?>
				</p>
				<textarea readonly rows="8" class="large-text code" id="nvoos-cds-export-json"><?php echo esc_textarea( $export_json ); ?></textarea>
				<p>
					<button type="button" class="button" id="nvoos-cds-copy-export">
						<?php esc_html_e( 'Copy to Clipboard', 'nvoos-crocoblock-ds' ); ?>
					</button>
					<a href="<?php echo esc_url( $dtcg_url ); ?>" class="button">
						<?php esc_html_e( 'Download DTCG', 'nvoos-crocoblock-ds' ); ?>
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

		if ( ! isset( $_POST['nvoos_cds_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nvoos_cds_nonce'] ) ), 'nvoos_cds_save' ) ) {
			wp_die( esc_html__( 'Security check failed. Please try again.', 'nvoos-crocoblock-ds' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'nvoos-crocoblock-ds' ) );
		}

		// Always process the @property toggle (checkbox state).
		$this->handle_settings_save();

		$action = isset( $_POST['nvoos_cds_action'] ) ? sanitize_text_field( wp_unslash( $_POST['nvoos_cds_action'] ) ) : '';

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
		$typed = isset( $_POST['nvoos_cds_use_typed_properties'] ) ? 1 : 0;
		update_option( NV_oOS_Crocoblock_DS_Plugin::TYPED_PROPERTY_KEY, $typed );
	}

	/**
	 * Handle preset application.
	 *
	 * @return void
	 */
	private function handle_apply_preset() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in maybe_handle_post.
		$class = isset( $_POST['nvoos_cds_preset'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- same as above.
			? sanitize_text_field( wp_unslash( $_POST['nvoos_cds_preset'] ) )
			: '';

		if ( ! isset( $this->presets[ $class ] ) ) {
			add_settings_error(
				'nvoos_cds',
				'invalid_preset',
				__( 'Invalid preset selected.', 'nvoos-crocoblock-ds' ),
				'error'
			);
			return;
		}

		$this->registry->apply_preset( $class );
		$this->registry->save();

		add_settings_error(
			'nvoos_cds',
			'preset_applied',
			sprintf(
				/* translators: %s: preset name */
				__( 'Preset "%s" applied successfully.', 'nvoos-crocoblock-ds' ),
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
		if ( ! isset( $_POST['nvoos_cds_tokens'] ) || ! is_array( $_POST['nvoos_cds_tokens'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing -- values sanitised per-token in Token_Registry.
		$raw     = wp_unslash( $_POST['nvoos_cds_tokens'] );
		$updated = $this->registry->set_all( $raw );
		$this->registry->save();

		if ( $updated > 0 ) {
			add_settings_error(
				'nvoos_cds',
				'tokens_saved',
				sprintf(
					/* translators: %d: number of tokens updated */
					_n(
						'%d token updated.',
						'%d tokens updated.',
						$updated,
						'nvoos-crocoblock-ds'
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
		settings_errors( 'nvoos_cds' );
	}

	/**
	 * Get a compact CSS string for the preview pane.
	 *
	 * @return string
	 */
	private function get_css_preview() {
		$generator = NV_oOS_Crocoblock_DS_Plugin::css_generator();
		$css       = $generator->generate();

		// Pretty-print for the code view.
		$css = str_replace( ';', ";\n  ", $css );
		$css = str_replace( '{', "{\n  ", $css );
		$css = str_replace( '}', "}\n", $css );

		return $css;
	}
}
