<?php
/**
 * Test profession dataset preservation during replacement.
 *
 * @package WP_MCP_AI
 */

/**
 * Test that preferred datasets are preserved when professions are replaced.
 */
class Test_Profession_Dataset_Preservation extends WP_UnitTestCase {

	/**
	 * Tear down after each test.
	 */
	public function tearDown(): void {
		parent::tearDown();

		// Clean up any test professions.
		$posts = get_posts(
			array(
				'post_type'      => 'mcp_ai_profession',
				'posts_per_page' => -1,
				'post_status'    => 'any',
			)
		);

		foreach ( $posts as $post ) {
			wp_delete_post( $post->ID, true );
		}
	}

	/**
	 * Test that datasets are preserved during replace operation.
	 */
	public function test_datasets_preserved_during_replace() {
		// Load required classes.
		require_once WP_MCP_AI_PATH . 'includes/professions/class-wp-mcp-ai-profession-cpt.php';
		require_once WP_MCP_AI_PATH . 'includes/repositories/class-wp-mcp-ai-profession-repository.php';

		// Create a test profession with datasets.
		$profession_data = array(
			'title'       => 'Test Profession',
			'slug'        => 'test_profession',
			'description' => 'Test description',
			'category'    => 'technical',
		);

		$repository = new WP_MCP_AI_Profession_Repository();
		$post_id    = $repository->save( $profession_data );

		$this->assertIsInt( $post_id, 'Profession should be created successfully' );

		// Add preferred datasets to the profession.
		$test_datasets = array(
			array(
				'dataset'  => 'rajpurkar/squad',
				'name'     => 'SQuAD',
				'category' => 'nlp',
				'priority' => 'critical',
			),
			array(
				'dataset'  => 'abisee/cnn_dailymail',
				'name'     => 'CNN/DailyMail',
				'category' => 'nlp',
				'priority' => 'critical',
			),
		);

		update_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_PREFERRED_DATASETS, $test_datasets );

		// Verify datasets were saved.
		$saved_datasets = get_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_PREFERRED_DATASETS, true );
		$this->assertEquals( $test_datasets, $saved_datasets, 'Datasets should be saved correctly' );

		// Simulate the preservation logic that happens before replacement.
		$preserved_datasets = array();
		$existing_posts     = get_posts(
			array(
				'post_type'      => 'mcp_ai_profession',
				'posts_per_page' => -1,
				'post_status'    => 'any',
			)
		);

		foreach ( $existing_posts as $post ) {
			$datasets = get_post_meta( $post->ID, WP_MCP_AI_Profession_CPT::META_PREFERRED_DATASETS, true );
			if ( ! empty( $datasets ) && is_array( $datasets ) ) {
				$preserved_datasets[ $post->post_name ] = $datasets;
			}
		}

		$this->assertArrayHasKey( 'test_profession', $preserved_datasets, 'Datasets should be preserved' );
		$this->assertEquals( $test_datasets, $preserved_datasets['test_profession'], 'Preserved datasets should match original' );

		// Delete the profession (simulating replace action).
		wp_delete_post( $post_id, true );

		// Recreate the profession (simulating new creation from JSON).
		$new_post_id = $repository->save( $profession_data );
		$this->assertIsInt( $new_post_id, 'Profession should be recreated successfully' );

		// Restore the preserved datasets.
		$slug = sanitize_title( $profession_data['slug'] );
		if ( isset( $preserved_datasets[ $slug ] ) ) {
			update_post_meta( $new_post_id, WP_MCP_AI_Profession_CPT::META_PREFERRED_DATASETS, $preserved_datasets[ $slug ] );
		}

		// Verify datasets were restored.
		$restored_datasets = get_post_meta( $new_post_id, WP_MCP_AI_Profession_CPT::META_PREFERRED_DATASETS, true );
		$this->assertEquals( $test_datasets, $restored_datasets, 'Datasets should be restored after replacement' );
	}

	/**
	 * Test that datasets are preserved during update operation.
	 */
	public function test_datasets_preserved_during_update() {
		// Load required classes.
		require_once WP_MCP_AI_PATH . 'includes/professions/class-wp-mcp-ai-profession-cpt.php';
		require_once WP_MCP_AI_PATH . 'includes/repositories/class-wp-mcp-ai-profession-repository.php';

		// Create a test profession with datasets.
		$profession_data = array(
			'title'       => 'Test Update Profession',
			'slug'        => 'test_update_profession',
			'description' => 'Test description',
			'category'    => 'creative',
		);

		$repository = new WP_MCP_AI_Profession_Repository();
		$post_id    = $repository->save( $profession_data );

		// Add datasets.
		$test_datasets = array(
			array(
				'dataset'  => 'detection-datasets/coco',
				'name'     => 'COCO',
				'category' => 'vision',
				'priority' => 'critical',
			),
		);

		update_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_PREFERRED_DATASETS, $test_datasets );

		// Get existing datasets before update.
		$existing_datasets = get_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_PREFERRED_DATASETS, true );

		// Update the profession with new data (simulating update from JSON).
		$updated_data = array(
			'id'          => $post_id,
			'title'       => 'Updated Test Profession',
			'slug'        => 'test_update_profession',
			'description' => 'Updated description',
			'category'    => 'creative',
		);

		// If datasets are not in the updated data, preserve them.
		if ( ! isset( $updated_data['preferred_datasets'] ) && ! empty( $existing_datasets ) ) {
			$updated_data['preferred_datasets'] = $existing_datasets;
		}

		$result = $repository->save( $updated_data );
		$this->assertIsInt( $result, 'Update should be successful' );

		// Verify datasets were preserved.
		$preserved_datasets = get_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_PREFERRED_DATASETS, true );
		$this->assertEquals( $test_datasets, $preserved_datasets, 'Datasets should be preserved during update' );
	}

	/**
	 * Test preservation of multiple professions with different datasets.
	 */
	public function test_multiple_professions_dataset_preservation() {
		// Load required classes.
		require_once WP_MCP_AI_PATH . 'includes/professions/class-wp-mcp-ai-profession-cpt.php';
		require_once WP_MCP_AI_PATH . 'includes/repositories/class-wp-mcp-ai-profession-repository.php';

		$repository = new WP_MCP_AI_Profession_Repository();

		// Create multiple professions with different datasets.
		$professions = array(
			array(
				'data'     => array(
					'title'    => 'Profession A',
					'slug'     => 'profession_a',
					'category' => 'technical',
				),
				'datasets' => array(
					array(
						'dataset'  => 'rajpurkar/squad',
						'name'     => 'SQuAD',
						'category' => 'nlp',
						'priority' => 'critical',
					),
				),
			),
			array(
				'data'     => array(
					'title'    => 'Profession B',
					'slug'     => 'profession_b',
					'category' => 'creative',
				),
				'datasets' => array(
					array(
						'dataset'  => 'detection-datasets/coco',
						'name'     => 'COCO',
						'category' => 'vision',
						'priority' => 'critical',
					),
				),
			),
		);

		$profession_ids = array();

		// Create professions and assign datasets.
		foreach ( $professions as $profession ) {
			$post_id                                       = $repository->save( $profession['data'] );
			$profession_ids[ $profession['data']['slug'] ] = $post_id;
			update_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_PREFERRED_DATASETS, $profession['datasets'] );
		}

		// Preserve all datasets.
		$preserved_datasets = array();
		$existing_posts     = get_posts(
			array(
				'post_type'      => 'mcp_ai_profession',
				'posts_per_page' => -1,
				'post_status'    => 'any',
			)
		);

		foreach ( $existing_posts as $post ) {
			$datasets = get_post_meta( $post->ID, WP_MCP_AI_Profession_CPT::META_PREFERRED_DATASETS, true );
			if ( ! empty( $datasets ) && is_array( $datasets ) ) {
				$preserved_datasets[ $post->post_name ] = $datasets;
			}
		}

		// Verify all datasets are preserved.
		$this->assertCount( 2, $preserved_datasets, 'Should preserve datasets for both professions' );
		$this->assertArrayHasKey( 'profession_a', $preserved_datasets );
		$this->assertArrayHasKey( 'profession_b', $preserved_datasets );
		$this->assertEquals( $professions[0]['datasets'], $preserved_datasets['profession_a'] );
		$this->assertEquals( $professions[1]['datasets'], $preserved_datasets['profession_b'] );
	}
}
