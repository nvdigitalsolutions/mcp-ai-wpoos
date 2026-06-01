<?php
/**
 * Paper Store Test Helpers — Shared setup/teardown for Paper Store tests.
 *
 * Creates a temporary paper-store root directory with test collections
 * and cleans up after each test.
 *
 * @package WP_MCP_AI
 * @since   1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	// ABSPATH not yet defined — this trait is being loaded before WordPress.
	// Return early without defining the trait. The bootstrap should load this
	// file after the WP PHPUnit bootstrap (which defines ABSPATH).
	return;
}

/**
 * Trait WP_MCP_AI_Paper_Store_Test_Helpers
 *
 * Use this trait in any WP_UnitTestCase that needs a Paper Store fixture.
 */
trait WP_MCP_AI_Paper_Store_Test_Helpers {

	/**
	 * Temp paper-store root path.
	 *
	 * @var string
	 */
	protected $paper_root;

	/**
	 * Paper Store Manager instance.
	 *
	 * @var WP_MCP_AI_Paper_Store_Manager
	 */
	protected $manager;

	/**
	 * Set up paper store test fixture.
	 *
	 * Creates a temp directory, overrides the root path filter,
	 * and resets the manager.
	 */
	public function set_up_paper_store() {
		$this->paper_root = sys_get_temp_dir() . '/wp-mcp-ai-paper-test-' . wp_rand( 100000, 999999 ) . '/';
		if ( ! is_dir( $this->paper_root ) ) {
			mkdir( $this->paper_root, 0777, true );
		}

		// Override the root path filter to use our temp directory.
		add_filter(
			'wp_mcp_ai_paper_store_root',
			function () {
				return $this->paper_root;
			},
			999
		);

		// Reset the manager so it picks up the new root path.
		$this->manager = WP_MCP_AI_Paper_Store_Manager::get_instance();
		$this->manager->reset();
	}

	/**
	 * Tear down paper store test fixture.
	 *
	 * Removes the temp directory and resets the manager.
	 */
	public function tear_down_paper_store() {
		if ( null !== $this->manager ) {
			$this->manager->reset();
		}

		if ( null !== $this->paper_root && is_dir( $this->paper_root ) ) {
			$this->delete_directory( $this->paper_root );
		}

		remove_all_filters( 'wp_mcp_ai_paper_store_root', 999 );
	}

	/**
	 * Recursively delete a directory.
	 *
	 * @param string $dir Directory path.
	 */
	private function delete_directory( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = scandir( $dir );
		if ( ! is_array( $items ) ) {
			return;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . DIRECTORY_SEPARATOR . $item;
			if ( is_dir( $path ) ) {
				$this->delete_directory( $path );
			} else {
				unlink( $path );
			}
		}
		rmdir( $dir );
	}

	/**
	 * Create a sample record array for testing.
	 *
	 * @param string $id    Record ID.
	 * @param string $title Record title.
	 * @return array
	 */
	protected function make_record( $id = 'test-record', $title = 'Test Record' ) {
		return array(
			'id'          => $id,
			'type'        => 'test',
			'title'       => $title,
			'description' => 'A test record for unit testing.',
			'tags'        => array( 'test', 'unit' ),
			'status'      => 'published',
			'body'        => array( 'content' => 'Hello, world!' ),
			'meta'        => array( 'key' => 'value' ),
		);
	}

	/**
	 * Seed multiple records into a collection for testing.
	 *
	 * @param string $collection Collection name.
	 * @param array  $records    Array of record arrays (must include 'id').
	 */
	protected function seed_records( $collection, array $records ) {
		$repo = $this->manager->get_repository( $collection );
		foreach ( $records as $record ) {
			if ( ! isset( $record['type'] ) ) {
				$record['type'] = $collection;
			}
			if ( ! isset( $record['status'] ) ) {
				$record['status'] = 'published';
			}
			$repo->save( $record );
		}
	}
}
