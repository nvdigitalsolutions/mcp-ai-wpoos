<?php
/**
 * Test: Email Template CPT — prefixes, storage and legacy migration.
 *
 * @package NV_oOS_Design_System
 */

/**
 * Tests for NV_oOS_Design_System_Email_Template_CPT.
 *
 * @covers NV_oOS_Design_System_Email_Template_CPT
 */
class Test_Email_Template_CPT extends WP_UnitTestCase {

	/**
	 * Register the post type and clear the migration guard.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		NV_oOS_Design_System_Email_Template_CPT::register();
		delete_option( 'nvoos_nds_cpt_migrated' );
	}

	/**
	 * Clean up migration state.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		delete_option( 'nvoos_nds_cpt_migrated' );
		parent::tearDown();
	}

	/**
	 * Every stored identifier is prefixed for the WordPress.org guidelines.
	 *
	 * @return void
	 */
	public function test_identifiers_are_prefixed() {
		$this->assertSame( 'nvoos_nds_email', NV_oOS_Design_System_Email_Template_CPT::POST_TYPE );
		$this->assertStringStartsWith( '_nvoos_nds_', NV_oOS_Design_System_Email_Template_CPT::META_SOURCE );
		$this->assertStringStartsWith( '_nvoos_nds_', NV_oOS_Design_System_Email_Template_CPT::META_SCOPE );
		$this->assertStringStartsWith( '_nvoos_nds_', NV_oOS_Design_System_Email_Template_CPT::META_SETTINGS );
	}

	/**
	 * The registered post type is private and not REST-exposed.
	 *
	 * @return void
	 */
	public function test_post_type_is_private() {
		$object = get_post_type_object( NV_oOS_Design_System_Email_Template_CPT::POST_TYPE );

		$this->assertNotNull( $object );
		$this->assertFalse( $object->public );
		$this->assertFalse( $object->show_ui );
		$this->assertFalse( $object->show_in_rest );
	}

	/**
	 * Unsafe HTML never reaches the database.
	 *
	 * @return void
	 */
	public function test_upsert_rejects_unsafe_html() {
		$result = NV_oOS_Design_System_Email_Template_CPT::upsert(
			'unsafe-test',
			'Unsafe',
			'<p>hi</p><script>alert(1)</script>'
		);

		$this->assertWPError( $result );
		$this->assertSame( 'nds_email_unsafe_html', $result->get_error_code() );
		$this->assertNull( NV_oOS_Design_System_Email_Template_CPT::get_by_slug( 'unsafe-test' ) );
	}

	/**
	 * A safe template is stored with the prefixed meta keys.
	 *
	 * @return void
	 */
	public function test_upsert_stores_prefixed_meta() {
		$post_id = NV_oOS_Design_System_Email_Template_CPT::upsert(
			'safe-test',
			'Safe template',
			'<p>Hello {{to_name}}</p>',
			'custom',
			'body',
			array( 'logo_url' => 'https://example.com/logo.png' ),
			'draft'
		);

		$this->assertIsInt( $post_id );
		$this->assertSame( 'nvoos_nds_email', get_post_type( $post_id ) );
		$this->assertSame( 'draft', get_post_status( $post_id ) );
		$this->assertSame( 'custom', get_post_meta( $post_id, '_nvoos_nds_template_source', true ) );
		$this->assertSame( 'body', get_post_meta( $post_id, '_nvoos_nds_template_scope', true ) );
		$this->assertSame( 'https://example.com/logo.png', NV_oOS_Design_System_Email_Template_CPT::get_settings( $post_id )['logo_url'] );
	}

	/**
	 * A full document round-trips intact even without `unfiltered_html`.
	 *
	 * KSES strips `<style>`, VML and the doctype, which would silently reduce
	 * a built-in template to a broken fragment on front-end requests, cron
	 * and WP-CLI. The seeder runs on `init`, so this is the common path.
	 *
	 * @return void
	 */
	public function test_upsert_preserves_email_markup_without_unfiltered_html() {
		wp_set_current_user( 0 );

		$html = '<!DOCTYPE html><html lang="en"><head><title>{{subject}}</title>'
			. '<style>@media (prefers-color-scheme: dark){.x{color:#fff}}</style></head>'
			. '<body><!--[if mso]><v:rect xmlns:v="urn:schemas-microsoft-com:vml"></v:rect><![endif]-->'
			. '<p>Hello {{to_name}}</p></body></html>';

		$post_id = NV_oOS_Design_System_Email_Template_CPT::upsert( 'full-document', 'Full document', $html, 'builtin', 'full', array(), 'publish' );

		$this->assertIsInt( $post_id );

		$stored = get_post( $post_id )->post_content;

		$this->assertStringContainsString( '<!DOCTYPE html>', $stored );
		$this->assertStringContainsString( '<style>', $stored );
		$this->assertStringContainsString( 'v:rect', $stored );
		$this->assertStringContainsString( 'prefers-color-scheme', $stored );

		// KSES is restored: unsafe markup is still stripped afterwards.
		$stripped = wp_kses_post( '<p>ok</p><script>alert(1)</script>' );
		$this->assertStringNotContainsString( '<script', $stripped );
	}

	/**
	 * Legacy posts and meta are renamed exactly once.
	 *
	 * @return void
	 */
	public function test_migrate_legacy_renames_type_and_meta() {
		$legacy_id = wp_insert_post(
			array(
				'post_name'    => 'legacy-template',
				'post_title'   => 'Legacy template',
				'post_content' => '<p>legacy</p>',
				'post_status'  => 'publish',
				'post_type'    => 'nds_email_template',
			)
		);

		update_post_meta( $legacy_id, '_nds_template_source', 'builtin' );
		update_post_meta( $legacy_id, '_nds_template_scope', 'full' );

		NV_oOS_Design_System_Email_Template_CPT::migrate_legacy();

		$this->assertSame( 'nvoos_nds_email', get_post_type( $legacy_id ) );
		$this->assertSame( 'builtin', get_post_meta( $legacy_id, '_nvoos_nds_template_source', true ) );
		$this->assertSame( 'full', get_post_meta( $legacy_id, '_nvoos_nds_template_scope', true ) );
		$this->assertFalse( metadata_exists( 'post', $legacy_id, '_nds_template_source' ) );
		$this->assertSame( 1, (int) get_option( 'nvoos_nds_cpt_migrated' ) );

		// Idempotent: a second run is a no-op.
		NV_oOS_Design_System_Email_Template_CPT::migrate_legacy();
		$this->assertSame( 1, (int) get_option( 'nvoos_nds_cpt_migrated' ) );
	}

	/**
	 * Existing prefixed values are not overwritten by the migration.
	 *
	 * @return void
	 */
	public function test_migrate_legacy_does_not_overwrite_new_meta() {
		$legacy_id = wp_insert_post(
			array(
				'post_name'    => 'legacy-template-2',
				'post_title'   => 'Legacy template 2',
				'post_content' => '<p>legacy</p>',
				'post_status'  => 'publish',
				'post_type'    => 'nds_email_template',
			)
		);

		update_post_meta( $legacy_id, '_nds_template_source', 'builtin' );
		update_post_meta( $legacy_id, '_nvoos_nds_template_source', 'custom' );

		NV_oOS_Design_System_Email_Template_CPT::migrate_legacy();

		$this->assertSame( 'custom', get_post_meta( $legacy_id, '_nvoos_nds_template_source', true ) );
	}
}
