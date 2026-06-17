<?php
/**
 * NV oOS Comic Reader — PHPUnit Tests
 *
 * @package NV_oOS_Comic_Reader
 * @since   0.1.0
 */

/**
 * Test shortcode registration and rendering.
 */
class Test_Comic_Reader_Shortcode extends WP_UnitTestCase {

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		// Register the shortcode.
		if ( class_exists( 'NV_oOS_Comic_Reader_Shortcode' ) ) {
			NV_oOS_Comic_Reader_Shortcode::register();
		}
	}

	/**
	 * Test that the shortcode is registered.
	 *
	 * @return void
	 */
	public function test_shortcode_registered() {
		$this->assertTrue(
			shortcode_exists( 'nvoos_comic_reader' ),
			'Shortcode [nvoos_comic_reader] should be registered.'
		);
	}

	/**
	 * Test shortcode renders container div.
	 *
	 * @return void
	 */
	public function test_shortcode_renders_container() {
		$output = do_shortcode( '[nvoos_comic_reader]' );
		$this->assertStringContainsString(
			'nvoos-comic-reader-root',
			$output,
			'Shortcode output should contain the root container class.'
		);
		$this->assertStringContainsString(
			'data-config=',
			$output,
			'Shortcode output should contain data-config attribute.'
		);
	}

	/**
	 * Test shortcode with comic ID attribute.
	 *
	 * @return void
	 */
	public function test_shortcode_with_comic_id() {
		$output = do_shortcode( '[nvoos_comic_reader id="42"]' );
		$this->assertStringContainsString(
			'"comicId":42',
			$output,
			'Shortcode should pass comic ID in config.'
		);
	}

	/**
	 * Test shortcode sanitizes direction attribute.
	 *
	 * @return void
	 */
	public function test_shortcode_sanitizes_direction() {
		$output = do_shortcode( '[nvoos_comic_reader direction="rtl"]' );
		$this->assertStringContainsString(
			'"direction":"rtl"',
			$output,
			'Shortcode should accept valid direction values.'
		);
	}

	/**
	 * Test shortcode rejects invalid direction.
	 *
	 * @return void
	 */
	public function test_shortcode_rejects_invalid_direction() {
		$output = do_shortcode( '[nvoos_comic_reader direction="invalid"]' );
		$this->assertStringContainsString(
			'"direction":"ltr"',
			$output,
			'Shortcode should fall back to default direction for invalid values.'
		);
	}
}
