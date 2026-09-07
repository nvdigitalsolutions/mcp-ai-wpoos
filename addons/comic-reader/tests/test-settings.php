<?php
/**
 * NV oOS Comic Reader — Settings Tests
 *
 * @package NV_oOS_Comic_Reader
 * @since   0.5.0
 */

/**
 * Test the settings storage, sanitizer, and capability resolution.
 */
class Test_Comic_Reader_Settings extends WP_UnitTestCase {

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		delete_option( NV_oOS_Comic_Reader_Settings::OPTION_NAME );
	}

	/**
	 * Test defaults are returned when nothing is stored.
	 *
	 * @return void
	 */
	public function test_defaults_when_unset() {
		$this->assertEquals( 'ltr', NV_oOS_Comic_Reader_Settings::get( 'reader_direction' ) );
		$this->assertEquals( 'paged', NV_oOS_Comic_Reader_Settings::get( 'reader_mode' ) );
		$this->assertEquals( 256, NV_oOS_Comic_Reader_Settings::get( 'max_upload_mb' ) );
		$this->assertTrue( NV_oOS_Comic_Reader_Settings::progress_sync_enabled() );
		$this->assertTrue( NV_oOS_Comic_Reader_Settings::cover_extraction_enabled() );
	}

	/**
	 * Test stored values merge over defaults.
	 *
	 * @return void
	 */
	public function test_stored_values_merge_over_defaults() {
		update_option(
			NV_oOS_Comic_Reader_Settings::OPTION_NAME,
			array( 'reader_direction' => 'rtl' )
		);

		$this->assertEquals( 'rtl', NV_oOS_Comic_Reader_Settings::get( 'reader_direction' ) );
		$this->assertEquals( 'paged', NV_oOS_Comic_Reader_Settings::get( 'reader_mode' ) );
	}

	/**
	 * Test the sanitizer whitelists select values.
	 *
	 * @return void
	 */
	public function test_sanitizer_whitelists_values() {
		$clean = NV_oOS_Comic_Reader_Settings::sanitize_settings(
			array(
				'reader_direction' => 'diagonal',
				'reader_mode'      => 'webtoon',
				'max_upload_mb'    => '99999',
			)
		);

		$this->assertEquals( 'ltr', $clean['reader_direction'] );
		$this->assertEquals( 'webtoon', $clean['reader_mode'] );
		$this->assertEquals( 4096, $clean['max_upload_mb'] );
	}

	/**
	 * Test checkbox fields coerce to 0/1.
	 *
	 * @return void
	 */
	public function test_sanitizer_checkboxes() {
		$clean = NV_oOS_Comic_Reader_Settings::sanitize_settings(
			array(
				'progress_sync'    => 'on',
				'cover_extraction' => null,
			)
		);

		$this->assertSame( 1, $clean['progress_sync'] );
		$this->assertSame( 0, $clean['cover_extraction'] );
	}

	/**
	 * Test capability resolution: settings override beats the filter default.
	 *
	 * @return void
	 */
	public function test_capability_settings_override() {
		update_option(
			NV_oOS_Comic_Reader_Settings::OPTION_NAME,
			array( 'capability_read' => 'manage_options' )
		);

		$this->assertEquals( 'manage_options', NV_oOS_Comic_Reader_Settings::get_capability( 'read' ) );
		$this->assertEquals( 'upload_files', NV_oOS_Comic_Reader_Settings::get_capability( 'upload' ) );
	}

	/**
	 * Test capability resolution honors the filter when settings are blank.
	 *
	 * @return void
	 */
	public function test_capability_filter_default() {
		add_filter(
			'nvoos_comic_reader_delete_capability',
			function () {
				return 'manage_options';
			}
		);

		$this->assertEquals( 'manage_options', NV_oOS_Comic_Reader_Settings::get_capability( 'delete' ) );
	}

	/**
	 * Test the reader defaults payload shape for the SPA.
	 *
	 * @return void
	 */
	public function test_reader_defaults_payload() {
		$defaults = NV_oOS_Comic_Reader_Settings::get_reader_defaults();

		$this->assertArrayHasKey( 'direction', $defaults );
		$this->assertArrayHasKey( 'readingMode', $defaults );
		$this->assertArrayHasKey( 'scale', $defaults );
		$this->assertArrayHasKey( 'background', $defaults );
		$this->assertArrayHasKey( 'doublePage', $defaults );
		$this->assertArrayHasKey( 'transition', $defaults );
		$this->assertArrayHasKey( 'gestures', $defaults );
	}

	/**
	 * Test the shortcode falls back to the stored direction default.
	 *
	 * @return void
	 */
	public function test_shortcode_uses_settings_direction_default() {
		if ( class_exists( 'NV_oOS_Comic_Reader_Shortcode' ) ) {
			NV_oOS_Comic_Reader_Shortcode::register();
		}

		update_option(
			NV_oOS_Comic_Reader_Settings::OPTION_NAME,
			array( 'reader_direction' => 'rtl' )
		);

		$output = do_shortcode( '[nvoos_comic_reader]' );
		$this->assertStringContainsString(
			'&quot;direction&quot;:&quot;rtl&quot;',
			$output,
			'Shortcode should fall back to the settings default direction.'
		);

		// An explicit attribute still wins over the setting.
		$output = do_shortcode( '[nvoos_comic_reader direction="ltr"]' );
		$this->assertStringContainsString(
			'&quot;direction&quot;:&quot;ltr&quot;',
			$output,
			'Explicit attributes should override the settings default.'
		);
	}

	/**
	 * Test the settings-page capability gate.
	 *
	 * @return void
	 */
	public function test_page_requires_manage_options() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		ob_start();
		NV_oOS_Comic_Reader_Settings::render_page();
		$output = ob_get_clean();

		$this->assertEmpty( $output, 'Subscribers must not see the settings page output.' );
	}
}
