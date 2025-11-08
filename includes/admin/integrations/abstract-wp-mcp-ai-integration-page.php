<?php
/**
 * Abstract base class for integration pages.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Integration_Page' ) ) {
	/**
	 * Base class that all integration pages must extend.
	 */
	abstract class WP_MCP_AI_Integration_Page {
		/**
		 * Page hook suffix.
		 *
		 * @var string
		 */
		protected $page_hook = '';

		/**
		 * Get the page slug.
		 *
		 * @return string
		 */
		abstract public function get_page_slug();

		/**
		 * Get the page title.
		 *
		 * @return string
		 */
		abstract public function get_page_title();

		/**
		 * Get the menu title.
		 *
		 * @return string
		 */
		abstract public function get_menu_title();

		/**
		 * Get the integration name.
		 *
		 * @return string
		 */
		abstract public function get_integration_name();

		/**
		 * Get field definitions for this integration.
		 *
		 * @return array
		 */
		abstract public function get_fields();

		/**
		 * Render the page content.
		 */
		abstract public function render_page();

		/**
		 * Constructor.
		 */
		public function __construct() {
			add_action( 'admin_menu', array( $this, 'register_page' ) );
			add_action( 'admin_init', array( $this, 'register_settings' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		}

		/**
		 * Register the integration page.
		 */
		public function register_page() {
			$this->page_hook = add_submenu_page(
				'wp-mcp-ai-dashboard',
				$this->get_page_title(),
				$this->get_menu_title(),
				'manage_options',
				$this->get_page_slug(),
				array( $this, 'render_page' )
			);
		}

		/**
		 * Register settings for this integration.
		 */
		public function register_settings() {
			$fields = $this->get_fields();
			
			foreach ( $fields as $field_key => $field_config ) {
				register_setting(
					$this->get_page_slug() . '_settings',
					$field_key,
					array(
						'sanitize_callback' => array( $this, 'sanitize_field' ),
					)
				);
			}
		}

		/**
		 * Sanitize field value.
		 *
		 * @param mixed $value Field value.
		 * @return mixed Sanitized value.
		 */
		public function sanitize_field( $value ) {
			// Default sanitization - can be overridden in child classes.
			if ( is_array( $value ) ) {
				return array_map( 'sanitize_text_field', $value );
			}
			return sanitize_text_field( $value );
		}

		/**
		 * Enqueue assets for the integration page.
		 *
		 * @param string $hook Current admin page hook.
		 */
		public function enqueue_assets( $hook ) {
			if ( $this->page_hook !== $hook ) {
				return;
			}

			wp_enqueue_style(
				'wp-mcp-ai-integration-page',
				WP_MCP_AI_URL . 'assets/css/admin-settings.css',
				array(),
				WP_MCP_AI_VERSION
			);
		}

		/**
		 * Render page header.
		 */
		protected function render_header() {
			?>
			<div class="wrap">
				<h1><?php echo esc_html( $this->get_page_title() ); ?></h1>
				<p class="description">
					<?php
					printf(
						/* translators: %s: integration name */
						esc_html__( 'Configure settings for %s integration.', 'wp-mcp-ai' ),
						esc_html( $this->get_integration_name() )
					);
					?>
				</p>
			<?php
		}

		/**
		 * Render page footer.
		 */
		protected function render_footer() {
			?>
			</div>
			<?php
		}

		/**
		 * Render settings form.
		 */
		protected function render_form() {
			$settings = WP_MCP_AI_Admin_Settings_Base::get_settings();
			$fields   = $this->get_fields();
			?>
			<form method="post" action="options.php">
				<?php settings_fields( $this->get_page_slug() . '_settings' ); ?>
				<table class="form-table">
					<?php foreach ( $fields as $field_key => $field_config ) : ?>
						<tr>
							<th scope="row">
								<label for="<?php echo esc_attr( $field_key ); ?>">
									<?php echo esc_html( $field_config['label'] ); ?>
								</label>
							</th>
							<td>
								<?php $this->render_field( $field_key, $field_config, $settings ); ?>
								<?php if ( ! empty( $field_config['description'] ) ) : ?>
									<p class="description">
										<?php echo esc_html( $field_config['description'] ); ?>
									</p>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>
				<?php submit_button( __( 'Save Settings', 'wp-mcp-ai' ) ); ?>
			</form>
			<?php
		}

		/**
		 * Render a single field.
		 *
		 * @param string $field_key    Field key.
		 * @param array  $field_config Field configuration.
		 * @param array  $settings     Current settings.
		 */
		protected function render_field( $field_key, $field_config, $settings ) {
			$type  = $field_config['type'] ?? 'text';
			$value = $settings[ $field_key ] ?? ( $field_config['default'] ?? '' );

			switch ( $type ) {
				case 'text':
				case 'url':
				case 'email':
					?>
					<input
						type="<?php echo esc_attr( $type ); ?>"
						id="<?php echo esc_attr( $field_key ); ?>"
						name="<?php echo esc_attr( $field_key ); ?>"
						value="<?php echo esc_attr( $value ); ?>"
						class="regular-text"
						placeholder="<?php echo esc_attr( $field_config['placeholder'] ?? '' ); ?>"
					/>
					<?php
					break;

				case 'password':
					?>
					<input
						type="password"
						id="<?php echo esc_attr( $field_key ); ?>"
						name="<?php echo esc_attr( $field_key ); ?>"
						value="<?php echo esc_attr( $value ); ?>"
						class="regular-text"
						placeholder="<?php echo esc_attr( $field_config['placeholder'] ?? '' ); ?>"
					/>
					<?php
					break;

				case 'textarea':
					?>
					<textarea
						id="<?php echo esc_attr( $field_key ); ?>"
						name="<?php echo esc_attr( $field_key ); ?>"
						class="large-text"
						rows="<?php echo esc_attr( $field_config['rows'] ?? 5 ); ?>"
						placeholder="<?php echo esc_attr( $field_config['placeholder'] ?? '' ); ?>"
					><?php echo esc_textarea( $value ); ?></textarea>
					<?php
					break;

				case 'checkbox':
					?>
					<label>
						<input
							type="checkbox"
							id="<?php echo esc_attr( $field_key ); ?>"
							name="<?php echo esc_attr( $field_key ); ?>"
							value="1"
							<?php checked( $value, true ); ?>
						/>
						<?php echo esc_html( $field_config['checkbox_label'] ?? '' ); ?>
					</label>
					<?php
					break;

				case 'select':
					?>
					<select
						id="<?php echo esc_attr( $field_key ); ?>"
						name="<?php echo esc_attr( $field_key ); ?>"
					>
						<?php foreach ( $field_config['options'] ?? array() as $option_value => $option_label ) : ?>
							<option
								value="<?php echo esc_attr( $option_value ); ?>"
								<?php selected( $value, $option_value ); ?>
							>
								<?php echo esc_html( $option_label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<?php
					break;
			}
		}

		/**
		 * Get setting value.
		 *
		 * @param string $key     Setting key.
		 * @param mixed  $default Default value.
		 * @return mixed Setting value.
		 */
		protected function get_setting( $key, $default = '' ) {
			$settings = WP_MCP_AI_Admin_Settings_Base::get_settings();
			return $settings[ $key ] ?? $default;
		}
	}
}
