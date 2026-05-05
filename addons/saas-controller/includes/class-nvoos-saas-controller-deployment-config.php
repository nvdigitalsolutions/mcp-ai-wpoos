<?php
/**
 * Deployment-config store for the NV oOS SaaS Controller.
 *
 * Stores the operator's *desired* Cloudflare topology — the Worker name,
 * the D1 databases the Worker should bind, the KV namespaces it should
 * bind, the AI Gateway slug to route through, and any custom routes — in
 * a single WordPress option (`nvoos_saas_controller_deployment`). This
 * option is the input to the plan generator; the live Cloudflare account
 * is the other input.
 *
 * Unlike the credential store, this option is *not* encrypted: it
 * contains no secrets, only resource names.
 *
 * @package NV_oOS_SaaS_Controller
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deployment-config singleton.
 *
 * @since 0.1.0
 */
class NVOOS_SaaS_Controller_Deployment_Config {

	/**
	 * WP option name.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'nvoos_saas_controller_deployment';

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton.
	 *
	 * @since 0.1.0
	 *
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Default config shape.
	 *
	 * @since 0.1.0
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'worker_name'     => '',
			'account_id'      => '',
			'd1_databases'    => array(),
			'kv_namespaces'   => array(),
			'ai_gateway_slug' => '',
			'routes'          => array(),
		);
	}

	/**
	 * Read the current config (defaults merged in for missing keys).
	 *
	 * @since 0.1.0
	 *
	 * @return array
	 */
	public function get() {
		$stored = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return wp_parse_args( $stored, self::defaults() );
	}

	/**
	 * Replace the config with the supplied (sanitised) value.
	 *
	 * @since 0.1.0
	 *
	 * @param array $config Desired config.
	 * @return array Sanitised config that was persisted.
	 */
	public function set( array $config ) {
		$clean = self::sanitize( $config );
		update_option( self::OPTION_NAME, $clean, false );
		return $clean;
	}

	/**
	 * Clear the config back to defaults.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function clear() {
		delete_option( self::OPTION_NAME );
	}

	/**
	 * Strict per-field sanitiser.
	 *
	 * Bindings (used in the Workers runtime) must match `[A-Z][A-Z0-9_]*`,
	 * Worker names match Cloudflare's slug rules (`[a-z0-9][a-z0-9_-]{0,62}`),
	 * and resource names are sanitised with `sanitize_text_field`.
	 *
	 * @since 0.1.0
	 *
	 * @param array $config Raw config.
	 * @return array Sanitised config.
	 */
	public static function sanitize( array $config ) {
		$out = self::defaults();

		if ( ! empty( $config['worker_name'] ) ) {
			$worker = strtolower( sanitize_text_field( (string) $config['worker_name'] ) );
			if ( preg_match( '/^[a-z0-9][a-z0-9_-]{0,62}$/', $worker ) ) {
				$out['worker_name'] = $worker;
			}
		}

		if ( ! empty( $config['account_id'] ) ) {
			$account_id = sanitize_text_field( (string) $config['account_id'] );
			if ( preg_match( '/^[a-f0-9]{16,64}$/i', $account_id ) ) {
				$out['account_id'] = strtolower( $account_id );
			}
		}

		if ( ! empty( $config['ai_gateway_slug'] ) ) {
			$slug = strtolower( sanitize_text_field( (string) $config['ai_gateway_slug'] ) );
			if ( preg_match( '/^[a-z0-9][a-z0-9_-]{0,62}$/', $slug ) ) {
				$out['ai_gateway_slug'] = $slug;
			}
		}

		if ( ! empty( $config['d1_databases'] ) && is_array( $config['d1_databases'] ) ) {
			foreach ( $config['d1_databases'] as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$name    = isset( $row['name'] ) ? sanitize_text_field( (string) $row['name'] ) : '';
				$binding = isset( $row['binding'] ) ? sanitize_text_field( (string) $row['binding'] ) : '';
				if ( '' === $name || '' === $binding ) {
					continue;
				}
				if ( ! preg_match( '/^[A-Z][A-Z0-9_]{0,63}$/', $binding ) ) {
					continue;
				}
				$out['d1_databases'][] = array(
					'name'    => $name,
					'binding' => $binding,
				);
			}
		}

		if ( ! empty( $config['kv_namespaces'] ) && is_array( $config['kv_namespaces'] ) ) {
			foreach ( $config['kv_namespaces'] as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$title   = isset( $row['title'] ) ? sanitize_text_field( (string) $row['title'] ) : '';
				$binding = isset( $row['binding'] ) ? sanitize_text_field( (string) $row['binding'] ) : '';
				if ( '' === $title || '' === $binding ) {
					continue;
				}
				if ( ! preg_match( '/^[A-Z][A-Z0-9_]{0,63}$/', $binding ) ) {
					continue;
				}
				$out['kv_namespaces'][] = array(
					'title'   => $title,
					'binding' => $binding,
				);
			}
		}

		if ( ! empty( $config['routes'] ) && is_array( $config['routes'] ) ) {
			foreach ( $config['routes'] as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$pattern   = isset( $row['pattern'] ) ? sanitize_text_field( (string) $row['pattern'] ) : '';
				$zone_name = isset( $row['zone_name'] ) ? sanitize_text_field( (string) $row['zone_name'] ) : '';
				if ( '' === $pattern || '' === $zone_name ) {
					continue;
				}
				$out['routes'][] = array(
					'pattern'   => $pattern,
					'zone_name' => $zone_name,
				);
			}
		}

		return $out;
	}
}
