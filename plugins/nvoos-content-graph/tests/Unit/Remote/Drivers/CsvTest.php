<?php
declare(strict_types=1);

namespace NvoosContentGraph\Tests\Unit\Remote\Drivers;

use NvoosContentGraph\Remote\Drivers\Csv;
use WP_UnitTestCase;

/**
 * Unit tests for the CSV file driver.
 *
 * Uses real files under the WordPress uploads directory, written through
 * WP_Filesystem so no direct filesystem calls are needed.
 *
 * @since 1.0.9
 */
class CsvTest extends WP_UnitTestCase {

	/** @var string|null Uploads-relative CSV path created in setUp. */
	private ?string $csvPath = null;

	/** @var string|null Outside-uploads CSV path created in setUp. */
	private ?string $outsidePath = null;

	/** @var \WP_Filesystem_Base|null */
	private $filesystem = null;

	/**
	 * Create the CSV fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		require_once ABSPATH . 'wp-admin/includes/file.php';
		// Force the direct filesystem method — wp-phpunit otherwise falls
		// back to ftpsockets and the fixture writes silently fail.
		if ( ! defined( 'FS_METHOD' ) ) {
			define( 'FS_METHOD', 'direct' );
		}
		WP_Filesystem();
		$this->filesystem = $GLOBALS['wp_filesystem'];

		$uploads = wp_get_upload_dir();
		if ( ! empty( $uploads['basedir'] ) ) {
			wp_mkdir_p( $uploads['basedir'] );
			$this->csvPath = $uploads['basedir'] . '/nvoos-cg-test-people.csv';
			$this->filesystem->put_contents(
				$this->csvPath,
				"id,name,homepage,kind\n" .
				"1,Alice,https://alice.example/,person\n" .
				"2,Bob,https://bob.example/,person\n" .
				"3,Carol,,person\n"
			);
		}

		$tmp               = sys_get_temp_dir();
		$this->outsidePath = $tmp . '/nvoos-cg-test-outside.csv';
		$this->filesystem->put_contents( $this->outsidePath, "id,name\n1,Outside\n" );
	}

	/**
	 * Remove the CSV fixtures.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if ( $this->filesystem ) {
			if ( $this->csvPath ) {
				$this->filesystem->delete( $this->csvPath );
			}
			if ( $this->outsidePath ) {
				$this->filesystem->delete( $this->outsidePath );
			}
		}
		parent::tearDown();
	}

	/**
	 * Build a configured driver instance.
	 *
	 * @param array<string,mixed> $config Config overrides.
	 * @return Csv
	 */
	private function driver( array $config = array() ): Csv {
		$driver = new Csv();
		$driver->setConfig(
			array_merge(
				array(
					'_slug'          => 'csv_test',
					'has_header_row' => true,
					'max_items'      => 100,
					// 'type' is a static value (like node_type elsewhere), not a
					// column reference — every ingested row becomes a person.
					'field_map'      => array(
						'id'    => 'id',
						'label' => 'name',
						'url'   => 'homepage',
						'type'  => 'person',
					),
				),
				$config
			)
		);
		return $driver;
	}

	/**
	 * A header-row CSV with a field map ingests as typed nodes.
	 *
	 * @return void
	 */
	public function test_fetch_nodes_parses_header_row_csv(): void {
		$nodes = $this->driver( array( 'file_path' => $this->csvPath ) )->fetchNodes();

		$this->assertCount( 3, $nodes );
		$this->assertSame( 'remote_csv_test_1', $nodes[0]['node_id'] );
		$this->assertSame( 'Alice', $nodes[0]['label'] );
		$this->assertSame( 'person', $nodes[0]['type'] );
		$this->assertSame( 'https://alice.example/', $nodes[0]['url'] );
		$this->assertSame( '', $nodes[2]['url'] ); // empty cell -> empty URL.
	}

	/**
	 * max_items caps row ingestion.
	 *
	 * @return void
	 */
	public function test_fetch_nodes_respects_max_items(): void {
		$nodes = $this->driver(
			array(
				'file_path' => $this->csvPath,
				'max_items' => 2,
			)
		)->fetchNodes();

		$this->assertCount( 2, $nodes );
	}

	/**
	 * testConnection succeeds for a readable CSV inside uploads.
	 *
	 * @return void
	 */
	public function test_test_connection_succeeds_for_uploads_file(): void {
		$result = $this->driver( array( 'file_path' => $this->csvPath ) )->testConnection();

		$this->assertTrue( $result['success'] );
	}

	/**
	 * Paths outside the uploads directory are refused (path-traversal guard).
	 *
	 * @return void
	 */
	public function test_outside_uploads_path_is_refused(): void {
		$result = $this->driver( array( 'file_path' => $this->outsidePath ) )->testConnection();

		$this->assertFalse( $result['success'] );
		$this->assertSame( array(), $this->driver( array( 'file_path' => $this->outsidePath ) )->fetchNodes() );
	}

	/**
	 * A missing field map (no label mapping) ingests nothing.
	 *
	 * @return void
	 */
	public function test_fetch_nodes_empty_without_label_mapping(): void {
		$nodes = $this->driver(
			array(
				'file_path' => $this->csvPath,
				'field_map' => array( 'id' => 'id' ),
			)
		)->fetchNodes();

		$this->assertSame( array(), $nodes );
	}
}
