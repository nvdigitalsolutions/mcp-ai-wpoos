<?php
/**
 * Test suite for the Codex startup provisioning script.
 *
 * @package WP_MCP_AI
 */

class Test_Codex_Startup extends WP_UnitTestCase {
		/**
		 * Ensure the startup script installs Composer dependencies when missing.
		 */
	public function test_composer_dependencies_installed_on_startup() {
		// Skip test if exec() is not available.
		if ( ! function_exists( 'exec' ) ) {
			$this->markTestSkipped( 'exec() function is disabled. Cannot test shell script execution.' );
		}

			$root_dir = trailingslashit( sys_get_temp_dir() ) . 'wp-mcp-ai-startup-' . wp_generate_uuid4();
			$bin_dir  = $root_dir . '/bin';

			wp_mkdir_p( $bin_dir );

			$source_script = dirname( __DIR__ ) . '/bin/codex-startup.sh';
			$target_script = $bin_dir . '/codex-startup.sh';

			$this->assertTrue( copy( $source_script, $target_script ), 'Unable to copy codex-startup.sh to temporary directory.' );
			$this->assertTrue( chmod( $target_script, 0755 ), 'Unable to mark codex-startup.sh as executable.' );

			$mock_bin = $root_dir . '/mock-bin';
			wp_mkdir_p( $mock_bin );

			$log_file         = $root_dir . '/composer.log';
			$log_file_escaped = escapeshellarg( $log_file );

			$mock_composer_script  = "#!/usr/bin/env bash\n";
			$mock_composer_script .= "set -euo pipefail\n";
			$mock_composer_script .= 'echo "composer $@" >> ' . $log_file_escaped . "\n";
			$mock_composer_script .= "workdir=\"\"\n";
			$mock_composer_script .= 'for arg in "$@"; do' . "\n";
			$mock_composer_script .= '  case "$arg" in' . "\n";
			$mock_composer_script .= '    --working-dir=*)' . "\n";
			$mock_composer_script .= '      workdir="${arg#--working-dir=}"' . "\n";
			$mock_composer_script .= '      ;;' . "\n";
			$mock_composer_script .= '  esac' . "\n";
			$mock_composer_script .= 'done' . "\n";
			$mock_composer_script .= 'if [[ -z "$workdir" ]]; then' . "\n";
			$mock_composer_script .= '  workdir="$PWD"' . "\n";
			$mock_composer_script .= 'fi' . "\n";
			$mock_composer_script .= 'mkdir -p "$workdir/vendor"' . "\n";
			$mock_composer_script .= 'touch "$workdir/vendor/autoload.php"' . "\n";
			$mock_composer_script .= "exit 0\n";

			$mock_composer_path = $mock_bin . '/composer';
			$this->assertNotFalse( file_put_contents( $mock_composer_path, $mock_composer_script ), 'Unable to create mock composer binary.' );
			$this->assertTrue( chmod( $mock_composer_path, 0755 ), 'Unable to make mock composer binary executable.' );

			$original_path = getenv( 'PATH' );
			putenv( 'PATH=' . $mock_bin . PATH_SEPARATOR . $original_path );
			putenv( 'WP_MCP_AI_STARTUP_EXIT_AFTER_COMPOSER=1' );

			$output = array();
			$result = 0;

		try {
				exec( 'bash ' . escapeshellarg( $target_script ) . ' 2>&1', $output, $result );
		} finally {
				putenv( 'PATH=' . $original_path );
				putenv( 'WP_MCP_AI_STARTUP_EXIT_AFTER_COMPOSER' );
		}

		try {
				$this->assertSame( 0, $result, 'codex-startup.sh should exit successfully. Output: ' . implode( "\n", $output ) );
				$this->assertFileExists( $root_dir . '/vendor/autoload.php', 'Startup script should create vendor/autoload.php via Composer.' );
				$this->assertFileExists( $log_file, 'Mock composer log should be created.' );

				$log_contents = file_get_contents( $log_file );
				$this->assertNotFalse( $log_contents, 'Unable to read mock composer log.' );
				$this->assertStringContainsString( 'install --no-interaction --prefer-dist', $log_contents, 'Composer should be invoked with install flags.' );
		} finally {
				$this->remove_directory( $root_dir );
		}
	}

		/**
		 * Recursively remove a directory and its contents.
		 *
		 * @param string $directory Directory path.
		 */
	private function remove_directory( $directory ) {
		if ( ! is_dir( $directory ) ) {
				return;
		}

			$items = scandir( $directory );
		if ( false === $items ) {
				return;
		}

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
					continue;
			}

				$path = $directory . '/' . $item;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
					$this->remove_directory( $path );
			} else {
					@unlink( $path );
			}
		}

			@rmdir( $directory );
	}
}
