<?php
/**
 * Admin Settings Renderer for NV oOS.
 *
 * Handles UI rendering logic for the settings page.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Admin_Settings_Renderer' ) ) {
	/**
	 * Renders settings page UI elements.
	 */
	class WP_MCP_AI_Admin_Settings_Renderer {

		/**
		 * Settings base instance.
		 *
		 * @var WP_MCP_AI_Admin_Settings_Base
		 */
		private $settings_base;

		/**
		 * Constructor.
		 *
		 * @param WP_MCP_AI_Admin_Settings_Base $settings_base Settings base instance.
		 */
		public function __construct( $settings_base ) {
			$this->settings_base = $settings_base;
		}

		/**
		 * Render a text input field.
		 *
		 * @param string $name Field name.
		 * @param string $label Field label.
		 * @param string $description Field description.
		 * @param array  $args Additional arguments.
		 */
		public function render_text_field( $name, $label, $description = '', $args = array() ) {
			$settings = WP_MCP_AI_Admin_Settings_Base::get_settings();
			$value    = isset( $settings[ $name ] ) ? $settings[ $name ] : '';
			$type     = isset( $args['type'] ) ? $args['type'] : 'text';
			$class    = isset( $args['class'] ) ? $args['class'] : 'regular-text';

			?>
			<label for="<?php echo esc_attr( $name ); ?>">
				<?php echo esc_html( $label ); ?>
			</label>
			<input 
				type="<?php echo esc_attr( $type ); ?>" 
				id="<?php echo esc_attr( $name ); ?>" 
				name="<?php echo esc_attr( WP_MCP_AI_Admin_Settings_Base::OPTION_NAME ); ?>[<?php echo esc_attr( $name ); ?>]" 
				value="<?php echo esc_attr( $value ); ?>" 
				class="<?php echo esc_attr( $class ); ?>"
			/>
			<?php if ( ! empty( $description ) ) : ?>
				<p class="description"><?php echo wp_kses_post( $description ); ?></p>
			<?php endif; ?>
			<?php
		}

		/**
		 * Render a password input field.
		 *
		 * @param string $name Field name.
		 * @param string $label Field label.
		 * @param string $description Field description.
		 */
		public function render_password_field( $name, $label, $description = '' ) {
			$this->render_text_field( $name, $label, $description, array( 'type' => 'password' ) );
		}

		/**
		 * Render a textarea field.
		 *
		 * @param string $name Field name.
		 * @param string $label Field label.
		 * @param string $description Field description.
		 * @param array  $args Additional arguments.
		 */
		public function render_textarea_field( $name, $label, $description = '', $args = array() ) {
			$settings = WP_MCP_AI_Admin_Settings_Base::get_settings();
			$value    = isset( $settings[ $name ] ) ? $settings[ $name ] : '';
			$rows     = isset( $args['rows'] ) ? $args['rows'] : 5;
			$cols     = isset( $args['cols'] ) ? $args['cols'] : 50;
			$class    = isset( $args['class'] ) ? $args['class'] : 'large-text';

			?>
			<label for="<?php echo esc_attr( $name ); ?>">
				<?php echo esc_html( $label ); ?>
			</label>
			<textarea 
				id="<?php echo esc_attr( $name ); ?>" 
				name="<?php echo esc_attr( WP_MCP_AI_Admin_Settings_Base::OPTION_NAME ); ?>[<?php echo esc_attr( $name ); ?>]" 
				rows="<?php echo esc_attr( $rows ); ?>" 
				cols="<?php echo esc_attr( $cols ); ?>"
				class="<?php echo esc_attr( $class ); ?>"
			><?php echo esc_textarea( $value ); ?></textarea>
			<?php if ( ! empty( $description ) ) : ?>
				<p class="description"><?php echo wp_kses_post( $description ); ?></p>
			<?php endif; ?>
			<?php
		}

		/**
		 * Render a checkbox field.
		 *
		 * @param string $name Field name.
		 * @param string $label Field label.
		 * @param string $description Field description.
		 */
		public function render_checkbox_field( $name, $label, $description = '' ) {
			$settings = WP_MCP_AI_Admin_Settings_Base::get_settings();
			$checked  = ! empty( $settings[ $name ] );

			?>
			<label for="<?php echo esc_attr( $name ); ?>">
				<input 
					type="checkbox" 
					id="<?php echo esc_attr( $name ); ?>" 
					name="<?php echo esc_attr( WP_MCP_AI_Admin_Settings_Base::OPTION_NAME ); ?>[<?php echo esc_attr( $name ); ?>]" 
					value="1" 
					<?php checked( $checked ); ?>
				/>
				<?php echo esc_html( $label ); ?>
			</label>
			<?php if ( ! empty( $description ) ) : ?>
				<p class="description"><?php echo wp_kses_post( $description ); ?></p>
			<?php endif; ?>
			<?php
		}

		/**
		 * Render a select dropdown field.
		 *
		 * @param string $name Field name.
		 * @param string $label Field label.
		 * @param array  $options Options array (value => label).
		 * @param string $description Field description.
		 * @param array  $args Additional arguments.
		 */
		public function render_select_field( $name, $label, $options, $description = '', $args = array() ) {
			$settings = WP_MCP_AI_Admin_Settings_Base::get_settings();
			$value    = isset( $settings[ $name ] ) ? $settings[ $name ] : '';
			$class    = isset( $args['class'] ) ? $args['class'] : 'regular-text';

			?>
			<label for="<?php echo esc_attr( $name ); ?>">
				<?php echo esc_html( $label ); ?>
			</label>
			<select 
				id="<?php echo esc_attr( $name ); ?>" 
				name="<?php echo esc_attr( WP_MCP_AI_Admin_Settings_Base::OPTION_NAME ); ?>[<?php echo esc_attr( $name ); ?>]" 
				class="<?php echo esc_attr( $class ); ?>"
			>
				<?php foreach ( $options as $option_value => $option_label ) : ?>
					<option value="<?php echo esc_attr( $option_value ); ?>" <?php selected( $value, $option_value ); ?>>
						<?php echo esc_html( $option_label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<?php if ( ! empty( $description ) ) : ?>
				<p class="description"><?php echo wp_kses_post( $description ); ?></p>
			<?php endif; ?>
			<?php
		}

		/**
		 * Render a number input field.
		 *
		 * @param string $name Field name.
		 * @param string $label Field label.
		 * @param string $description Field description.
		 * @param array  $args Additional arguments (min, max, step).
		 */
		public function render_number_field( $name, $label, $description = '', $args = array() ) {
			$settings = WP_MCP_AI_Admin_Settings_Base::get_settings();
			$value    = isset( $settings[ $name ] ) ? $settings[ $name ] : '';
			$min      = isset( $args['min'] ) ? $args['min'] : '';
			$max      = isset( $args['max'] ) ? $args['max'] : '';
			$step     = isset( $args['step'] ) ? $args['step'] : '1';
			$class    = isset( $args['class'] ) ? $args['class'] : 'small-text';

			?>
			<label for="<?php echo esc_attr( $name ); ?>">
				<?php echo esc_html( $label ); ?>
			</label>
			<input 
				type="number" 
				id="<?php echo esc_attr( $name ); ?>" 
				name="<?php echo esc_attr( WP_MCP_AI_Admin_Settings_Base::OPTION_NAME ); ?>[<?php echo esc_attr( $name ); ?>]" 
				value="<?php echo esc_attr( $value ); ?>" 
				class="<?php echo esc_attr( $class ); ?>"
				<?php
				if ( '' !== $min ) :
					?>
					min="<?php echo esc_attr( $min ); ?>"<?php endif; ?>
				<?php
				if ( '' !== $max ) :
					?>
					max="<?php echo esc_attr( $max ); ?>"<?php endif; ?>
				step="<?php echo esc_attr( $step ); ?>"
			/>
			<?php if ( ! empty( $description ) ) : ?>
				<p class="description"><?php echo wp_kses_post( $description ); ?></p>
			<?php endif; ?>
			<?php
		}

		/**
		 * Render a color picker field.
		 *
		 * @param string $name Field name.
		 * @param string $label Field label.
		 * @param string $description Field description.
		 */
		public function render_color_field( $name, $label, $description = '' ) {
			$settings = WP_MCP_AI_Admin_Settings_Base::get_settings();
			$value    = isset( $settings[ $name ] ) ? $settings[ $name ] : '';

			?>
			<label for="<?php echo esc_attr( $name ); ?>">
				<?php echo esc_html( $label ); ?>
			</label>
			<input 
				type="color" 
				id="<?php echo esc_attr( $name ); ?>" 
				name="<?php echo esc_attr( WP_MCP_AI_Admin_Settings_Base::OPTION_NAME ); ?>[<?php echo esc_attr( $name ); ?>]" 
				value="<?php echo esc_attr( $value ); ?>" 
				class="wp-color-picker"
			/>
			<?php if ( ! empty( $description ) ) : ?>
				<p class="description"><?php echo wp_kses_post( $description ); ?></p>
			<?php endif; ?>
			<?php
		}

		/**
		 * Render a section description.
		 *
		 * @param string $description Section description HTML.
		 */
		public function render_section_description( $description ) {
			echo wp_kses_post( wpautop( $description ) );
		}

		/**
		 * Render an admin notice.
		 *
		 * @param string $message Notice message.
		 * @param string $type Notice type (success, error, warning, info).
		 * @param bool   $is_dismissible Whether notice is dismissible.
		 */
		public function render_admin_notice( $message, $type = 'info', $is_dismissible = true ) {
			$dismissible_class = $is_dismissible ? 'is-dismissible' : '';
			?>
			<div class="notice notice-<?php echo esc_attr( $type ); ?> <?php echo esc_attr( $dismissible_class ); ?>">
				<p><?php echo wp_kses_post( $message ); ?></p>
			</div>
			<?php
		}

		/**
		 * Render a settings table.
		 *
		 * @param array $fields Array of field definitions.
		 */
		public function render_settings_table( $fields ) {
			?>
			<table class="form-table" role="presentation">
				<tbody>
					<?php foreach ( $fields as $field_id => $field ) : ?>
						<tr>
							<th scope="row">
								<?php if ( isset( $field['label'] ) ) : ?>
									<label for="<?php echo esc_attr( $field_id ); ?>">
										<?php echo esc_html( $field['label'] ); ?>
									</label>
								<?php endif; ?>
							</th>
							<td>
								<?php
								if ( isset( $field['callback'] ) && is_callable( $field['callback'] ) ) {
									call_user_func( $field['callback'] );
								}
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php
		}

		/**
		 * Get connector statuses for display.
		 *
		 * @param array $settings Current settings.
		 * @return array Connector statuses.
		 */
		public function get_connector_statuses( $settings ) {
			$definitions = $this->get_connector_definitions();
			$statuses    = array();

			foreach ( $definitions as $connector_id => $definition ) {
				$is_active = $this->is_connector_active( $definition, $settings );
				$status    = array(
					'id'     => $connector_id,
					'label'  => $definition['label'],
					'active' => $is_active,
				);

				if ( $is_active ) {
					if ( isset( $definition['ready_message'] ) ) {
						$status['message'] = $definition['ready_message'];
					} else {
						$status['message'] = __( 'Connected', 'wp-mcp-ai' );
					}
					$status['status_class'] = 'active';
				} elseif ( isset( $definition['empty_status'] ) ) {
					// Check if there's an empty_status configuration.
					$status['message']      = $definition['empty_status']['message'];
					$status['status_class'] = $definition['empty_status']['status'];
					} else {
						$missing_keys = $this->get_missing_connector_keys( $definition, $settings );
						if ( ! empty( $missing_keys ) ) {
							$status['message'] = $this->format_connector_missing_message(
								$missing_keys,
								$definition['fields'],
								isset( $definition['inactive_message'] ) ? $definition['inactive_message'] : ''
							);
						} else {
							$status['message'] = __( 'Not configured', 'wp-mcp-ai' );
						}
						$status['status_class'] = 'inactive';
					}
				}

				$statuses[ $connector_id ] = $status;
			}

			return $statuses;
		}

		/**
		 * Check if a connector is active.
		 *
		 * @param array $definition Connector definition.
		 * @param array $settings Current settings.
		 * @return bool Whether connector is active.
		 */
		private function is_connector_active( $definition, $settings ) {
			if ( empty( $definition['required_options'] ) ) {
				return true;
			}

			// Check if there's an active_when condition.
			if ( isset( $definition['active_when'] ) ) {
				foreach ( $definition['active_when'] as $key => $allowed_values ) {
					if ( ! isset( $settings[ $key ] ) || ! in_array( $settings[ $key ], $allowed_values, true ) ) {
						return false;
					}
				}
			}

			foreach ( $definition['required_options'] as $option ) {
				if ( empty( $settings[ $option ] ) ) {
					return false;
				}
			}

			return true;
		}

		/**
		 * Get missing connector keys.
		 *
		 * @param array $definition Connector definition.
		 * @param array $settings Current settings.
		 * @return array Missing keys.
		 */
		private function get_missing_connector_keys( $definition, $settings ) {
			$missing = array();

			if ( empty( $definition['required_options'] ) ) {
				return $missing;
			}

			foreach ( $definition['required_options'] as $option ) {
				if ( empty( $settings[ $option ] ) ) {
					$missing[] = $option;
				}
			}

			return $missing;
		}

		/**
		 * Format connector missing message.
		 *
		 * @param array  $missing_keys Missing keys.
		 * @param array  $fields Field definitions.
		 * @param string $template Message template.
		 * @return string Formatted message.
		 */
		private function format_connector_missing_message( $missing_keys, $fields, $template ) {
			if ( ! empty( $template ) ) {
				return $template;
			}

			$missing_labels = array();
			foreach ( $missing_keys as $key ) {
				if ( isset( $fields[ $key ] ) ) {
					$missing_labels[] = $fields[ $key ];
				} else {
					$missing_labels[] = ucwords( str_replace( '_', ' ', $key ) );
				}
			}

			return sprintf(
				/* translators: %s: list of missing fields */
				__( 'Missing required fields: %s', 'wp-mcp-ai' ),
				implode( ', ', $missing_labels )
			);
		}

		/**
		 * Get connector definitions.
		 *
		 * @return array Connector definitions.
		 */
		private function get_connector_definitions() {
			// This would be imported from the base class or a separate configuration file.
			// For now, returning an empty array as a placeholder.
			return array();
		}
	}
}
