<?php
/**
 * Tool registry coverage smoke test.
 *
 * Single data-driven smoke test that locks the contract for every registered
 * tool. Implements PR #2 of the PHPUnit Test Coverage Gap-Filling Plan: by
 * iterating WP_MCP_AI_Tool_Registry::get_instance()->get_tools() it asserts
 * that every tool exposes a sane slug, a parameter schema with no `'mixed'`
 * types and proper `items` declarations on arrays, a resolvable required
 * capability, and an execute() that does not crash when invoked by an
 * unauthenticated subscriber.
 *
 * The companion manifest files at
 *   tests/tools/.coverage-manifest.txt
 *   addons/pro/tests/tools/.coverage-manifest.txt
 * list every tool-class file basename so bin/find-untested-classes.sh
 * recognises this single test as covering the whole tool registry. Regenerate
 * them via bin/generate-tool-coverage-manifest.sh whenever a tool is added,
 * removed or renamed.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Tool registry coverage tests.
 */
class Test_Tool_Registry_Coverage extends WP_UnitTestCase {

	/**
	 * Cached list of registered tools, keyed by slug.
	 *
	 * @var WP_MCP_AI_Tool_Interface[]
	 */
	private static $tools = array();

	/**
	 * Lazy-load the registered tools once for the whole test class.
	 *
	 * @return WP_MCP_AI_Tool_Interface[]
	 */
	private function get_registered_tools() {
		if ( ! empty( self::$tools ) ) {
			return self::$tools;
		}

		if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			$this->markTestSkipped( 'Tool registry class is not available.' );
		}

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tools = $registry->get_tools();

		if ( empty( $tools ) ) {
			$this->markTestSkipped( 'No tools registered — smoke test has nothing to assert.' );
		}

		// Index by slug so per-tool failure messages are stable.
		foreach ( $tools as $tool ) {
			$slug = is_object( $tool ) && method_exists( $tool, 'get_slug' )
				? (string) $tool->get_slug()
				: 'unknown_' . spl_object_id( $tool );
			self::$tools[ $slug ] = $tool;
		}

		return self::$tools;
	}

	/**
	 * Every registered tool exposes a non-empty, kebab/snake-case slug that
	 * survives sanitize_key() unchanged.
	 */
	public function test_every_tool_returns_a_sane_slug() {
		$tools = $this->get_registered_tools();

		foreach ( $tools as $registered_slug => $tool ) {
			$slug = $tool->get_slug();
			$this->assertIsString(
				$slug,
				sprintf( 'Tool %s::get_slug() must return a string.', get_class( $tool ) )
			);
			$this->assertNotEmpty(
				$slug,
				sprintf( 'Tool %s::get_slug() must return a non-empty string.', get_class( $tool ) )
			);
			$this->assertSame(
				sanitize_key( $slug ),
				$slug,
				sprintf( 'Tool %s slug %s does not survive sanitize_key().', get_class( $tool ), $slug )
			);
		}
	}

	/**
	 * Every registered tool's parameter schema is OpenAI-compatible: no
	 * `'mixed'` type and every `type:'array'` declares `items`. Tools may
	 * expose the schema via either `get_parameters_schema()` (interface) or
	 * the optional `get_definition()['parameters']`.
	 */
	public function test_every_tool_parameter_schema_is_openai_compatible() {
		$tools = $this->get_registered_tools();

		foreach ( $tools as $slug => $tool ) {
			$schema = $this->extract_parameter_schema( $tool );

			if ( null === $schema ) {
				// Tool exposes no parameter schema — that is allowed.
				continue;
			}

			$violations = $this->find_schema_violations( $schema, $slug );

			$this->assertEmpty(
				$violations,
				sprintf(
					'Tool %s has invalid parameter schema:%s%s',
					$slug,
					PHP_EOL,
					implode( PHP_EOL, $violations )
				)
			);
		}
	}

	/**
	 * Every tool that declares a required capability returns either a
	 * non-empty string or an array of capability strings, all of which
	 * are recognisable by WP_User::has_cap() (i.e. non-empty strings).
	 */
	public function test_every_tool_required_capability_resolves() {
		$tools = $this->get_registered_tools();

		foreach ( $tools as $slug => $tool ) {
			$capability = $this->extract_required_capability( $tool );

			if ( null === $capability ) {
				// Not all tools advertise a capability.
				continue;
			}

			if ( is_array( $capability ) ) {
				$this->assertNotEmpty(
					$capability,
					sprintf( 'Tool %s required_capability returned an empty array.', $slug )
				);
				foreach ( $capability as $cap ) {
					$this->assertIsString(
						$cap,
						sprintf( 'Tool %s required_capability array contains non-string entries.', $slug )
					);
					$this->assertNotEmpty(
						$cap,
						sprintf( 'Tool %s required_capability array contains empty strings.', $slug )
					);
				}
				continue;
			}

			$this->assertIsString(
				$capability,
				sprintf( 'Tool %s required_capability must be a string or array of strings.', $slug )
			);
			$this->assertNotEmpty(
				$capability,
				sprintf( 'Tool %s required_capability must be non-empty.', $slug )
			);
		}
	}

	/**
	 * Every tool's execute() either returns a result or a WP_Error / failure
	 * envelope when invoked by a logged-out user. The point of this assertion
	 * is to catch tools that crash with a fatal error or uncaught exception
	 * for unauthenticated callers; a tool that legitimately succeeds for the
	 * 'read' capability is also acceptable.
	 */
	public function test_every_tool_execute_handles_unauthenticated_caller_safely() {
		$tools = $this->get_registered_tools();

		// Save and clear the current user.
		$original_user = get_current_user_id();
		wp_set_current_user( 0 );

		try {
			foreach ( $tools as $slug => $tool ) {
				try {
					$result = $tool->execute( array(), array( 'source' => 'phpunit-smoke-test' ) );
				} catch ( Throwable $e ) {
					$this->fail(
						sprintf(
							'Tool %s::execute() threw %s for an unauthenticated caller: %s',
							$slug,
							get_class( $e ),
							$e->getMessage()
						)
					);
				}

				// Acceptable shapes: WP_Error, array, string, null, scalar.
				// We only fail if execute() returned an object that is not a
				// WP_Error and not an iterable — that would indicate a leaked
				// internal handle.
				if ( is_object( $result ) && ! ( $result instanceof WP_Error ) ) {
					$this->assertTrue(
						$result instanceof Traversable
							|| $result instanceof JsonSerializable
							|| $result instanceof ArrayAccess,
						sprintf(
							'Tool %s::execute() returned an unexpected object of type %s.',
							$slug,
							get_class( $result )
						)
					);
				} else {
					// Any other return shape is acceptable for the smoke test.
					$this->assertTrue( true );
				}
			}
		} finally {
			wp_set_current_user( $original_user );
		}
	}

	/**
	 * Confirm the coverage manifests are in sync with the tool source tree
	 * so contributors do not silently drop a tool out of the smoke test by
	 * forgetting to regenerate the manifest.
	 *
	 * @dataProvider provide_manifest_paths
	 *
	 * @param string $source_dir   Source directory containing class-*.php files.
	 * @param string $manifest     Manifest file path relative to repository root.
	 * @param string $regen_script Script contributors should run to fix drift.
	 */
	public function test_tool_class_manifest_is_up_to_date( $source_dir, $manifest, $regen_script ) {
		$root = dirname( __DIR__ );

		$source_full = $root . '/' . $source_dir;
		if ( ! is_dir( $source_full ) ) {
			$this->markTestSkipped( 'Source directory missing: ' . $source_dir );
		}

		$manifest_full = $root . '/' . $manifest;
		$this->assertFileExists(
			$manifest_full,
			sprintf( 'Tool coverage manifest missing — run %s to regenerate.', $regen_script )
		);

		$expected = array();
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $source_full ) );
		foreach ( $iterator as $file ) {
			$basename = $file->getBasename();
			if ( 0 !== strpos( $basename, 'class-' ) || '.php' !== substr( $basename, -4 ) ) {
				continue;
			}
			$expected[] = substr( $basename, 6, -4 ); // Strip 'class-' prefix and '.php' suffix.
		}
		$expected = array_values( array_unique( $expected ) );
		sort( $expected );

		$actual = array_filter(
			array_map( 'trim', file( $manifest_full ) ),
			static function ( $line ) {
				return '' !== $line && 0 !== strpos( $line, '#' );
			}
		);
		$actual = array_values( $actual );
		sort( $actual );

		$missing = array_diff( $expected, $actual );
		$extra   = array_diff( $actual, $expected );

		$this->assertEmpty(
			$missing,
			sprintf(
				'Tool classes missing from %s — run %s. First few: %s',
				$manifest,
				$regen_script,
				implode( ', ', array_slice( $missing, 0, 5 ) )
			)
		);
		$this->assertEmpty(
			$extra,
			sprintf(
				'Stale entries in %s that no longer have a source file — run %s. First few: %s',
				$manifest,
				$regen_script,
				implode( ', ', array_slice( $extra, 0, 5 ) )
			)
		);
	}

	/**
	 * Manifest paths data provider.
	 *
	 * @return array<string,array{0:string,1:string,2:string}>
	 */
	public static function provide_manifest_paths() {
		return array(
			'base tools' => array(
				'includes/tools',
				'tests/tools/.coverage-manifest.txt',
				'bin/generate-tool-coverage-manifest.sh',
			),
			'pro tools'  => array(
				'addons/pro/includes/tools',
				'addons/pro/tests/tools/.coverage-manifest.txt',
				'bin/generate-tool-coverage-manifest.sh',
			),
		);
	}

	/**
	 * Pull a parameter schema array out of the tool, regardless of whether it
	 * exposes get_parameters_schema() (interface) or get_definition() (most
	 * tools).
	 *
	 * @param object $tool Tool instance.
	 * @return array|null  Parameter schema array, or null if not available.
	 */
	private function extract_parameter_schema( $tool ) {
		if ( method_exists( $tool, 'get_parameters_schema' ) ) {
			$schema = $tool->get_parameters_schema();
			if ( is_array( $schema ) && ! empty( $schema ) ) {
				return $schema;
			}
		}

		if ( method_exists( $tool, 'get_definition' ) ) {
			$definition = $tool->get_definition();
			if ( is_array( $definition ) && isset( $definition['parameters'] ) && is_array( $definition['parameters'] ) ) {
				return $definition['parameters'];
			}
		}

		return null;
	}

	/**
	 * Pull the tool's required capability declaration. Returns null when
	 * the tool does not advertise one.
	 *
	 * @param object $tool Tool instance.
	 * @return string|array<string>|null
	 */
	private function extract_required_capability( $tool ) {
		if ( method_exists( $tool, 'get_required_capability' ) ) {
			$cap = $tool->get_required_capability();
			if ( is_string( $cap ) || is_array( $cap ) ) {
				return $cap;
			}
		}

		if ( method_exists( $tool, 'get_definition' ) ) {
			$def = $tool->get_definition();
			if ( is_array( $def ) && isset( $def['required_capability'] ) ) {
				$cap = $def['required_capability'];
				if ( is_string( $cap ) || is_array( $cap ) ) {
					return $cap;
				}
			}
		}

		return null;
	}

	/**
	 * Recursively walk a parameter schema and collect every constraint
	 * violation. Returns an array of human-readable strings (one per finding)
	 * so the eventual assertion failure cites every offender at once.
	 *
	 * @param mixed  $node Current schema node.
	 * @param string $tool Tool slug for error messages.
	 * @param string $path JSON-pointer-ish breadcrumb for error messages.
	 * @return string[]
	 */
	private function find_schema_violations( $node, $tool, $path = '' ) {
		$violations = array();

		if ( ! is_array( $node ) ) {
			return $violations;
		}

		// Detect 'type' => 'mixed' (or array containing 'mixed').
		if ( isset( $node['type'] ) ) {
			$types = (array) $node['type'];
			foreach ( $types as $type_value ) {
				if ( 'mixed' === $type_value ) {
					$violations[] = sprintf( '  - %s: %s declares forbidden type "mixed"', $tool, $path === '' ? '(root)' : $path );
				}
			}

			// Every 'type:array' MUST declare items.
			if ( in_array( 'array', $types, true ) && ! isset( $node['items'] ) ) {
				$violations[] = sprintf( '  - %s: %s is type:array but missing "items"', $tool, $path === '' ? '(root)' : $path );
			}
		}

		// Recurse into nested structures.
		foreach ( $node as $key => $child ) {
			if ( is_array( $child ) ) {
				$child_path = '' === $path ? (string) $key : $path . '.' . $key;
				$violations = array_merge(
					$violations,
					$this->find_schema_violations( $child, $tool, $child_path )
				);
			}
		}

		return $violations;
	}
}
