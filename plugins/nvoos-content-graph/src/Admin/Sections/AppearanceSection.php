<?php
declare(strict_types=1);

namespace NvoosContentGraph\Admin\Sections;

use NvoosContentGraph\Admin\Section;
use NvoosContentGraph\Schema;
use NvoosContentGraph\Settings;
use NvoosContentGraph\Visual\Tokens;

use function array_keys;
use function array_merge;
use function array_unique;
use function array_values;
use function esc_attr;
use function esc_html;
use function esc_html_e;
use function is_array;
use function is_string;
use function sanitize_hex_color;
use function sanitize_key;
use function selected;
use function sort;

/**
 * Appearance settings section.
 *
 * Controls the visual experience system for the graph explorer — theme,
 * color encoding, icons, shapes, legend, edge styles, label density, and
 * per-type color/icon overrides with a live WCAG contrast report.
 *
 * @since 1.0.4
 */
class AppearanceSection extends Section {

	/**
	 * @inheritDoc
	 */
	public function get_id(): string {
		return 'appearance_section';
	}

	/**
	 * @inheritDoc
	 */
	public function get_title(): string {
		return __( 'Graph Appearance', 'nvoos-content-graph' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_tab(): string {
		return 'appearance';
	}

	/**
	 * @inheritDoc
	 */
	public function get_priority(): int {
		return 10;
	}

	/**
	 * @inheritDoc
	 */
	public function get_description(): string {
		return __( 'Style the graph explorer — themes, colors, icons, shapes, legend, edge styles, and label density. Changes apply to the admin explorer and (unless overridden per embed) the front-end graph.', 'nvoos-content-graph' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_fields(): array {
		$presetOptions = array( '' => __( '— Select a preset —', 'nvoos-content-graph' ) );
		foreach ( Tokens::presets() as $slug => $preset ) {
			$presetOptions[ $slug ] = $preset['label'];
		}

		return array(
			'visual_preset'          => array(
				'type'        => 'select',
				'label'       => __( 'Preset', 'nvoos-content-graph' ),
				'description' => __( 'One-click style presets. Choosing one fills the fields below — review, then Save Changes.', 'nvoos-content-graph' ),
				'options'     => $presetOptions,
				'default'     => '',
			),
			'visual_theme'           => array(
				'type'        => 'select',
				'label'       => __( 'Theme', 'nvoos-content-graph' ),
				'description' => __( 'Dark, light, follow the OS setting, or follow the WordPress admin color scheme.', 'nvoos-content-graph' ),
				'options'     => array(
					'dark'  => __( 'Dark', 'nvoos-content-graph' ),
					'light' => __( 'Light', 'nvoos-content-graph' ),
					'auto'  => __( 'Auto (OS setting)', 'nvoos-content-graph' ),
					'admin' => __( 'WordPress Admin', 'nvoos-content-graph' ),
				),
				'default'     => 'dark',
			),
			'visual_color_by'        => array(
				'type'        => 'select',
				'label'       => __( 'Color nodes by', 'nvoos-content-graph' ),
				'description' => __( 'Type (default), detected community, connection degree, or a single accent color.', 'nvoos-content-graph' ),
				'options'     => array(
					'type'       => __( 'Type', 'nvoos-content-graph' ),
					'community'  => __( 'Community', 'nvoos-content-graph' ),
					'degree'     => __( 'Degree', 'nvoos-content-graph' ),
					'monochrome' => __( 'Monochrome', 'nvoos-content-graph' ),
				),
				'default'     => 'type',
			),
			'visual_show_icons'      => array(
				'type'        => 'checkbox',
				'label'       => __( 'Show icons', 'nvoos-content-graph' ),
				'description' => __( 'Render a glyph inside each node (a redundant, colorblind-friendly encoding). Unknown types fall back to a monogram.', 'nvoos-content-graph' ),
				'default'     => 1,
			),
			'visual_icon_mode'       => array(
				'type'    => 'select',
				'label'   => __( 'Icon style', 'nvoos-content-graph' ),
				'options' => array(
					'filled'  => __( 'Filled node', 'nvoos-content-graph' ),
					'outline' => __( 'Outline node', 'nvoos-content-graph' ),
					'high'    => __( 'High contrast', 'nvoos-content-graph' ),
				),
				'default' => 'filled',
			),
			'visual_node_shapes'     => array(
				'type'        => 'checkbox',
				'label'       => __( 'Shape mode', 'nvoos-content-graph' ),
				'description' => __( 'Encode top-level categories as node shapes (posts as rounded squares, entities as diamonds, …). Alternative to icons.', 'nvoos-content-graph' ),
				'default'     => 0,
			),
			'visual_show_legend'     => array(
				'type'        => 'checkbox',
				'label'       => __( 'Show legend', 'nvoos-content-graph' ),
				'description' => __( 'Auto-generated legend panel with swatches, icons, and node counts. Click a row to filter.', 'nvoos-content-graph' ),
				'default'     => 1,
			),
			'visual_edge_style'      => array(
				'type'        => 'select',
				'label'       => __( 'Edge style', 'nvoos-content-graph' ),
				'description' => __( 'Plain lines, arrowheads, tapered by strength, or the fast haystack mode for dense graphs. Auto switches to density above 500 edges.', 'nvoos-content-graph' ),
				'options'     => array(
					'plain'   => __( 'Plain', 'nvoos-content-graph' ),
					'arrows'  => __( 'Arrows', 'nvoos-content-graph' ),
					'tapered' => __( 'Tapered', 'nvoos-content-graph' ),
					'density' => __( 'Density (fast)', 'nvoos-content-graph' ),
					'auto'    => __( 'Auto', 'nvoos-content-graph' ),
				),
				'default'     => 'plain',
			),
			'visual_edge_labels'     => array(
				'type'    => 'select',
				'label'   => __( 'Edge labels', 'nvoos-content-graph' ),
				'options' => array(
					'off'    => __( 'Off', 'nvoos-content-graph' ),
					'hover'  => __( 'On hover / selection', 'nvoos-content-graph' ),
					'always' => __( 'Always', 'nvoos-content-graph' ),
				),
				'default' => 'hover',
			),
			'visual_size_min'        => array(
				'type'    => 'number',
				'label'   => __( 'Node size — minimum (px)', 'nvoos-content-graph' ),
				'min'     => 8,
				'max'     => 40,
				'default' => 12,
			),
			'visual_size_max'        => array(
				'type'    => 'number',
				'label'   => __( 'Node size — maximum (px)', 'nvoos-content-graph' ),
				'min'     => 40,
				'max'     => 120,
				'default' => 60,
			),
			'visual_label_font_size' => array(
				'type'    => 'number',
				'label'   => __( 'Label font size (px)', 'nvoos-content-graph' ),
				'min'     => 9,
				'max'     => 16,
				'default' => 10,
			),
			'visual_min_label_zoom'  => array(
				'type'        => 'decimal',
				'label'       => __( 'Label zoom threshold', 'nvoos-content-graph' ),
				'description' => __( 'Hide labels below this zoom level (0–1). Lower values show labels sooner; 0 always shows them.', 'nvoos-content-graph' ),
				'min'         => 0,
				'max'         => 1,
				'default'     => 0.35,
			),
			'visual_anim_enabled'    => array(
				'type'        => 'checkbox',
				'label'       => __( 'Animate layouts', 'nvoos-content-graph' ),
				'description' => __( 'Animate node positions during layout. Always disabled when the OS requests reduced motion.', 'nvoos-content-graph' ),
				'default'     => 1,
			),
		);
	}

	/**
	 * Render the section wrapper (standard form-table), then the
	 * per-type color/icon grids and the WCAG contrast report after it.
	 *
	 * @param string $page_slug The settings page slug (unused).
	 * @return void
	 */
	public function render_wrapper( string $page_slug = '' ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- public API, used by SettingsRegistry
		parent::render_wrapper( $page_slug );
		$this->render_grids();
	}

	/**
	 * Render the per-type color/icon grids and the contrast report.
	 *
	 * @return void
	 */
	private function render_grids(): void {
		$settings = Settings::all();
		$palette  = Tokens::type_palette();
		$icons    = Tokens::type_icon_map();
		$catalog  = Tokens::icon_catalog();

		$overrides = isset( $settings['visual_type_colors'] ) && is_array( $settings['visual_type_colors'] )
			? $settings['visual_type_colors']
			: array();
		$iconOver  = isset( $settings['visual_type_icons'] ) && is_array( $settings['visual_type_icons'] )
			? $settings['visual_type_icons']
			: array();

		$types = array_values( array_unique( array_merge( array_keys( $palette ), array_keys( $overrides ) ) ) );
		sort( $types );

		$optionName = Schema::OPTION_SETTINGS;
		?>
		<h3><?php esc_html_e( 'Type colors & icons', 'nvoos-content-graph' ); ?></h3>
		<p class="description">
			<?php esc_html_e( 'Override the color or icon for any node type. Leave a color empty to use the curated default. Colors are automatically lightness-corrected per theme to keep WCAG 2.2 contrast (≥ 3:1). Unknown types (custom post types, remote sources) get a stable algorithmic color until overridden here.', 'nvoos-content-graph' ); ?>
		</p>
		<table class="widefat striped nvoos-cg-type-grid" style="max-width:760px">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Type', 'nvoos-content-graph' ); ?></th>
					<th><?php esc_html_e( 'Color', 'nvoos-content-graph' ); ?></th>
					<th><?php esc_html_e( 'Icon', 'nvoos-content-graph' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $types as $type ) : ?>
				<?php
				$colorVal    = isset( $overrides[ $type ] ) && sanitize_hex_color( $overrides[ $type ] ) ? sanitize_hex_color( $overrides[ $type ] ) : '';
				$defaultCol  = $palette[ $type ] ?? '#95a5a6';
				$iconVal     = isset( $iconOver[ $type ] ) && is_string( $iconOver[ $type ] ) ? sanitize_key( $iconOver[ $type ] ) : '';
				$defaultIcon = $icons[ $type ] ?? 'dot';
				$colorName   = $optionName . '[visual_type_colors][' . $type . ']';
				$iconName    = $optionName . '[visual_type_icons][' . $type . ']';
				?>
				<tr>
					<td><code><?php echo esc_html( $type ); ?></code></td>
					<td>
						<input type="text"
							class="nvoos-cg-color-field"
							name="<?php echo esc_attr( $colorName ); ?>"
							value="<?php echo esc_attr( $colorVal ); ?>"
							placeholder="<?php echo esc_attr( $defaultCol ); ?>"
							data-default-color="<?php echo esc_attr( $defaultCol ); ?>">
					</td>
					<td>
						<select name="<?php echo esc_attr( $iconName ); ?>">
							<option value=""><?php echo esc_html( $defaultIcon ); ?> — <?php esc_html_e( 'default', 'nvoos-content-graph' ); ?></option>
							<?php foreach ( $catalog as $slug => $label ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $iconVal, $slug ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<?php $this->renderContrastReport( $overrides ); ?>
		<?php
	}

	/**
	 * Render the WCAG contrast report for the resolved type colors.
	 *
	 * @param array<string,mixed> $overrides Raw type => color overrides.
	 * @return void
	 */
	private function renderContrastReport( array $overrides ): void {
		$report = Tokens::contrast_report( $overrides );
		?>
		<h3><?php esc_html_e( 'Contrast report', 'nvoos-content-graph' ); ?></h3>
		<p class="description">
			<?php esc_html_e( 'WCAG 2.2 SC 1.4.11 requires ≥ 3:1 contrast for graphical elements against their background. Rows below the threshold are automatically corrected at render time; the suggested values show what each theme will actually display.', 'nvoos-content-graph' ); ?>
		</p>
		<table class="widefat striped nvoos-cg-contrast-report" style="max-width:760px">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Type', 'nvoos-content-graph' ); ?></th>
					<th><?php esc_html_e( 'Color', 'nvoos-content-graph' ); ?></th>
					<th><?php esc_html_e( 'Dark ratio', 'nvoos-content-graph' ); ?></th>
					<th><?php esc_html_e( 'Dark render', 'nvoos-content-graph' ); ?></th>
					<th><?php esc_html_e( 'Light ratio', 'nvoos-content-graph' ); ?></th>
					<th><?php esc_html_e( 'Light render', 'nvoos-content-graph' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $report as $row ) : ?>
				<tr>
					<td><code><?php echo esc_html( $row['type'] ); ?></code></td>
					<td>
						<span class="nvoos-cg-swatch" style="background:<?php echo esc_attr( $row['color'] ); ?>"></span>
						<code><?php echo esc_html( $row['color'] ); ?></code>
					</td>
					<td class="<?php echo esc_attr( $row['ok_dark'] ? 'nvoos-cg-ok' : 'nvoos-cg-bad' ); ?>">
						<?php echo esc_html( (string) $row['ratio_dark'] ); ?>
					</td>
					<td>
						<span class="nvoos-cg-swatch" style="background:<?php echo esc_attr( $row['fix_dark'] ); ?>"></span>
						<code><?php echo esc_html( $row['fix_dark'] ); ?></code>
					</td>
					<td class="<?php echo esc_attr( $row['ok_light'] ? 'nvoos-cg-ok' : 'nvoos-cg-bad' ); ?>">
						<?php echo esc_html( (string) $row['ratio_light'] ); ?>
					</td>
					<td>
						<span class="nvoos-cg-swatch" style="background:<?php echo esc_attr( $row['fix_light'] ); ?>"></span>
						<code><?php echo esc_html( $row['fix_light'] ); ?></code>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Sanitize the scalar fields via the base implementation, then the
	 * per-type color and icon grids.
	 *
	 * @param array<string,mixed> $input Raw submitted values keyed by setting key.
	 * @return array<string,mixed>
	 */
	public function sanitize( array $input ): array {
		$out = parent::sanitize( $input );

		$out['visual_type_colors'] = array();
		if ( isset( $input['visual_type_colors'] ) && is_array( $input['visual_type_colors'] ) ) {
			$out['visual_type_colors'] = Tokens::sanitize_type_colors( $input['visual_type_colors'] );
		}

		$out['visual_type_icons'] = array();
		if ( isset( $input['visual_type_icons'] ) && is_array( $input['visual_type_icons'] ) ) {
			$out['visual_type_icons'] = Tokens::sanitize_type_icons( $input['visual_type_icons'] );
		}

		return $out;
	}
}
