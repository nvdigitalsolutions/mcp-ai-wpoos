<?php
/**
 * Test profession dataset mappings.
 *
 * @package WP_MCP_AI
 */

/**
 * Test profession dataset mappings functionality.
 */
class Test_Profession_Dataset_Mappings extends WP_UnitTestCase {

	/**
	 * Test dataset mapping file exists and loads correctly.
	 */
	public function test_dataset_mapping_file_exists() {
		$file = WP_MCP_AI_PATH . 'includes/professions/profession-dataset-mappings.php';
		$this->assertFileExists( $file, 'Dataset mapping file should exist' );

		require_once $file;

		$this->assertTrue(
			function_exists( 'wp_mcp_ai_get_profession_dataset_recommendations' ),
			'Dataset recommendation function should exist'
		);

		$this->assertTrue(
			function_exists( 'wp_mcp_ai_get_all_profession_dataset_mappings' ),
			'Dataset mappings function should exist'
		);
	}

	/**
	 * Test dataset recommendations for specific professions.
	 */
	public function test_get_profession_dataset_recommendations() {
		require_once WP_MCP_AI_PATH . 'includes/professions/profession-dataset-mappings.php';

		// Test data scientist gets appropriate datasets.
		$datasets = wp_mcp_ai_get_profession_dataset_recommendations( 'data_scientist' );
		$this->assertIsArray( $datasets, 'Should return array of datasets' );
		$this->assertNotEmpty( $datasets, 'Data scientist should have dataset recommendations' );

		// Verify dataset structure.
		$first_dataset = $datasets[0];
		$this->assertArrayHasKey( 'dataset', $first_dataset );
		$this->assertArrayHasKey( 'name', $first_dataset );
		$this->assertArrayHasKey( 'category', $first_dataset );
		$this->assertArrayHasKey( 'priority', $first_dataset );
	}

	/**
	 * Test creative professions get vision datasets.
	 */
	public function test_creative_professions_get_vision_datasets() {
		require_once WP_MCP_AI_PATH . 'includes/professions/profession-dataset-mappings.php';

		$creative_professions = array( 'graphic_designer', 'photographer', 'graphic_artist' );

		foreach ( $creative_professions as $profession_slug ) {
			$datasets = wp_mcp_ai_get_profession_dataset_recommendations( $profession_slug );

			// Check if at least one dataset is vision category.
			$has_vision = false;
			foreach ( $datasets as $dataset ) {
				if ( isset( $dataset['category'] ) && 'vision' === $dataset['category'] ) {
					$has_vision = true;
					break;
				}
			}

			$this->assertTrue(
				$has_vision,
				"Creative profession {$profession_slug} should have at least one vision dataset"
			);
		}
	}

	/**
	 * Test content creators get NLP datasets.
	 */
	public function test_content_professions_get_nlp_datasets() {
		require_once WP_MCP_AI_PATH . 'includes/professions/profession-dataset-mappings.php';

		$content_professions = array( 'content_creator', 'screenwriter', 'medical_writer' );

		foreach ( $content_professions as $profession_slug ) {
			$datasets = wp_mcp_ai_get_profession_dataset_recommendations( $profession_slug );

			// Check if at least one dataset is NLP category.
			$has_nlp = false;
			foreach ( $datasets as $dataset ) {
				if ( isset( $dataset['category'] ) && 'nlp' === $dataset['category'] ) {
					$has_nlp = true;
					break;
				}
			}

			$this->assertTrue(
				$has_nlp,
				"Content profession {$profession_slug} should have at least one NLP dataset"
			);
		}
	}

	/**
	 * Test sound designer gets audio datasets.
	 */
	public function test_audio_professions_get_audio_datasets() {
		require_once WP_MCP_AI_PATH . 'includes/professions/profession-dataset-mappings.php';

		$datasets = wp_mcp_ai_get_profession_dataset_recommendations( 'sound_designer' );

		// Check if at least one dataset is audio category.
		$has_audio = false;
		foreach ( $datasets as $dataset ) {
			if ( isset( $dataset['category'] ) && 'audio' === $dataset['category'] ) {
				$has_audio = true;
				break;
			}
		}

		$this->assertTrue( $has_audio, 'Sound designer should have at least one audio dataset' );
	}

	/**
	 * Test unknown profession returns empty array.
	 */
	public function test_unknown_profession_returns_empty() {
		require_once WP_MCP_AI_PATH . 'includes/professions/profession-dataset-mappings.php';

		$datasets = wp_mcp_ai_get_profession_dataset_recommendations( 'nonexistent_profession' );

		$this->assertIsArray( $datasets );
		$this->assertEmpty( $datasets, 'Unknown profession should return empty array' );
	}

	/**
	 * Test profession CPT has preferred datasets meta constant.
	 */
	public function test_profession_cpt_has_meta_constant() {
		$this->assertTrue(
			defined( 'WP_MCP_AI_Profession_CPT::META_PREFERRED_DATASETS' ),
			'Profession CPT should have META_PREFERRED_DATASETS constant'
		);

		$this->assertEquals(
			'_wp_mcp_ai_profession_preferred_datasets',
			WP_MCP_AI_Profession_CPT::META_PREFERRED_DATASETS
		);
	}

	/**
	 * Test preferred datasets sanitization.
	 */
	public function test_sanitize_preferred_datasets() {
		$input = array(
			array(
				'dataset'  => 'test/dataset',
				'name'     => 'Test Dataset',
				'category' => 'nlp',
				'priority' => 'high',
			),
			array(
				'dataset'  => '<script>alert("xss")</script>',
				'name'     => 'XSS Attempt',
				'category' => 'vision',
				'priority' => 'medium',
			),
		);

		$sanitized = WP_MCP_AI_Profession_CPT::sanitize_preferred_datasets( $input );

		$this->assertIsArray( $sanitized );
		$this->assertCount( 2, $sanitized );

		// Check XSS was sanitized.
		$this->assertNotContains( '<script>', $sanitized[1]['dataset'] );
	}

	/**
	 * Test sanitization limits to 10 datasets.
	 */
	public function test_sanitization_limits_to_10_datasets() {
		$input = array();
		for ( $i = 0; $i < 15; $i++ ) {
			$input[] = array(
				'dataset'  => "dataset-{$i}",
				'name'     => "Dataset {$i}",
				'category' => 'nlp',
				'priority' => 'medium',
			);
		}

		$sanitized = WP_MCP_AI_Profession_CPT::sanitize_preferred_datasets( $input );

		$this->assertCount( 10, $sanitized, 'Should limit to 10 datasets' );
	}

	/**
	 * Test all mappings return valid structure.
	 */
	public function test_all_mappings_have_valid_structure() {
		require_once WP_MCP_AI_PATH . 'includes/professions/profession-dataset-mappings.php';

		$all_mappings = wp_mcp_ai_get_all_profession_dataset_mappings();

		$this->assertIsArray( $all_mappings );
		$this->assertNotEmpty( $all_mappings, 'Should have at least one profession mapping' );

		foreach ( $all_mappings as $profession_slug => $datasets ) {
			$this->assertIsString( $profession_slug, 'Profession slug should be string' );
			$this->assertIsArray( $datasets, 'Datasets should be array' );

			foreach ( $datasets as $dataset ) {
				$this->assertArrayHasKey( 'dataset', $dataset, "Dataset for {$profession_slug} missing 'dataset' key" );
				$this->assertArrayHasKey( 'name', $dataset, "Dataset for {$profession_slug} missing 'name' key" );
				$this->assertArrayHasKey( 'category', $dataset, "Dataset for {$profession_slug} missing 'category' key" );
				$this->assertArrayHasKey( 'priority', $dataset, "Dataset for {$profession_slug} missing 'priority' key" );

				// Validate category values.
				$valid_categories = array( 'nlp', 'vision', 'audio', 'multimodal' );
				$this->assertContains(
					$dataset['category'],
					$valid_categories,
					"Invalid category '{$dataset['category']}' for {$profession_slug}"
				);

				// Validate priority values.
				$valid_priorities = array( 'critical', 'high', 'medium', 'low' );
				$this->assertContains(
					$dataset['priority'],
					$valid_priorities,
					"Invalid priority '{$dataset['priority']}' for {$profession_slug}"
				);
			}
		}
	}

	/**
	 * Test resync assigns datasets to professions with empty datasets.
	 */
	public function test_resync_assigns_datasets_to_professions_with_empty_datasets() {
		// Create a test profession with a slug that has dataset mappings.
		$profession_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_profession',
				'post_title'  => 'Data Scientist',
				'post_name'   => 'data_scientist',
				'post_status' => 'publish',
			)
		);

		// Set empty dataset array (simulating the bug scenario).
		update_post_meta( $profession_id, '_wp_mcp_ai_profession_preferred_datasets', array() );

		// Delete the sync option to allow resync to run.
		delete_option( 'wp_mcp_ai_professions_datasets_synced' );

		// Run the resync.
		WP_MCP_AI_Profession_Seeder::resync_profession_datasets();

		// Get the datasets after resync.
		$datasets = get_post_meta( $profession_id, '_wp_mcp_ai_profession_preferred_datasets', true );

		// Verify datasets were assigned.
		$this->assertIsArray( $datasets, 'Datasets should be an array' );
		$this->assertNotEmpty( $datasets, 'Datasets should not be empty after resync' );
		$this->assertGreaterThan( 0, count( $datasets ), 'Profession should have at least one dataset' );

		// Verify dataset structure.
		$first_dataset = $datasets[0];
		$this->assertArrayHasKey( 'dataset', $first_dataset );
		$this->assertArrayHasKey( 'name', $first_dataset );
		$this->assertArrayHasKey( 'category', $first_dataset );
		$this->assertArrayHasKey( 'priority', $first_dataset );
	}

	/**
	 * Test resync continues to run until all professions have datasets.
	 */
	public function test_resync_continues_until_all_professions_have_datasets() {
		// Create multiple test professions.
		$profession_ids   = array();
		$profession_slugs = array( 'data_scientist', 'graphic_designer', 'content_creator' );

		foreach ( $profession_slugs as $slug ) {
			$profession_ids[] = $this->factory->post->create(
				array(
					'post_type'   => 'mcp_ai_profession',
					'post_title'  => ucwords( str_replace( '_', ' ', $slug ) ),
					'post_name'   => $slug,
					'post_status' => 'publish',
				)
			);
		}

		// Set all professions to have empty datasets.
		foreach ( $profession_ids as $id ) {
			update_post_meta( $id, '_wp_mcp_ai_profession_preferred_datasets', array() );
		}

		// Delete the sync option to allow resync to run.
		delete_option( 'wp_mcp_ai_professions_datasets_synced' );

		// Run the resync.
		WP_MCP_AI_Profession_Seeder::resync_profession_datasets();

		// Verify all professions now have datasets.
		foreach ( $profession_ids as $id ) {
			$datasets = get_post_meta( $id, '_wp_mcp_ai_profession_preferred_datasets', true );
			$this->assertIsArray( $datasets, 'Datasets should be an array' );
			$this->assertNotEmpty( $datasets, "Profession {$id} should have datasets after resync" );
		}

		// Verify the sync option is now set.
		$this->assertTrue( (bool) get_option( 'wp_mcp_ai_professions_datasets_synced', false ), 'Sync option should be set after successful sync' );
	}

	/**
	 * Test resync does not overwrite existing datasets.
	 */
	public function test_resync_does_not_overwrite_existing_datasets() {
		// Create a test profession.
		$profession_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_profession',
				'post_title'  => 'Data Scientist',
				'post_name'   => 'data_scientist',
				'post_status' => 'publish',
			)
		);

		// Set custom datasets.
		$custom_datasets = array(
			array(
				'dataset'  => 'custom/dataset',
				'name'     => 'Custom Dataset',
				'category' => 'nlp',
				'priority' => 'high',
			),
		);
		update_post_meta( $profession_id, '_wp_mcp_ai_profession_preferred_datasets', $custom_datasets );

		// Delete the sync option to allow resync to run.
		delete_option( 'wp_mcp_ai_professions_datasets_synced' );

		// Run the resync.
		WP_MCP_AI_Profession_Seeder::resync_profession_datasets();

		// Get the datasets after resync.
		$datasets = get_post_meta( $profession_id, '_wp_mcp_ai_profession_preferred_datasets', true );

		// Verify custom datasets were not overwritten.
		$this->assertIsArray( $datasets );
		$this->assertCount( 1, $datasets, 'Custom datasets should not be overwritten' );
		$this->assertEquals( 'custom/dataset', $datasets[0]['dataset'], 'Custom dataset should remain' );
	}
}
