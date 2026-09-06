<?php
declare(strict_types=1);

namespace NvoosContentGraph\Admin;

/**
 * Abstract base class for settings sections.
 *
 * Every tab on the Knowledge Graph settings page is composed of one or
 * more sections. Each section owns its fields, sanitization, validation,
 * and rendering — addons extend this class and register instances via
 * {@see SettingsRegistry::register_section()}.
 *
 * Pattern mirrored from the NV oOS base plugin's WP_MCP_AI_Settings_Section.
 *
 * @since 1.0.0
 */
abstract class Section {

	/**
	 * Get the unique section identifier.
	 *
	 * @return string
	 */
	abstract public function get_id(): string;

	/**
	 * Get the human-readable section title.
	 *
	 * @return string
	 */
	abstract public function get_title(): string;

	/**
	 * Get the tab slug this section belongs to.
	 *
	 * @return string
	 */
	abstract public function get_tab(): string;

	/**
	 * Get field definitions for this section.
	 *
	 * Returns an associative array of `field_key => definition` where
	 * each definition has: 'type' (string), 'label' (string), and
	 * optional 'description', 'options', 'min', 'max', 'default'.
	 *
	 * @return array<string, array<string,mixed>>
	 */
	abstract public function get_fields(): array;

	/**
	 * Render the section's fields inside a <table class="form-table">.
	 *
	 * Default implementation iterates {@see get_fields()} and calls
	 * {@see render_field()} for each. Override for custom rendering.
	 *
	 * @return void
	 */
	public function render(): void {
		foreach ( $this->get_fields() as $key => $field ) {
			$this->render_field( $key, $field );
		}
	}

	/**
	 * Get section priority (lower numbers render first within a tab).
	 *
	 * @return int
	 */
	public function get_priority(): int {
		return 10;
	}

	/**
	 * Get section description (shown between heading and form-table).
	 *
	 * @return string
	 */
	public function get_description(): string {
		return '';
	}

	/**
	 * Sanitize input values for this section.
	 *
	 * Default implementation sanitizes each field by type.
	 * Override for custom sanitization logic.
	 *
	 * @param array<string,mixed> $input Raw submitted values keyed by setting key.
	 * @return array<string,mixed> Sanitized values.
	 */
	public function sanitize( array $input ): array {
		$sanitized = array();

		foreach ( $this->get_fields() as $key => $field ) {
			$value = $input[ $key ] ?? null;
			if ( null === $value ) {
				continue;
			}
			$sanitized[ $key ] = $this->sanitize_field_value( $key, $value, $field );
		}

		return $sanitized;
	}

	/**
	 * Sanitize a single field value based on its type.
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value Raw value.
	 * @param array  $field Field definition.
	 * @return mixed
	 */
	protected function sanitize_field_value( string $key, $value, array $field ) {
		switch ( $field['type'] ?? 'text' ) {
			case 'checkbox':
				return ! empty( $value ) ? 1 : 0;

			case 'number':
				$val = absint( $value );
				if ( isset( $field['min'] ) ) {
					$val = max( (int) $field['min'], $val );
				}
				if ( isset( $field['max'] ) ) {
					$val = min( (int) $field['max'], $val );
				}
				return $val;

			case 'decimal':
				$val = (float) $value;
				if ( isset( $field['min'] ) ) {
					$val = max( (float) $field['min'], $val );
				}
				if ( isset( $field['max'] ) ) {
					$val = min( (float) $field['max'], $val );
				}
				return $val;

			case 'color':
				$clean = \sanitize_hex_color( (string) $value );
				if ( $clean ) {
					return $clean;
				}
				$default = \sanitize_hex_color( (string) ( $field['default'] ?? '' ) );
				return $default ?: '';

			case 'icon':
				$allowed = \NvoosContentGraph\Visual\Tokens::icon_catalog();
				$value   = \sanitize_key( (string) $value );
				if ( isset( $allowed[ $value ] ) ) {
					return $value;
				}
				$default = \sanitize_key( (string) ( $field['default'] ?? '' ) );
				return isset( $allowed[ $default ] ) ? $default : 'dot';

			case 'select':
				$allowed = array_keys( $field['options'] ?? array() );
				if ( in_array( $value, $allowed, true ) ) {
					return $value;
				}
				return $field['default'] ?? ( $allowed[0] ?? '' );

			case 'password':
			case 'text':
				return \sanitize_text_field( (string) $value );

			case 'textarea':
				return \sanitize_textarea_field( (string) $value );

			case 'array':
				if ( empty( $value ) || ! \is_array( $value ) ) {
					return array();
				}
				return \array_values( \array_filter( \array_map( 'sanitize_key', $value ) ) );

			default:
				return \sanitize_text_field( (string) $value );
		}
	}

	/**
	 * Render a single settings field as a table row.
	 *
	 * @param string $key   Setting key.
	 * @param array  $field Field definition.
	 * @return void
	 */
	protected function render_field( string $key, array $field ): void {
		$option_name = \NvoosContentGraph\Schema::OPTION_SETTINGS;
		$settings    = \NvoosContentGraph\Settings::all();
		$value       = $settings[ $key ] ?? ( $field['default'] ?? '' );

		/**
		 * Filter a field's value before it is rendered.
		 *
		 * Lets addons mask sensitive values (e.g. the AI addon masks
		 * stored API keys so they are never echoed back into the form).
		 *
		 * @since 1.0.4
		 *
		 * @param mixed  $value Current value.
		 * @param string $key   Setting key.
		 * @param array  $field Field definition.
		 */
		$value = \apply_filters( 'nvoos_content_graph/section_field_value', $value, $key, $field );
		$name  = \esc_attr( $option_name . '[' . $key . ']' );
		$label = $field['label'] ?? '';
		$desc  = $field['description'] ?? '';
		$type  = $field['type'] ?? 'text';

		echo '<tr><th scope="row">' . \esc_html( $label ) . '</th><td>';

		switch ( $type ) {
			case 'checkbox':
				echo '<label>';
				printf(
					'<input type="checkbox" name="%s" value="1" %s>',
					esc_attr( $name ),
					\checked( 1, $value, false )
				);
				if ( $desc ) {
					echo ' ' . \esc_html( $desc );
				}
				echo '</label>';
				break;

			case 'select':
				printf( '<select name="%s">', esc_attr( $name ) );
				foreach ( ( $field['options'] ?? array() ) as $opt_value => $opt_label ) {
					echo '<option value="' . \esc_attr( $opt_value ) . '" ' . \selected( $value, $opt_value, false ) . '>' . \esc_html( $opt_label ) . '</option>';
				}
				echo '</select>';
				if ( $desc ) {
					echo '<p class="description">' . \esc_html( $desc ) . '</p>';
				}
				break;

			case 'number':
				printf(
					'<input type="number" name="%s" value="%d"%s%s class="small-text">',
					esc_attr( $name ),
					\absint( $value ),
					isset( $field['min'] ) ? sprintf( ' min="%d"', \absint( $field['min'] ) ) : '',
					isset( $field['max'] ) ? sprintf( ' max="%d"', \absint( $field['max'] ) ) : ''
				);
				if ( $desc ) {
					echo '<p class="description">' . \esc_html( $desc ) . '</p>';
				}
				break;

			case 'decimal':
				printf(
					'<input type="number" step="0.05" name="%s" value="%s"%s%s class="small-text">',
					esc_attr( $name ),
					esc_attr( (string) (float) $value ),
					isset( $field['min'] ) ? sprintf( ' min="%s"', \esc_attr( (string) (float) $field['min'] ) ) : '',
					isset( $field['max'] ) ? sprintf( ' max="%s"', \esc_attr( (string) (float) $field['max'] ) ) : ''
				);
				if ( $desc ) {
					echo '<p class="description">' . \esc_html( $desc ) . '</p>';
				}
				break;

			case 'color':
				printf(
					'<input type="text" name="%s" value="%s" class="nvoos-cg-color-field" data-default-color="%s">',
					esc_attr( $name ),
					esc_attr( (string) $value ),
					esc_attr( (string) ( $field['default'] ?? '' ) )
				);
				if ( $desc ) {
					echo '<p class="description">' . \esc_html( $desc ) . '</p>';
				}
				break;

			case 'icon':
				$catalog = \NvoosContentGraph\Visual\Tokens::icon_catalog();
				printf( '<select name="%s">', esc_attr( $name ) );
				foreach ( $catalog as $opt_value => $opt_label ) {
					echo '<option value="' . \esc_attr( $opt_value ) . '" ' . \selected( $value, $opt_value, false ) . '>' . \esc_html( $opt_label ) . '</option>';
				}
				echo '</select>';
				if ( $desc ) {
					echo '<p class="description">' . \esc_html( $desc ) . '</p>';
				}
				break;

			case 'password':
				printf(
					'<input type="password" name="%s" value="%s" class="regular-text" autocomplete="new-password">',
					esc_attr( $name ),
					\esc_attr( $value )
				);
				if ( $desc ) {
					echo '<p class="description">' . \esc_html( $desc ) . '</p>';
				}
				break;

			case 'textarea':
				printf(
					'<textarea name="%s" rows="%d" class="large-text">%s</textarea>',
					esc_attr( $name ),
					\absint( $field['rows'] ?? 6 ),
					\esc_textarea( (string) $value )
				);
				if ( $desc ) {
					echo '<p class="description">' . \esc_html( $desc ) . '</p>';
				}
				break;

			case 'text':
			default:
				printf(
					'<input type="text" name="%s" value="%s" class="regular-text">',
					esc_attr( $name ),
					\esc_attr( $value )
				);
				if ( $desc ) {
					echo '<p class="description">' . \esc_html( $desc ) . '</p>';
				}
				break;
		}

		echo '</td></tr>';
	}

	/**
	 * Render the section wrapper (heading + description + form-table).
	 *
	 * @param string $page_slug The settings page slug (unused in this implementation).
	 * @return void
	 */
	public function render_wrapper( string $page_slug = '' ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- public API, used by SettingsRegistry
		?>
		<h2><?php echo \esc_html( $this->get_title() ); ?></h2>
		<?php if ( $this->get_description() ) : ?>
			<p><?php echo \esc_html( $this->get_description() ); ?></p>
		<?php endif; ?>
		<table class="form-table">
			<tbody>
				<?php $this->render(); ?>
			</tbody>
		</table>
		<?php
	}
}
