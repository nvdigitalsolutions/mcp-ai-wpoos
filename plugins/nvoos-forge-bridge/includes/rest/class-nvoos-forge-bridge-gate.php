<?php
/**
 * REST — the front-end gate state (Proposal 152 P4).
 *
 * A pre-launch WordPress site gates its RENDERED HTML (503 + Retry-After, or a
 * 200 coming-soon page) while `/wp-json/` stays open. The forge can infer that
 * from the HTML, but inference has two blind spots: a fingerprint-free gate,
 * and an edge cache that serves an ANONYMOUS copy to an authenticated request.
 * This route answers the question directly — "is the front end gated, and by
 * what?" — from the options the gate plugins already keep.
 *
 * Read-only and public by design, like `/health`: booleans, a mode keyword and
 * a plugin slug only. Never a URL, a template id, a role list, or a secret. The
 * maintenance state is already public (the gate answers 503 or a holding page
 * to every visitor), so this adds no new disclosure.
 *
 * Supported out of the box (each verified against the plugin source):
 *   - WordPress core   — the `.maintenance` file (10-minute window)
 *   - Elementor        — Tools → Maintenance Mode (mode + template id required)
 *   - WP Maintenance Mode (Designmodo) — Settings → General → Status
 * Any other gate declares itself through the `nvoos_forge_bridge_gate_state`
 * filter; the forge never guesses a third-party option name.
 *
 * @package NvoosForgeBridge
 */

namespace NvoosForgeBridge\Rest;

/**
 * Front-end gate state route.
 */
class Gate extends \WP_REST_Controller {

	/**
	 * Namespace.
	 */
	private const NS = 'nvoos-forge/v1';

	/**
	 * Modes a gate can report.
	 *
	 * @var string[]
	 */
	private const MODES = array( 'off', 'maintenance', 'coming_soon' );

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NS,
			'/gate',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_gate' ),
				// Public by design — the same class of disclosure as /health, and
				// the state is already observable from the front end itself.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * The current front-end gate state.
	 *
	 * @return \WP_REST_Response
	 */
	public function handle_gate(): \WP_REST_Response {
		$state = self::detect();

		return new \WP_REST_Response(
			array(
				'maintenance' => (bool) $state['maintenance'],
				'mode'        => $state['mode'],
				'plugin'      => $state['plugin'],
				'checkedAt'   => gmdate( 'c' ),
			)
		);
	}

	/**
	 * Detect the front-end gate state.
	 *
	 * Public + static so the suite can exercise it without the REST layer.
	 * Every plugin check requires the plugin to be ACTIVE (its class loaded):
	 * a deactivated plugin leaves its options behind, but it no longer gates
	 * the front end.
	 *
	 * @return array{maintenance: bool, mode: string, plugin: string|null}
	 */
	public static function detect(): array {
		$state = array(
			'maintenance' => false,
			'mode'        => 'off',
			'plugin'      => null,
		);

		// WordPress core: `.maintenance` in ABSPATH (expires after 10 minutes).
		if ( function_exists( 'wp_is_maintenance_mode' ) && wp_is_maintenance_mode() ) {
			$state = array(
				'maintenance' => true,
				'mode'        => 'maintenance',
				'plugin'      => 'core',
			);
		}

		// Elementor: enabled only when BOTH a mode and a template are set
		// (Elementor\Maintenance_Mode::__construct). `maintenance` answers 503 +
		// Retry-After: 600; `coming_soon` answers 200.
		if ( ! $state['maintenance'] && class_exists( '\Elementor\Plugin' ) ) {
			$mode     = get_option( 'elementor_maintenance_mode_mode' );
			$template = (int) get_option( 'elementor_maintenance_mode_template_id' );
			if ( $template > 0 && in_array( $mode, array( 'maintenance', 'coming_soon' ), true ) ) {
				$state = array(
					'maintenance' => true,
					'mode'        => $mode,
					'plugin'      => 'elementor',
				);
			}
		}

		// WP Maintenance Mode (Designmodo): Settings → General → Status.
		if ( ! $state['maintenance'] && class_exists( '\WP_Maintenance_Mode' ) ) {
			$settings = get_option( 'wpmm_settings' );
			if ( is_array( $settings ) && ! empty( $settings['general']['status'] ) ) {
				$state = array(
					'maintenance' => true,
					'mode'        => 'maintenance',
					'plugin'      => 'wp-maintenance-mode',
				);
			}
		}

		/**
		 * Filters the detected front-end gate state.
		 *
		 * Every other gate plugin declares itself here rather than making the
		 * bridge guess an option name:
		 *
		 *     add_filter( 'nvoos_forge_bridge_gate_state', function ( $state ) {
		 *         return array(
		 *             'maintenance' => (bool) get_option( 'my_gate_enabled' ),
		 *             'mode'        => 'coming_soon', // off | maintenance | coming_soon
		 *             'plugin'      => 'my-gate',
		 *         );
		 *     } );
		 *
		 * @since 0.3.2
		 *
		 * @param array{maintenance: bool, mode: string, plugin: string|null} $state Detected state.
		 */
		$filtered = apply_filters( 'nvoos_forge_bridge_gate_state', $state );

		return self::normalize( $filtered, $state );
	}

	/**
	 * Normalize a (possibly filtered) state to the wire shape.
	 *
	 * A filter that returns something unusable keeps the detected state rather
	 * than reporting a gate that is not there.
	 *
	 * @param mixed                                                       $candidate Filtered value.
	 * @param array{maintenance: bool, mode: string, plugin: string|null} $fallback  Detected state.
	 * @return array{maintenance: bool, mode: string, plugin: string|null}
	 */
	private static function normalize( $candidate, array $fallback ): array {
		if ( ! is_array( $candidate ) ) {
			return $fallback;
		}

		$maintenance = ! empty( $candidate['maintenance'] );
		$mode        = isset( $candidate['mode'] ) ? sanitize_key( (string) $candidate['mode'] ) : '';
		if ( ! in_array( $mode, self::MODES, true ) ) {
			$mode = $maintenance ? 'maintenance' : 'off';
		}
		if ( ! $maintenance ) {
			$mode = 'off';
		}

		$plugin = isset( $candidate['plugin'] ) && is_string( $candidate['plugin'] ) && '' !== $candidate['plugin']
			? sanitize_key( $candidate['plugin'] )
			: null;

		return array(
			'maintenance' => $maintenance,
			'mode'        => $mode,
			'plugin'      => $plugin,
		);
	}
}
