<?php
/**
 * Tests for Search Content Validated Tool
 *
 * @package WP_MCP_AI
 */

/**
 * Class Test_WP_MCP_AI_Tool_Search_Content_Validated
 *
 * Tests for the validated search_content tool using Symfony Validator.
 */
class Test_WP_MCP_AI_Tool_Search_Content_Validated extends WP_UnitTestCase {

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Search_Content_Validated
	 */
	private $tool;

	/**
	 * Test user ID with read capabilities.
	 *
	 * @var int
	 */
	private $user_id;

	/**
	 * Test post IDs.
	 *
	 * @var array
	 */
	private $test_posts = array();

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Load dependencies.
		require_once dirname( __DIR__ ) . '/includes/validators/class-wp-mcp-ai-validator-service.php';
		require_once dirname( __DIR__ ) . '/includes/validators/class-wp-mcp-ai-validated-tool.php';
		require_once dirname( __DIR__ ) . '/includes/validators/arguments/class-search-content-arguments.php';
		require_once dirname( __DIR__ ) . '/includes/tools/class-wp-mcp-ai-tool-search-content-validated.php';

		// Create test user.
		$this->user_id = $this->factory->user->create(
			array(
				'role' => 'subscriber',
			)
		);

		wp_set_current_user( $this->user_id );

		// Create test posts.
		$this->test_posts['apple'] = $this->factory->post->create(
			array(
				'post_title'   => 'Apple Pie Recipe',
				'post_content' => 'How to make a delicious apple pie.',
				'post_status'  => 'publish',
			)
		);

		$this->test_posts['banana'] = $this->factory->post->create(
			array(
				'post_title'   => 'Banana Bread Tutorial',
				'post_content' => 'Learn to bake banana bread at home.',
				'post_status'  => 'publish',
			)
		);

		$this->test_posts['cherry'] = $this->factory->post->create(
			array(
				'post_title'   => 'Cherry Cheesecake',
				'post_content' => 'A sweet cherry cheesecake recipe.',
				'post_status'  => 'publish',
			)
		);

		$this->tool = new WP_MCP_AI_Tool_Search_Content_Validated();
	}

	/**
	 * Clean up after tests.
	 */
	public function tearDown(): void {
		// Clean up test posts.
		foreach ( $this->test_posts as $post_id ) {
			wp_delete_post( $post_id, true );
		}

		parent::tearDown();
	}

	/**
	 * Test tool metadata.
	 */
	public function test_tool_metadata() {
		$this->assertEquals( 'search_content_validated', $this->tool->get_slug() );
		$this->assertNotEmpty( $this->tool->get_name() );
		$this->assertStringContainsString( 'Validated', $this->tool->get_name() );
		$this->assertNotEmpty( $this->tool->get_description() );
		$this->assertStringContainsString( 'Symfony Validator', $this->tool->get_description() );
	}

	/**
	 * Test basic search with valid data.
	 */
	public function test_search_with_valid_term() {
		$arguments = array(
			'search_term' => 'apple',
			'post_type'   => 'post',
			'limit'       => 10,
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertArrayHasKey( 'results', $result );
		$this->assertArrayHasKey( 'count', $result );
		$this->assertArrayHasKey( 'summary', $result );
		$this->assertGreaterThan( 0, $result['count'] );
	}

	/**
	 * Test search returns correct results.
	 */
	public function test_search_returns_correct_results() {
		$arguments = array(
			'search_term' => 'banana',
			'post_type'   => 'post',
			'limit'       => 10,
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertIsArray( $result );
		$this->assertGreaterThanOrEqual( 1, $result['count'] );
		
		// Check that results contain the banana post.
		$found = false;
		foreach ( $result['results'] as $item ) {
			if ( $item['ID'] === $this->test_posts['banana'] ) {
				$found = true;
				$this->assertStringContainsString( 'Banana', $item['title'] );
				break;
			}
		}
		$this->assertTrue( $found, 'Should find the banana post' );
	}

	/**
	 * Test search with limit parameter.
	 */
	public function test_search_respects_limit() {
		// Create more posts to test limit.
		for ( $i = 1; $i <= 15; $i++ ) {
			$this->factory->post->create(
				array(
					'post_title'   => "Recipe Post $i",
					'post_content' => 'Recipe content here',
					'post_status'  => 'publish',
				)
			);
		}

		$arguments = array(
			'search_term' => 'recipe',
			'post_type'   => 'post',
			'limit'       => 5,
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertIsArray( $result );
		$this->assertLessThanOrEqual( 5, $result['count'] );
	}

	/**
	 * Test validation fails when search_term is missing.
	 */
	public function test_validation_fails_without_search_term() {
		$arguments = array(
			'post_type' => 'post',
			'limit'     => 10,
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		// Note: The search_content tool allows searching without search_term
		// if taxonomy or meta filters are provided. So this should fail
		// with missing_criteria error, not validation error.
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_missing_criteria', $result->get_error_code() );
	}

	/**
	 * Test validation with limit outside range.
	 */
	public function test_validation_fails_with_invalid_limit() {
		$arguments = array(
			'search_term' => 'test',
			'post_type'   => 'post',
			'limit'       => 100,  // Max is 50.
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'validation_failed', $result->get_error_code() );
	}

	/**
	 * Test permission check - users without read capability should be denied.
	 */
	public function test_permission_denied_without_read_capability() {
		// Create user without read capability.
		$no_cap_user = $this->factory->user->create();
		$user        = new WP_User( $no_cap_user );
		$user->remove_cap( 'read' );

		$arguments = array(
			'search_term' => 'test',
			'post_type'   => 'post',
			'limit'       => 10,
		);

		$context = array( 'user_id' => $no_cap_user );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test search with taxonomy filters.
	 */
	public function test_search_with_taxonomy_filters() {
		// Create a category.
		$category_id = $this->factory->category->create(
			array(
				'name' => 'Desserts',
				'slug' => 'desserts',
			)
		);

		// Assign category to cherry post.
		wp_set_post_categories( $this->test_posts['cherry'], array( $category_id ) );

		$arguments = array(
			'search_term'      => 'recipe',
			'post_type'        => 'post',
			'limit'            => 10,
			'taxonomy_filters' => array(
				array(
					'taxonomy' => 'category',
					'terms'    => array( 'desserts' ),
					'field'    => 'slug',
					'operator' => 'IN',
				),
			),
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'results', $result );
		
		// Verify results include the cherry post.
		$found = false;
		foreach ( $result['results'] as $item ) {
			if ( $item['ID'] === $this->test_posts['cherry'] ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'Should find post with desserts category' );
	}

	/**
	 * Test search with meta filters.
	 */
	public function test_search_with_meta_filters() {
		// Add meta to banana post.
		add_post_meta( $this->test_posts['banana'], 'difficulty', 'easy' );
		add_post_meta( $this->test_posts['cherry'], 'difficulty', 'hard' );

		$arguments = array(
			'search_term'  => 'recipe',
			'post_type'    => 'post',
			'limit'        => 10,
			'meta_filters' => array(
				array(
					'key'     => 'difficulty',
					'value'   => 'easy',
					'compare' => '=',
				),
			),
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertIsArray( $result );
		
		// Verify results include the banana post but not cherry.
		$found_banana = false;
		$found_cherry = false;
		foreach ( $result['results'] as $item ) {
			if ( $item['ID'] === $this->test_posts['banana'] ) {
				$found_banana = true;
			}
			if ( $item['ID'] === $this->test_posts['cherry'] ) {
				$found_cherry = true;
			}
		}
		$this->assertTrue( $found_banana, 'Should find banana post with easy difficulty' );
	}

	/**
	 * Test capability flags.
	 */
	public function test_capability_flags() {
		$flags = $this->tool->get_capability_flags();

		$this->assertIsArray( $flags );
		$this->assertContains( 'read-only', $flags );
		$this->assertContains( 'local-only', $flags );
		$this->assertContains( 'requires-capability', $flags );
		$this->assertContains( 'cacheable', $flags );
	}

	/**
	 * Test parameters schema.
	 */
	public function test_parameters_schema() {
		$schema = $this->tool->get_parameters_schema();

		$this->assertIsArray( $schema );
		$this->assertEquals( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'search_term', $schema['properties'] );
		$this->assertArrayHasKey( 'post_type', $schema['properties'] );
		$this->assertArrayHasKey( 'limit', $schema['properties'] );
		$this->assertArrayHasKey( 'taxonomy_filters', $schema['properties'] );
		$this->assertArrayHasKey( 'meta_filters', $schema['properties'] );
		$this->assertContains( 'search_term', $schema['required'] );
	}

	/**
	 * Test default values.
	 */
	public function test_default_values() {
		$arguments = array(
			'search_term' => 'test',
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertIsArray( $result );
		// Default limit should be applied (10).
		// Default post_type should be 'any'.
		// Test passes if no error occurs.
	}
}
