<?php
/**
 * NV oOS Crocoblock DS — JetSmartFilters Integration
 *
 * Injects .cds-filter-bar CSS classes into filter containers and outputs
 * token-driven filter styles when JetSmartFilters is active.
 *
 * @package NV_oOS_Crocoblock_DS
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * JetSmartFilters integration layer.
 *
 * Hooks:
 *   - jet-smart-filters/filter/container-classes  → inject .cds-filter-bar
 *   - jet-smart-filters/filters/localized-data     → pass CDS token values to JS
 *   - jet-smart-filters/query/final-query           → respect CDS grid gap
 *
 * @since 0.1.0
 */
class NV_oOS_Crocoblock_DS_Integration_JSF {

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
			array( __CLASS__, 'inject_cds_tokens_into_js' ),
			10,
			2
		);

		// Ensure CDS component CSS is always enqueued when JSF is present.
		add_action( 'wp_enqueue_scripts', array( 'NV_oOS_Crocoblock_DS_Assets', 'enqueue_components' ), 25 );
	}

	/**
	 * Inject .cds-filter-bar into the filter container's CSS class list.
	 *
	 * @param array  $classes  Existing CSS classes.
	 * @param object $filter   JetSmartFilters filter instance.
	 * @return array Modified CSS classes.
	 */
	public static function add_filter_bar_class( $classes, $filter ) {
		if ( ! is_array( $classes ) ) {
			$classes = array();
		}

		if ( ! in_array( 'cds-filter-bar', $classes, true ) ) {
			$classes[] = 'cds-filter-bar';
		}

		// Tag specific filter types with their variant class.
		$filter_type = method_exists( $filter, 'get_type' ) ? $filter->get_type() : '';

		switch ( $filter_type ) {
			case 'color-image':
				$classes[] = 'cds-filter-type-pills';
				break;
			case 'search':
				$classes[] = 'cds-filter-type-search';
				break;
			case 'sorting':
				$classes[] = 'cds-filter-type-sort';
				break;
			case 'range':
				$classes[] = 'cds-filter-type-range';
				break;
			case 'checkboxes':
				$classes[] = 'cds-filter-type-checkboxes';
				break;
		}

		return $classes;
	}

	/**
	 * Inject CDS token values into the JSF front-end JS config.
	 *
	 * This allows JSF's client-side filtering to respect CDS token values
	 * for animations, delays, and visual states.
	 *
	 * @param array  $data   Existing localised data.
	 * @param object $filter JetSmartFilters filter instance.
	 * @return array Modified localised data.
	 */
	public static function inject_cds_tokens_into_js( $data, $filter ) {
		if ( ! is_array( $data ) ) {
			$data = array();
		}

		$registry = NV_oOS_Crocoblock_DS_Plugin::token_registry();
		$values   = $registry->get_values_map();

		// Pass only the tokens relevant to JSF behaviour.
		$data['cds_tokens'] = array(
			'transition_fast'   => isset( $values['transition_fast'] ) ? $values['transition_fast'] : '150ms ease',
			'transition_normal' => isset( $values['transition_normal'] ) ? $values['transition_normal'] : '300ms ease',
			'easing_standard'   => isset( $values['easing_standard'] ) ? $values['easing_standard'] : '',
			'duration_short'    => isset( $values['duration_short'] ) ? $values['duration_short'] : '200ms',
			'gap_filter'        => isset( $values['gap_filter'] ) ? $values['gap_filter'] : '30px',
		);

		return $data;
	}
}
