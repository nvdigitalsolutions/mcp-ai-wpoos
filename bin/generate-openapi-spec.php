<?php
/**
 * Generate an OpenAPI 3.0 specification from WordPress registered REST routes.
 *
 * This script introspects `rest_get_server()->get_routes()` and builds a
 * minimal but valid OpenAPI 3.0.3 document. It is designed to run inside
 * the QA Docker stack (WP-CLI container) during the OWASP ZAP API scan
 * workflow.
 *
 * Usage (inside WP-CLI container):
 *   wp eval-file bin/generate-openapi-spec.php --allow-root > openapi.json
 *
 * The generated spec is consumed by `zaproxy/action-api-scan` in the
 * DAST — OWASP ZAP API Scan workflow.
 *
 * @package WP_MCP_AI
 * @since   1.0.0
 */

// ---------------------------------------------------------------------------
// 1. Bootstrap WordPress if running outside WP-CLI's eval-file context.
// ---------------------------------------------------------------------------
if ( ! defined( 'ABSPATH' ) ) {
	// Try to locate wp-load.php from common WP-CLI working directories.
	$candidates = array(
		'/var/www/html/wp-load.php',
		dirname( __DIR__, 4 ) . '/wp-load.php',
		dirname( __DIR__, 3 ) . '/wp-load.php',
	);

	foreach ( $candidates as $candidate ) {
		if ( file_exists( $candidate ) ) {
			require_once $candidate;
			break;
		}
	}

	if ( ! defined( 'ABSPATH' ) ) {
		fwrite( STDERR, "Error: Could not locate WordPress. Run this script via `wp eval-file`.\n" );
		exit( 1 );
	}
}

// ---------------------------------------------------------------------------
// 2. Ensure the REST API server is initialised.
// ---------------------------------------------------------------------------
if ( ! function_exists( 'rest_get_server' ) ) {
	fwrite( STDERR, "Error: REST API not available.\n" );
	exit( 1 );
}

$server = rest_get_server();
$routes = $server->get_routes();

// ---------------------------------------------------------------------------
// 3. Plugin namespaces to include in the spec.
//    Only routes under these namespaces are generated — WordPress core
//    routes are excluded to keep the spec focused on the plugin surface.
// ---------------------------------------------------------------------------
$plugin_namespaces = array(
	'mcp-ai',
	'nvoos',
	'nv-oos',
	'nvoos-chat-spa',
	'nvoos-canvas',
	'nvoos-comic-reader',
	'nvoos-cloudways',
	'nvoos-algorave',
);

$paths = array();

foreach ( $routes as $route => $handlers ) {
	$route_clean = ltrim( $route, '/' );

	// Skip WordPress core routes — only include plugin namespaces.
	$is_plugin_route = false;
	foreach ( $plugin_namespaces as $ns ) {
		if ( strpos( $route_clean, $ns . '/' ) === 0 || $route_clean === $ns ) {
			$is_plugin_route = true;
			break;
		}
	}

	if ( ! $is_plugin_route ) {
		continue;
	}

	$path_item = array();

	foreach ( $handlers as $handler ) {
		if ( empty( $handler['methods'] ) ) {
			continue;
		}

		$methods = is_array( $handler['methods'] )
			? $handler['methods']
			: array( $handler['methods'] );

		foreach ( $methods as $method ) {
			$method_upper = strtoupper( $method );

			// Skip OPTIONS (CORS preflight) and HEAD — not useful for security scanning.
			if ( 'OPTIONS' === $method_upper || 'HEAD' === $method_upper ) {
				continue;
			}

			// Only standard HTTP methods are valid in OpenAPI.
			$valid_methods = array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' );
			if ( ! in_array( $method_upper, $valid_methods, true ) ) {
				continue;
			}

			// Derive a stable operationId from route + method.
			$operation_id = sanitize_title( $route_clean . '-' . strtolower( $method_upper ) );
			// operationId must be unique.
			if ( isset( $paths[ '/' . $route_clean ][ strtolower( $method_upper ) ] ) ) {
				$operation_id .= '-2';
			}

			$operation = array(
				'operationId' => $operation_id,
				'summary'     => isset( $handler['callback'] )
					? ( is_array( $handler['callback'] ) ? implode( '::', $handler['callback'] ) : $handler['callback'] )
					: '',
				'responses'   => array(
					'200' => array( 'description' => 'Successful response' ),
					'400' => array( 'description' => 'Bad request' ),
					'401' => array( 'description' => 'Unauthorized' ),
					'403' => array( 'description' => 'Forbidden' ),
				),
			);

			// -------------------------------------------------------------------
			// 4. Map WordPress `args` schema to OpenAPI parameters.
			//    Security note: we intentionally include args here so ZAP can
			//    fuzz them. The actual permission_callback will still reject
			//    unauthorised requests at runtime.
			// -------------------------------------------------------------------
			if ( ! empty( $handler['args'] ) && is_array( $handler['args'] ) ) {
				$operation['parameters'] = array();

				foreach ( $handler['args'] as $arg_name => $arg_def ) {
					if ( ! is_array( $arg_def ) ) {
						continue;
					}

					$param = array(
						'name'        => $arg_name,
						'in'          => 'GET' === $method_upper ? 'query' : 'body',
						'required'    => ! empty( $arg_def['required'] ),
						'description' => isset( $arg_def['description'] )
							? wp_strip_all_tags( $arg_def['description'] )
							: '',
						'schema'      => array(
							'type' => isset( $arg_def['type'] )
								? $arg_def['type']
								: 'string',
						),
					);

					// Add enum values if present.
					if ( ! empty( $arg_def['enum'] ) && is_array( $arg_def['enum'] ) ) {
						$param['schema']['enum'] = $arg_def['enum'];
					}

					// Add default value if present.
					if ( isset( $arg_def['default'] ) ) {
						$param['schema']['default'] = $arg_def['default'];
					}

					$operation['parameters'][] = $param;
				}
			}

			// -------------------------------------------------------------------
			// 5. If the endpoint requires authentication, document it as a
			//    security requirement so ZAP knows it needs a token.
			// -------------------------------------------------------------------
			$has_permission_check = ! empty( $handler['permission_callback'] )
				&& '__return_true' !== $handler['permission_callback'];

			if ( $has_permission_check ) {
				$operation['security'] = array(
					array( 'bearerAuth' => array() ),
					array( 'wpNonce' => array() ),
				);
			}

			$path_item[ strtolower( $method_upper ) ] = $operation;
		}
	}

	if ( ! empty( $path_item ) ) {
		$paths[ '/' . $route_clean ] = $path_item;
	}
}

// ---------------------------------------------------------------------------
// 6. Build the full OpenAPI 3.0.3 document.
// ---------------------------------------------------------------------------
$spec = array(
	'openapi' => '3.0.3',
	'info'    => array(
		'title'       => 'NV oOS Plugin REST API',
		'version'     => defined( 'WP_MCP_AI_VERSION' ) ? WP_MCP_AI_VERSION : '1.0.0',
		'description' => sprintf(
			'Auto-generated OpenAPI specification from %d registered REST routes for the NV oOS WordPress plugin. Generated by bin/generate-openapi-spec.php for use with OWASP ZAP API security scanning.',
			count( $paths )
		),
	),
	'servers' => array(
		array(
			'url'         => 'http://localhost:8000',
			'description' => 'QA Docker environment',
		),
	),
	'paths'   => $paths,
	'components' => array(
		'securitySchemes' => array(
			'bearerAuth' => array(
				'type'        => 'http',
				'scheme'      => 'bearer',
				'description' => 'WordPress Application Password or Assistant Credential token',
			),
			'wpNonce' => array(
				'type'        => 'apiKey',
				'in'          => 'header',
				'name'        => 'X-WP-Nonce',
				'description' => 'WordPress REST API nonce',
			),
		),
	),
);

// ---------------------------------------------------------------------------
// 7. Output the spec as pretty-printed JSON to stdout.
// ---------------------------------------------------------------------------
// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
$json = json_encode(
	$spec,
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);

if ( false === $json ) {
	fwrite( STDERR, 'Error: Failed to encode OpenAPI spec as JSON: ' . json_last_error_msg() . "\n" );
	exit( 1 );
}

echo $json;
