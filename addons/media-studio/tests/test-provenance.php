<?php
/**
 * Provenance & Compliance tests.
 *
 * Covers IPTC 2025.1 field building from attachment meta, XMP embedding via
 * record()/record_derived(), the best-effort C2PA signing round-trip
 * (mocked transport), and the compliance capability block.
 *
 * @package NV_oOS_Media_Studio
 */
class Test_Media_Studio_Provenance extends WP_UnitTestCase {

	/**
	 * Set up test.
	 */
	public function setUp(): void {
		parent::setUp();
		if ( ! defined( 'NVOOS_MEDIA_STUDIO_VERSION' ) ) {
			define( 'NVOOS_MEDIA_STUDIO_VERSION', '0.5.0' );
		}
		require_once dirname( __DIR__ ) . '/includes/ai/class-nvoos-media-studio-ai-service.php';
		require_once dirname( __DIR__ ) . '/includes/ai/class-nvoos-media-studio-output-pipeline.php';
		require_once dirname( __DIR__ ) . '/includes/ai/class-nvoos-media-studio-xmp-writer.php';
		require_once dirname( __DIR__ ) . '/includes/ai/class-nvoos-media-studio-provenance.php';

		delete_option( NV_oOS_Media_Studio_AI_Service::OPTION_KEY );
		remove_all_filters( 'nvoos_media_studio_xmp_fields' );
	}

	/**
	 * Tear down test.
	 */
	public function tearDown(): void {
		delete_option( NV_oOS_Media_Studio_AI_Service::OPTION_KEY );
		remove_all_filters( 'nvoos_media_studio_xmp_fields' );
		parent::tearDown();
	}

	/**
	 * Create a PNG attachment with provenance meta.
	 *
	 * @param array $meta Meta to write.
	 * @return int Attachment ID.
	 */
	private function create_ai_attachment( $meta = array() ) {
		$img = imagecreatetruecolor( 40, 40 );
		imagefilledrectangle( $img, 0, 0, 40, 40, imagecolorallocate( $img, 210, 210, 210 ) );
		ob_start();
		imagepng( $img );
		$bytes = ob_get_clean();
		imagedestroy( $img );

		$upload        = wp_upload_bits( 'nvoos-prov-test-' . uniqid() . '.png', null, $bytes );
		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/png',
				'post_title'     => 'Provenance Fixture',
				'post_status'    => 'inherit',
			),
			$upload['file']
		);

		$defaults = array(
			'_nvoos_ai_generated'   => 1,
			'_nvoos_ai_provider'    => 'gemini',
			'_nvoos_ai_model'       => 'nano-banana',
			'_nvoos_ai_transform'   => 'background',
			'_nvoos_ai_prompt_hash' => 'abc123',
		);
		foreach ( array_merge( $defaults, $meta ) as $key => $value ) {
			update_post_meta( $attachment_id, $key, $value );
		}

		return $attachment_id;
	}

	/**
	 * Test build_fields derives the IPTC 2025.1 set from attachment meta.
	 */
	public function test_build_fields_from_meta() {
		$attachment_id = $this->create_ai_attachment();

		$fields = NV_oOS_Media_Studio_Provenance::build_fields( $attachment_id, 0 );

		$this->assertSame( NV_oOS_Media_Studio_XMP_Writer::DST_TRAINED_ALGORITHMIC, $fields['DigitalSourceType'] );
		$this->assertSame( 'gemini', $fields['AISystemUsed'] );
		$this->assertSame( 'nano-banana', $fields['AISystemVersionUsed'] );
		$this->assertSame( 'background', $fields['photoshop:Source'] );
		$this->assertArrayNotHasKey( 'AIPromptInformation', $fields ); // Privacy default.
	}

	/**
	 * Test record() embeds XMP and reports C2PA as unconfigured.
	 */
	public function test_record_embeds_xmp() {
		$attachment_id = $this->create_ai_attachment();

		$result = NV_oOS_Media_Studio_Provenance::record( $attachment_id, 0 );

		$this->assertTrue( $result['xmp_embedded'] );
		$this->assertFalse( $result['c2pa_signed'] );
		$this->assertSame( 'not_configured', $result['c2pa_reason'] );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- test reads local fixture.
		$data = file_get_contents( get_attached_file( $attachment_id ) );
		$this->assertStringContainsString( 'AISystemUsed', $data );
		$this->assertStringContainsString( 'gemini', $data );
	}

	/**
	 * Test record_derived marks compositeSynthetic and carries source AI fields.
	 */
	public function test_record_derived_carries_source_provenance() {
		$source  = $this->create_ai_attachment();
		$derived = $this->create_ai_attachment( array( '_nvoos_ai_generated' => 0 ) );
		update_post_meta( $derived, NV_oOS_Media_Studio_Output_Pipeline::META_DERIVED_FROM, $source );
		update_post_meta( $derived, NV_oOS_Media_Studio_Output_Pipeline::META_PROFILE, 'amazon' );

		$fields = NV_oOS_Media_Studio_Provenance::build_derived_fields( $derived, 0 );

		$this->assertSame( NV_oOS_Media_Studio_XMP_Writer::DST_COMPOSITE_SYNTHETIC, $fields['DigitalSourceType'] );
		$this->assertSame( 'gemini', $fields['AISystemUsed'] );
		$this->assertSame( 'amazon', $fields['photoshop:Source'] );
	}

	/**
	 * Test C2PA signing succeeds against a mocked signing service.
	 */
	public function test_c2pa_signing_success() {
		update_option(
			NV_oOS_Media_Studio_AI_Service::OPTION_KEY,
			array( 'c2pa_sign_url' => 'https://sign.example/c2pa' )
		);

		$attachment_id = $this->create_ai_attachment();

		add_filter(
			'pre_http_request',
			static function () {
				return array(
					'headers'  => array(),
					'body'     => wp_json_encode(
						array(
							'signed'   => true,
							'manifest' => 'c2pa-manifest-id',
						)
					),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => '',
				);
			},
			10,
			3
		);

		$result = NV_oOS_Media_Studio_Provenance::maybe_sign_c2pa( $attachment_id );

		remove_all_filters( 'pre_http_request' );

		$this->assertTrue( $result['signed'] );
		$this->assertSame( '1', get_post_meta( $attachment_id, NV_oOS_Media_Studio_Provenance::META_C2PA_SIGNED, true ) );
	}

	/**
	 * Test C2PA signing degrades gracefully when the service declines.
	 */
	public function test_c2pa_signing_declined() {
		update_option(
			NV_oOS_Media_Studio_AI_Service::OPTION_KEY,
			array( 'c2pa_sign_url' => 'https://sign.example/c2pa' )
		);

		$attachment_id = $this->create_ai_attachment();

		add_filter(
			'pre_http_request',
			static function () {
				return array(
					'headers'  => array(),
					'body'     => wp_json_encode( array( 'error' => 'key unavailable' ) ),
					'response' => array(
						'code'    => 500,
						'message' => 'Server Error',
					),
					'cookies'  => array(),
					'filename' => '',
				);
			},
			10,
			3
		);

		$result = NV_oOS_Media_Studio_Provenance::maybe_sign_c2pa( $attachment_id );

		remove_all_filters( 'pre_http_request' );

		$this->assertFalse( $result['signed'] );
		$this->assertSame( 'service_declined', $result['reason'] );
		$this->assertEmpty( get_post_meta( $attachment_id, NV_oOS_Media_Studio_Provenance::META_C2PA_SIGNED, true ) );
	}

	/**
	 * Test non-https signing URLs are never contacted.
	 */
	public function test_c2pa_requires_https() {
		update_option(
			NV_oOS_Media_Studio_AI_Service::OPTION_KEY,
			array( 'c2pa_sign_url' => 'http://insecure.example/c2pa' )
		);
		$this->assertFalse( NV_oOS_Media_Studio_Provenance::is_c2pa_configured() );

		$attachment_id = $this->create_ai_attachment();
		$result        = NV_oOS_Media_Studio_Provenance::maybe_sign_c2pa( $attachment_id );
		$this->assertFalse( $result['signed'] );
		$this->assertSame( 'not_configured', $result['reason'] );
	}

	/**
	 * Test the compliance block shape in capabilities.
	 */
	public function test_compliance_block_in_capabilities() {
		$capabilities = NV_oOS_Media_Studio_AI_Service::get_capabilities();
		$this->assertArrayHasKey( 'compliance', $capabilities );
		$this->assertArrayHasKey( 'disclosure', $capabilities['compliance'] );
		$this->assertArrayHasKey( 'xmp_support', $capabilities['compliance'] );
		$this->assertTrue( $capabilities['compliance']['xmp_support']['png'] );
		$this->assertArrayHasKey( 'c2pa_configured', $capabilities['compliance'] );
	}
}
