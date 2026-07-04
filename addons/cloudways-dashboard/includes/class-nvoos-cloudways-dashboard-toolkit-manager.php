<?php
/**
 * NV oOS Cloudways Dashboard — Toolkit Manager
 *
 * Manages toolkit application/removal on Cloudways-hosted WordPress sites.
 * Stores per-site toolkit state and handles assistant pre-configuration.
 *
 * @package NV_oOS_CloudwaysDashboard
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Toolkit Manager.
 *
 * @since 0.1.0
 */
class NV_oOS_CloudwaysDashboard_Toolkit_Manager {

	/**
	 * Get the option key for a site's toolkit state.
	 *
	 * @param int $app_id Cloudways app ID.
	 * @return string
	 */
	public static function state_key( $app_id ) {
		return "nvoos_cw_site_toolkits_{$app_id}";
	}

	/**
	 * Get all toolkits applied to a site.
	 *
	 * @param int $app_id Cloudways app ID.
	 * @return array Array of toolkit state entries keyed by slug.
	 */
	public static function get_site_toolkits( $app_id ) {
		return get_option( self::state_key( $app_id ), array() );
	}

	/**
	 * Apply one or more toolkits to a site.
	 *
	 * @param int   $app_id Cloudways app ID.
	 * @param array $toolkit_slugs Toolkit slugs to apply.
	 * @param array $options {
	 *     Optional. Application options.
	 *
	 *     @type array $assistant_defaults Key-value of assistant defaults.
	 * }
	 * @return array Results per toolkit slug.
	 */
	public static function apply_toolkits( $app_id, $toolkit_slugs, $options = array() ) {
		$state   = self::get_site_toolkits( $app_id );
		$results = array();

		foreach ( $toolkit_slugs as $slug ) {
			$slug = sanitize_key( $slug );

			if ( isset( $state[ $slug ] ) && 'active' === $state[ $slug ]['status'] ) {
				$results[ $slug ] = array(
					'status'  => 'already_active',
					'message' => sprintf( 'Toolkit "%s" is already active.', $slug ),
				);
				continue;
			}

			$state[ $slug ] = array(
				'slug'       => $slug,
				'status'     => 'active',
				'applied_at' => time(),
				'updated_at' => time(),
				'assistants' => isset( $options['assistant_defaults'] )
					? self::create_assistants_for_toolkit( $app_id, $slug, $options['assistant_defaults'] )
					: array(),
			);

			$results[ $slug ] = array(
				'status'  => 'applied',
				'message' => sprintf( 'Toolkit "%s" applied successfully.', $slug ),
			);

			/**
			 * Fires when a toolkit is applied to a site.
			 *
			 * @param int    $app_id Cloudways app ID.
			 * @param string $slug   Toolkit slug.
			 * @since 0.1.0
			 */
			do_action( 'nvoos_cloudways_dashboard_toolkit_applied', $app_id, $slug );
		}

		update_option( self::state_key( $app_id ), $state );

		return $results;
	}

	/**
	 * Remove one or more toolkits from a site.
	 *
	 * @param int   $app_id       Cloudways app ID.
	 * @param array $toolkit_slugs Toolkit slugs to remove.
	 * @return array Results per toolkit slug.
	 */
	public static function remove_toolkits( $app_id, $toolkit_slugs ) {
		$state   = self::get_site_toolkits( $app_id );
		$results = array();

		foreach ( $toolkit_slugs as $slug ) {
			$slug = sanitize_key( $slug );

			if ( ! isset( $state[ $slug ] ) ) {
				$results[ $slug ] = array(
					'status'  => 'not_found',
					'message' => sprintf( 'Toolkit "%s" was not active on this site.', $slug ),
				);
				continue;
			}

			unset( $state[ $slug ] );

			$results[ $slug ] = array(
				'status'  => 'removed',
				'message' => sprintf( 'Toolkit "%s" removed.', $slug ),
			);

			/**
			 * Fires when a toolkit is removed from a site.
			 *
			 * @param int    $app_id Cloudways app ID.
			 * @param string $slug   Toolkit slug.
			 * @since 0.1.0
			 */
			do_action( 'nvoos_cloudways_dashboard_toolkit_removed', $app_id, $slug );
		}

		update_option( self::state_key( $app_id ), $state );

		return $results;
	}

	/**
	 * Get a summary of all toolkits applied across all sites.
	 *
	 * @return array Array with `sites`, `toolkit_counts`, and `total`.
	 */
	public static function get_global_summary() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$keys = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				'nvoos_cw_site_toolkits_%'
			)
		);

		$sites    = array();
		$counts   = array();
		$total_tk = 0;

		if ( is_array( $keys ) ) {
			foreach ( $keys as $key ) {
				$app_id   = (int) str_replace( 'nvoos_cw_site_toolkits_', '', $key );
				$toolkits = get_option( $key, array() );
				$active   = array_filter(
					$toolkits,
					function ( $t ) {
						return 'active' === $t['status'];
					}
				);

				$sites[ $app_id ] = array(
					'app_id' => $app_id,
					'total'  => count( $active ),
					'active' => array_keys( $active ),
				);

				foreach ( $active as $slug => $data ) {
					$counts[ $slug ] = ( $counts[ $slug ] ?? 0 ) + 1;
					++$total_tk;
				}
			}
		}

		return array(
			'sites'              => $sites,
			'toolkit_counts'     => $counts,
			'total_applications' => $total_tk,
		);
	}

	/**
	 * Create default assistants for a toolkit on a site.
	 *
	 * This is a placeholder — in production, this would call the target
	 * site's API to create assistant CPTs. For v0.1, we record the intent.
	 *
	 * @param int    $app_id  Cloudways app ID.
	 * @param string $tk_slug Toolkit slug.
	 * @param array  $defaults Assistant default configs.
	 * @return array Created assistant IDs (empty for v0.1).
	 */
	private static function create_assistants_for_toolkit( $app_id, $tk_slug, $defaults ) {
		$results = array();

		foreach ( $defaults as $assistant_key => $config ) {
			$results[ $assistant_key ] = array(
				'status'  => 'pending_plugin_install',
				'message' => 'Assistant creation requires the nvOS plugin to be installed on the target site.',
			);
		}

		/**
		 * Filter the assistant defaults before they're applied.
		 *
		 * @param array  $defaults Assistant defaults keyed by assistant key.
		 * @param int    $app_id   Cloudways app ID.
		 * @param string $tk_slug  Toolkit slug.
		 * @since 0.1.0
		 */
		$defaults = apply_filters( 'nvoos_cloudways_dashboard_assistant_defaults', $defaults, $app_id, $tk_slug );

		return $results;
	}
}
