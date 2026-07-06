<?php
/**
 * PHPUnit runner — works around classmap-authoritative autoloader issues.
 *
 * Usage: php bin/run-tests.php [file1.php file2.php ...]
 *        php bin/run-tests.php --filter Test_Tool_Wait_For_User
 */

$root = dirname( __DIR__ );

// 1. Load project autoloader.
$autoload = require $root . '/vendor/autoload.php';

// 2. Disable classmap-authoritative so PSR-4 fallback works.
if ( method_exists( $autoload, 'setClassMapAuthoritative' ) ) {
    $autoload->setClassMapAuthoritative( false );
}

// 3. Scan PHPUnit src/ subdirectories and register as PSR-4 roots.
$phpunit_src = $root . '/vendor/phpunit/phpunit/src/';
if ( is_dir( $phpunit_src ) ) {
    $dirs = array_filter( glob( $phpunit_src . '*' ), 'is_dir' );
    foreach ( $dirs as $dir ) {
        $namespace = 'PHPUnit\\' . basename( $dir ) . '\\';
        $autoload->addPsr4( $namespace, $dir . '/' );
    }
}

// 4. Also register Sebastian packages.
$vendor_dir = $root . '/vendor/';
$sebastian_map = array();
$packages = array_filter( glob( $vendor_dir . 'sebastian/*' ), 'is_dir' );
$packages = array_merge( $packages, array_filter( glob( $vendor_dir . 'phpunit/*' ), 'is_dir' ) );
$packages = array_merge( $packages, array_filter( glob( $vendor_dir . 'staabm/*' ), 'is_dir' ) );

foreach ( $packages as $pkg_dir ) {
    $composer_json = $pkg_dir . '/composer.json';
    if ( ! file_exists( $composer_json ) ) {
        continue;
    }
    $pkg_data = json_decode( file_get_contents( $composer_json ), true );
    if ( ! $pkg_data || empty( $pkg_data['autoload']['psr-4'] ) ) {
        continue;
    }
    foreach ( $pkg_data['autoload']['psr-4'] as $ns => $paths ) {
        $paths = (array) $paths;
        foreach ( $paths as $path ) {
            $full_path = $pkg_dir . '/' . rtrim( $path, '/' );
            if ( is_dir( $full_path ) ) {
                $autoload->addPsr4( $ns, $full_path . '/' );
            }
        }
    }
}

// 5. Verify PHPUnit is loadable.
if ( ! class_exists( 'PHPUnit\\TextUI\\Application' ) ) {
    fwrite( STDERR, "ERROR: PHPUnit classes not found after autoload setup.\n" );
    exit( 1 );
}

// 6. Build args, add default bootstrap.
$args = $_SERVER['argv'];
array_shift( $args );

$has_bootstrap = false;
$has_config    = false;
$test_files    = array();

foreach ( $args as $arg ) {
    if ( str_contains( $arg, 'bootstrap' ) ) {
        $has_bootstrap = true;
    }
    if ( str_contains( $arg, 'configuration' ) || $arg === '-c' ) {
        $has_config = true;
    }
    if ( str_ends_with( $arg, '.php' ) && file_exists( $arg ) ) {
        $test_files[] = $arg;
    }
}

if ( ! $has_bootstrap ) {
    $bootstrap = $root . '/tests/bootstrap.php';
    if ( file_exists( $bootstrap ) ) {
        array_unshift( $args, '--bootstrap', $bootstrap );
    }
}

if ( ! $has_config && empty( $test_files ) ) {
    $config = $root . '/phpunit.xml.dist';
    if ( file_exists( $config ) ) {
        array_unshift( $args, '--configuration', $config );
    }
}

// 7. Run PHPUnit.
chdir( $root );
$app = new \PHPUnit\TextUI\Application();
exit( $app->run( $args ) );
