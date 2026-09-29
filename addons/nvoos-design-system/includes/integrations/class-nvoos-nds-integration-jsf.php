<?php
/**
 * NV oOS Design System — JetSmartFilters Integration
 *
 * Injects .nds-filter-bar CSS classes into filter containers and outputs
 * token-driven filter styles when JetSmartFilters is active.
 *
 * @package NV_oOS_Design_System
 * @since   0.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * JetSmartFilters integration layer.
 *
 * Hooks:
 *   - jet-smart-filters/filter/container-classes  → inject .nds-filter-bar
 *   - jet-smart-filters/filters/localized-data     → pass NDS token values to JS
 *
 * When legacy aliases are enabled, the previous `.cds-*` classes are also
 * injected so existing custom CSS keeps matching (transition release).
 *
 * @since 0.2.0
 */
class NV_oOS_Design_System_Integration_JSF {

	/**
	 * Whether hooks have been registered.
	 *
	 * @var bool
	 */
	private static $registered = false;

	/**
	 * Register hooks if JetSmartFilters is active.
	 *
	 * @return void
	 */
	public static function init() {
		if ( self::$registered ) {
			return;
		}

		if ( ! class_exists( 'Jet_Smart_Filters' ) ) {
			return;
		}

		self::$registered = true;

		add_filter(
			'jet-smart-filters/filter/container-classes',
			array( __CLASS__, 'add_filter_bar_class' ),
			10,
			2
		);

		add_filter(
			'jet-smart-filters/filters/localized-data',
			array( __CLASS__, 'inject_nds_tokens_into_js' ),
			10,
			2
		);

		// Ensure NDS component CSS is always enqueued when JSF is present.
		add_action( 'wp_enqueue_scripts', array( 'NV_oOS_Design_System_Assets', 'enqueue_components' ), 25 );
	}

	/**
	 * Inject .nds-filter-bar into the filter container's CSS class list.
	 *
	 * @param array  $classes Existing CSS classes.
	 * @param object $filter  JetSmartFilters filter instance.
	 * @return array Modified CSS classes.
	 */
	public static function add_filter_bar_class( $classes, $filter ) {
		if ( ! is_array( $classes ) ) {
			$classes = array();
		}

		if ( ! in_array( 'nds-filter-bar', $classes, true ) ) {
			$classes[] = 'nds-filter-bar';
		}

		// Legacy alias class (transition release).
		if ( NV_oOS_Design_System_Plugin::is_legacy_aliases_enabled() && ! in_array( 'cds-filter-bar', $classes, true ) ) {
			$classes[] = 'cds-filter-bar';
		}

		// Tag specific filter types with their variant class.
		$filter_type = method_exists( $filter, 'get_type' ) ? $filter->get_type() : '';

		switch ( $filter_type ) {
			case 'color-image':
				$classes[] = 'nds-filter-type-pills';
				break;
			case 'search':
				$classes[] = 'nds-filter-type-search';
				break;
			case 'sorting':
				$classes[] = 'nds-filter-type-sort';
				break;
			case 'range':
				$classes[] = 'nds-filter-type-range';
				break;
			case 'checkboxes':
				$classes[] = 'nds-filter-type-checkboxes';
				break;
		}

		return $classes;
	}

	/**
	 * Inject NDS token values into the JSF front-end JS config.
	 *
	 * This allows JSF's client-side filtering to respect NDS token values
	 * for animations, delays, and visual states.
	 *
	 * @param array  $data   Existing localised data.
	 * @param object $filter JetSmartFilters filter instance.
	 * @return array Modified localised data.
	 */
	public static function inject_nds_tokens_into_js( $data, $filter ) {
		if ( ! is_array( $data ) ) {
			$data = array();
		}

		$registry = NV_oOS_Design_System_Plugin::token_registry();
		$values   = $registry->get_values_map();

		// Pass only the tokens relevant to JSF behaviour.
		$data['nds_tokens'] = array(
			'transition_fast'   => isset( $values['transition_fast'] ) ? $values['transition_fast'] : '150ms ease',
			'transition_normal' => isset( $values['transition_normal'] ) ? $values['transition_normal'] : '300ms ease',
			'easing_standard'   => isset( $values['easing_standard'] ) ? $values['easing_standard'] : '',
			'duration_short'    => isset( $values['duration_short'] ) ? $values['duration_short'] : '200ms',
			'gap_filter'        => isset( $values['gap_filter'] ) ? $values['gap_filter'] : '30px',
		);

		return $data;
	}
}
