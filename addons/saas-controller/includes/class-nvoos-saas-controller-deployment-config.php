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
			// Phase 6: desired Stripe + OpenRouter topology. Each section is
			// optional and skipped at plan time when its credentials aren't
			// configured (no SSRF / spurious-failure surface for operators
			// who only use the Cloudflare side of the addon).
			'stripe_products' => array(),
			'stripe_prices'   => array(),
			'openrouter_keys' => array(),
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

		// Phase 6 — Stripe products. Each row matches Stripe's product
		// model loosely: `id` is operator-supplied so create-or-update is
		// idempotent across replays. `name` and `description` are free-form
		// text. We reject ids that don't match Stripe's (loose) slug rules
		// so a typo can't smuggle a colon-prefixed expandable parameter
		// like `price:auto` into the upstream call.
		if ( ! empty( $config['stripe_products'] ) && is_array( $config['stripe_products'] ) ) {
			foreach ( $config['stripe_products'] as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$id   = isset( $row['id'] ) ? sanitize_text_field( (string) $row['id'] ) : '';
				$name = isset( $row['name'] ) ? sanitize_text_field( (string) $row['name'] ) : '';
				if ( '' === $id || '' === $name ) {
					continue;
				}
				if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9_\-]{0,99}$/', $id ) ) {
					continue;
				}
				$entry = array(
					'id'   => $id,
					'name' => $name,
				);
				if ( ! empty( $row['description'] ) ) {
					$entry['description'] = sanitize_text_field( (string) $row['description'] );
				}
				$out['stripe_products'][] = $entry;
			}
		}

		// Phase 6 — Stripe prices. Each row references a `product_id` from
		// the products array (or any product already provisioned in
		// Stripe), with explicit `currency`, `unit_amount` (in the
		// currency's smallest unit), and either `recurring_interval` for
		// subscriptions or unset for one-shot prices. A `lookup_key` is
		// required so we can match desired-vs-live without depending on
		// the provider-assigned price id.
		if ( ! empty( $config['stripe_prices'] ) && is_array( $config['stripe_prices'] ) ) {
			foreach ( $config['stripe_prices'] as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$lookup_key = isset( $row['lookup_key'] ) ? sanitize_text_field( (string) $row['lookup_key'] ) : '';
				$product_id = isset( $row['product_id'] ) ? sanitize_text_field( (string) $row['product_id'] ) : '';
				$currency   = isset( $row['currency'] ) ? strtolower( sanitize_text_field( (string) $row['currency'] ) ) : '';
				$amount     = isset( $row['unit_amount'] ) ? (int) $row['unit_amount'] : 0;
				if ( '' === $lookup_key || '' === $product_id || '' === $currency || $amount <= 0 ) {
					continue;
				}
				if ( ! preg_match( '/^[a-z0-9][a-z0-9_\-]{0,99}$/', $lookup_key ) ) {
					continue;
				}
				if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9_\-]{0,99}$/', $product_id ) ) {
					continue;
				}
				if ( ! preg_match( '/^[a-z]{3}$/', $currency ) ) {
					continue;
				}
				$entry = array(
					'lookup_key'  => $lookup_key,
					'product_id'  => $product_id,
					'currency'    => $currency,
					'unit_amount' => $amount,
				);
				if ( ! empty( $row['recurring_interval'] ) ) {
					$interval = sanitize_text_field( (string) $row['recurring_interval'] );
					if ( in_array( $interval, array( 'day', 'week', 'month', 'year' ), true ) ) {
						$entry['recurring_interval'] = $interval;
					}
				}
				if ( ! empty( $row['nickname'] ) ) {
					$entry['nickname'] = sanitize_text_field( (string) $row['nickname'] );
				}
				$out['stripe_prices'][] = $entry;
			}
		}

		// Phase 6 — OpenRouter runtime keys. `label` is the matching key
		// used by the plan generator to decide create-vs-noop; the
		// optional `limit_usd` is forwarded to OpenRouter as the per-key
		// dollar budget cap. We don't store the actual key value (it is
		// returned only at create time and surfaces in the apply result
		// row exactly once for the operator to copy out).
		if ( ! empty( $config['openrouter_keys'] ) && is_array( $config['openrouter_keys'] ) ) {
			foreach ( $config['openrouter_keys'] as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$label = isset( $row['label'] ) ? sanitize_text_field( (string) $row['label'] ) : '';
				if ( '' === $label ) {
					continue;
				}
				if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9_\- ]{0,99}$/', $label ) ) {
					continue;
				}
				$entry = array( 'label' => $label );
				if ( isset( $row['limit_usd'] ) ) {
					$limit = (float) $row['limit_usd'];
					if ( $limit > 0 ) {
						$entry['limit_usd'] = $limit;
					}
				}
				$out['openrouter_keys'][] = $entry;
			}
		}

		return $out;
	}
}
