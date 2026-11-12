<?php
/**
 * The profession write path: core SaveProfessionTool + WordPress adapter.
 *
 * Regression for the stub that reported "Profession saved." and wrote nothing.
 * The tool must reach a real write here, and an update must not duplicate the
 * post or clear meta the caller did not send.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

use Nvoos\Core\Domain\Contract\ProfessionWriteInterface;
use Nvoos\Core\Tool\SaveProfessionTool;
use Nvoos\WordPress\Adapter\ErrorFactory;
use Nvoos\WordPress\Adapter\ProfessionRepository;

/**
 * Regression tests for the profession write path.
 *
 * @covers \Nvoos\Core\Tool\SaveProfessionTool
 * @covers \Nvoos\WordPress\Adapter\ProfessionRepository::save
 */
class Test_Profession_Write_Adapter extends WP_UnitTestCase {

	/**
	 * Adapter under test.
	 *
	 * @var ProfessionRepository
	 */
	private $repo;

	/**
	 * Slugs created by the test, deleted in tear_down().
	 *
	 * @var string[]
	 */
	private $slugs = array();

	/**
	 * Set up an administrator and the adapter under test.
	 */
	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->repo = new ProfessionRepository();
	}

	/**
	 * Remove the professions this test created.
	 */
	public function tear_down() {
		foreach ( $this->slugs as $slug ) {
			$posts = get_posts(
				array(
					'name'        => $slug,
					'post_type'   => WP_MCP_AI_Profession_CPT::POST_TYPE,
					'post_status' => 'any',
					'numberposts' => 1,
				)
			);

			foreach ( $posts as $post ) {
				wp_delete_post( $post->ID, true );
			}
		}

		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * The adapter must advertise the optional write capability.
	 */
	public function test_the_adapter_exposes_the_optional_write_contract() {
		$this->assertInstanceOf( ProfessionWriteInterface::class, $this->repo );
	}

	/**
	 * Creating a profession writes the post and every supplied meta field.
	 */
	public function test_it_creates_a_profession_with_meta() {
		$slug = $this->track( 'test_marine_biologist' );

		$result = $this->repo->save(
			array(
				'slug'          => $slug,
				'title'         => 'Marine Biologist',
				'description'   => 'Studies marine life.',
				'category'      => 'technical',
				'expertise'     => array( 'Taxonomy', 'Fieldwork' ),
				'default_tools' => array( 'web_search' ),
			)
		);

		$this->assertTrue( $result['saved'], 'The write must report success.' );
		$this->assertSame( 'created', $result['action'] );

		$post = $this->find_by_slug( $slug );
		$this->assertInstanceOf( WP_Post::class, $post, 'The profession post must exist.' );
		$this->assertSame( 'Marine Biologist', $post->post_title );
		$this->assertSame( 'technical', get_post_meta( $post->ID, WP_MCP_AI_Profession_CPT::META_CATEGORY, true ) );
		$this->assertSame( array( 'Taxonomy', 'Fieldwork' ), get_post_meta( $post->ID, WP_MCP_AI_Profession_CPT::META_EXPERTISE, true ) );
		$this->assertSame( array( 'web_search' ), get_post_meta( $post->ID, WP_MCP_AI_Profession_CPT::META_DEFAULT_TOOLS, true ) );
	}

	/**
	 * An update reuses the post and leaves omitted meta untouched.
	 */
	public function test_it_updates_without_duplicating_and_keeps_omitted_meta() {
		$slug = $this->track( 'test_update_role' );

		$first = $this->repo->save(
			array(
				'slug'      => $slug,
				'title'     => 'First Title',
				'expertise' => array( 'Taxonomy' ),
			)
		);
		$this->assertSame( 'created', $first['action'] );

		// Only the title is sent: expertise must survive, and no second post
		// may be created.
		$second = $this->repo->save(
			array(
				'slug'  => $slug,
				'title' => 'Second Title',
			)
		);

		$this->assertTrue( $second['saved'] );
		$this->assertSame( 'updated', $second['action'] );

		$posts = get_posts(
			array(
				'name'        => $slug,
				'post_type'   => WP_MCP_AI_Profession_CPT::POST_TYPE,
				'post_status' => 'any',
				'numberposts' => -1,
			)
		);

		$this->assertCount( 1, $posts, 'An update must not create a second post.' );
		$this->assertSame( 'Second Title', $posts[0]->post_title );
		$this->assertSame(
			array( 'Taxonomy' ),
			get_post_meta( $posts[0]->ID, WP_MCP_AI_Profession_CPT::META_EXPERTISE, true ),
			'Omitted fields must not be cleared.'
		);
	}

	/**
	 * A saved profession is visible through the read contract.
	 */
	public function test_the_saved_profession_is_readable_through_the_adapter() {
		$slug = $this->track( 'test_read_back' );

		$this->repo->save(
			array(
				'slug'  => $slug,
				'title' => 'Read Back Role',
			)
		);

		$found = $this->repo->getBySlug( $slug );

		$this->assertTrue( $found['found'] );
		$this->assertSame( 'Read Back Role', $found['profession']['name'] );
	}

	/**
	 * The core tool persists through the adapter — the bug this guards.
	 */
	public function test_the_core_tool_writes_through_the_adapter() {
		$slug = $this->track( 'test_tool_role' );

		$tool   = new SaveProfessionTool( new ErrorFactory(), $this->repo );
		$result = $tool->execute(
			array(
				'slug'      => $slug,
				'title'     => 'Tool Role',
				'category'  => 'technical',
				'expertise' => array( 'Testing' ),
			)
		);

		$this->assertIsArray( $result, 'The tool must not return a stub payload.' );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'created', $result['data']['action'] );

		$post = $this->find_by_slug( $slug );

		$this->assertInstanceOf( WP_Post::class, $post, 'The tool must actually persist the profession.' );
		$this->assertSame( array( 'Testing' ), get_post_meta( $post->ID, WP_MCP_AI_Profession_CPT::META_EXPERTISE, true ) );
	}

	/**
	 * Missing required fields return a validation error, not a fake success.
	 */
	public function test_the_core_tool_rejects_a_missing_title() {
		$tool   = new SaveProfessionTool( new ErrorFactory(), $this->repo );
		$result = $tool->execute( array( 'slug' => 'test_missing_title' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'validation_failed', $result->get_error_code() );
	}

	/**
	 * Register a slug for cleanup.
	 *
	 * @param string $slug Profession slug.
	 * @return string
	 */
	private function track( $slug ) {
		$this->slugs[] = $slug;

		return $slug;
	}

	/**
	 * Find a profession post by slug.
	 *
	 * @param string $slug Profession slug.
	 * @return WP_Post|null
	 */
	private function find_by_slug( $slug ) {
		$posts = get_posts(
			array(
				'name'        => $slug,
				'post_type'   => WP_MCP_AI_Profession_CPT::POST_TYPE,
				'post_status' => 'any',
				'numberposts' => 1,
			)
		);

		return $posts ? $posts[0] : null;
	}
}
