<?php
declare(strict_types=1);
// phpcs:ignoreFile Generic.Files.OneObjectStructurePerFile, Universal.Files.SeparateFunctionsFromOO.Mixed, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Test stubs: one process-wide mock of the JetEngine surface the Detector touches, mirroring the base plugin's tests/helpers/jetengine-stubs.php pattern.

/**
 * Shared JetEngine CCT stubs for the Content Graph test suite.
 *
 * Only ONE definition of these global symbols may exist per process, so
 * every suite that needs a JetEngine CCT mock requires this file instead
 * of declaring its own `jet_engine()` stub. The stub only models the
 * surface {@see \NvoosContentGraph\Graph\Detector::getCctTypes()} reads:
 * `jet_engine() -> modules -> get_module( 'custom-content-types' ) ->
 * instance -> manager -> get_content_types()`, plus the per-type `db`
 * query used by {@see \NvoosContentGraph\Graph\Detector::detectCcts()}.
 *
 * Tests install a configured engine via
 * `nvoos_cg_test_install_jetengine_cct()` and reset it with
 * `nvoos_cg_test_reset_jetengine()` in tearDown().
 */

/**
 * Stub for the JetEngine modules registry.
 */
class Nvoos_CG_Test_Modules {

	/**
	 * The single module wrapper served for every module name.
	 *
	 * @var object|null
	 */
	public $module;

	/**
	 * @param object|null $module Module wrapper served by get_module().
	 */
	public function __construct( $module ) {
		$this->module = $module;
	}

	/**
	 * Return the installed module wrapper.
	 *
	 * @param string $name Module name (ignored — the stub serves one wrapper).
	 * @return object|null
	 */
	public function get_module( $name ) {
		unset( $name );
		return $this->module;
	}
}

/**
 * Stub for the JetEngine module wrapper (`instance` holder).
 */
class Nvoos_CG_Test_Cct_Module_Wrapper {

	/**
	 * The module instance.
	 *
	 * @var object|null
	 */
	public $instance;

	/**
	 * @param object|null $instance Module instance.
	 */
	public function __construct( $instance ) {
		$this->instance = $instance;
	}
}

/**
 * Stub for the Custom Content Types module instance.
 */
class Nvoos_CG_Test_Cct_Module {

	/**
	 * The content-type manager.
	 *
	 * @var object|null
	 */
	public $manager;

	/**
	 * @param object|null $manager Manager instance.
	 */
	public function __construct( $manager ) {
		$this->manager = $manager;
	}
}

/**
 * Stub for the CCT manager that enumerates registered content types.
 */
class Nvoos_CG_Test_Cct_Manager {

	/**
	 * Registered content types (objects or arrays).
	 *
	 * @var array<int,mixed>
	 */
	private array $types = array();

	/**
	 * Replace the registered content-type list.
	 *
	 * @param array<int,mixed> $types Content-type objects or arrays.
	 * @return void
	 */
	public function set_content_types( array $types ): void {
		$this->types = $types;
	}

	/**
	 * Return the registered content types.
	 *
	 * @return array<int,mixed>
	 */
	public function get_content_types() {
		return $this->types;
	}
}

/**
 * Stub for a single JetEngine CCT type definition.
 */
class Nvoos_CG_Test_Cct_Type {

	/**
	 * CCT slug.
	 *
	 * @var string
	 */
	public $slug = '';

	/**
	 * CCT human-readable name.
	 *
	 * @var string
	 */
	public $name = '';

	/**
	 * CCT database handler.
	 *
	 * @var object|null
	 */
	public $db = null;

	/**
	 * @param string      $slug CCT slug.
	 * @param string      $name CCT name.
	 * @param object|null $db   Database handler.
	 */
	public function __construct( string $slug, string $name, $db ) {
		$this->slug = $slug;
		$this->name = $name;
		$this->db   = $db;
	}
}

/**
 * Stub for the JetEngine CCT database handler.
 */
class Nvoos_CG_Test_Cct_Db {

	/**
	 * Rows returned by query().
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $items = array();

	/**
	 * Whether the physical CCT table exists.
	 *
	 * @var bool
	 */
	public bool $table_exists = true;

	/**
	 * Last format flag set via set_format_flag().
	 *
	 * @var mixed
	 */
	public $format_flag = null;

	/**
	 * @param array<int,array<string,mixed>> $items         Rows returned by query().
	 * @param bool                           $table_exists  Whether the physical table exists.
	 */
	public function __construct( array $items = array(), bool $table_exists = true ) {
		$this->items        = $items;
		$this->table_exists = $table_exists;
	}

	/**
	 * Report whether the physical CCT table exists.
	 *
	 * @return bool
	 */
	public function is_table_exists(): bool {
		return $this->table_exists;
	}

	/**
	 * Return the number of stubbed rows.
	 *
	 * @param array<string,mixed> $args Query args (ignored).
	 * @param string              $rel  Relation (ignored).
	 * @return int
	 */
	public function count( $args = array(), $rel = 'AND' ): int {
		unset( $args, $rel );
		return count( $this->items );
	}

	/**
	 * Record the requested format flag.
	 *
	 * @param mixed $flag Format flag.
	 * @return void
	 */
	public function set_format_flag( $flag ): void {
		$this->format_flag = $flag;
	}

	/**
	 * Return the stubbed rows.
	 *
	 * @param array<string,mixed> $args   Query args (ignored).
	 * @param int                 $limit  Result limit (ignored).
	 * @param int                 $offset Result offset (ignored).
	 * @return array<int,array<string,mixed>>
	 */
	public function query( $args = array(), $limit = 0, $offset = 0 ) {
		unset( $args, $limit, $offset );
		return $this->items;
	}
}

/**
 * Stub for the JetEngine root instance.
 */
class Nvoos_CG_Test_Jet_Engine {

	/**
	 * Modules registry.
	 *
	 * @var object|null
	 */
	public $modules = null;

	/**
	 * @param object|null $modules Modules registry.
	 */
	public function __construct( $modules ) {
		$this->modules = $modules;
	}
}

/**
 * Install a configured JetEngine CCT stub for the current test.
 *
 * @param array<int,array{slug: string, name: string, rows?: array<int,array<string,mixed>>, table_exists?: bool}> $types CCT specs.
 * @return Nvoos_CG_Test_Jet_Engine The installed engine (tests may mutate it).
 */
function nvoos_cg_test_install_jetengine_cct( array $types = array() ): Nvoos_CG_Test_Jet_Engine {
	$manager = new Nvoos_CG_Test_Cct_Manager();
	$built   = array();
	foreach ( $types as $spec ) {
		$db      = new Nvoos_CG_Test_Cct_Db(
			isset( $spec['rows'] ) && is_array( $spec['rows'] ) ? $spec['rows'] : array(),
			isset( $spec['table_exists'] ) ? (bool) $spec['table_exists'] : true
		);
		$built[] = new Nvoos_CG_Test_Cct_Type( $spec['slug'], $spec['name'], $db );
	}
	$manager->set_content_types( $built );

	$module  = new Nvoos_CG_Test_Cct_Module( $manager );
	$wrapper = new Nvoos_CG_Test_Cct_Module_Wrapper( $module );
	$modules = new Nvoos_CG_Test_Modules( $wrapper );
	$engine  = new Nvoos_CG_Test_Jet_Engine( $modules );

	$GLOBALS['nvoos_cg_test_jetengine_stub'] = $engine;
	return $engine;
}

/**
 * Reset the installed stub so a test can assert unavailable paths.
 *
 * @return void
 */
function nvoos_cg_test_reset_jetengine(): void {
	$GLOBALS['nvoos_cg_test_jetengine_stub'] = null;
}

/**
 * Mock jet_engine() accessor returning the currently installed stub.
 *
 * Returns null when no stub is installed — Detector treats that as the
 * modules-unavailable path, so absence tests should assert the skip
 * reason rather than the jetengine_not_active branch (the function
 * exists for the whole single-process run).
 *
 * @return Nvoos_CG_Test_Jet_Engine|null
 */
function jet_engine() {
	if ( ! isset( $GLOBALS['nvoos_cg_test_jetengine_stub'] ) ) {
		nvoos_cg_test_reset_jetengine();
	}
	$stub = $GLOBALS['nvoos_cg_test_jetengine_stub'];
	return $stub instanceof Nvoos_CG_Test_Jet_Engine ? $stub : null;
}
