<?php
/**
 * Test: Token Registry.
 *
 * @package NV_oOS_Design_System
 */

use PHPUnit\Framework\TestCase;

/**
 * Tests for the Token_Registry class.
 *
 * @covers NV_oOS_Design_System_Token_Registry
 * @covers NV_oOS_Design_System_Data_Token
 * @covers NV_oOS_Design_System_Preset_Minimal
 */
class Test_Token_Registry extends TestCase {

	/**
	 * Token registry instance.
	 *
	 * @var NV_oOS_Design_System_Token_Registry
	 */
	private $registry;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->registry = new NV_oOS_Design_System_Token_Registry();
	}

	/**
	 * Registry should contain at least 40 tokens.
	 *
	 * @return void
	 */
	public function test_registry_contains_all_tokens() {
		$tokens = $this->registry->get_all();
		$this->assertNotEmpty( $tokens );
		$this->assertGreaterThan( 40, count( $tokens ), 'Registry should contain at least 40 tokens.' );
	}

	/**
	 * Should be able to retrieve a token by its ID.
	 *
	 * @return void
	 */
	public function test_get_token_by_id() {
		$token = $this->registry->get( 'color_surface' );
		$this->assertNotNull( $token );
		$this->assertSame( '#1a1a1a', $token->value );
	}

	/**
	 * Getting a non-existent token should return null.
	 *
	 * @return void
	 */
	public function test_get_nonexistent_token() {
		$this->assertNull( $this->registry->get( 'nonexistent' ) );
	}

	/**
	 * Setting a token value should update and mark it as modified.
	 *
	 * @return void
	 */
	public function test_set_token_value() {
		$result = $this->registry->set( 'color_surface', '#ff0000' );
		$this->assertTrue( $result );

		$token = $this->registry->get( 'color_surface' );
		$this->assertSame( '#ff0000', $token->value );
		$this->assertTrue( $token->is_modified() );
	}

	/**
	 * Setting a non-existent token should return false.
	 *
	 * @return void
	 */
	public function test_set_nonexistent_token() {
		$result = $this->registry->set( 'nonexistent', '#ff0000' );
		$this->assertFalse( $result );
	}

	/**
	 * CSS variable name should use the --nds-{group}-{id} format.
	 *
	 * @return void
	 */
	public function test_css_var_format() {
		$token = $this->registry->get( 'color_surface' );
		$this->assertSame( '--nds-color-surface', $token->css_var() );
	}

	/**
	 * Email tokens should use the --nds-email- prefix.
	 *
	 * @return void
	 */
	public function test_email_token_css_var_format() {
		$token = $this->registry->get( 'email_page_bg' );
		$this->assertNotNull( $token );
		$this->assertSame( '--nds-email-page-bg', $token->css_var() );
	}

	/**
	 * Reset all should restore every token to its factory default.
	 *
	 * @return void
	 */
	public function test_reset_all() {
		$this->registry->set( 'color_surface', '#ff0000' );
		$this->registry->reset_all();

		$token = $this->registry->get( 'color_surface' );
		$this->assertSame( '#1a1a1a', $token->value );
		$this->assertFalse( $token->is_modified() );
	}

	/**
	 * Tokens should be grouped by category.
	 *
	 * @return void
	 */
	public function test_get_grouped() {
		$grouped = $this->registry->get_grouped();
		$this->assertArrayHasKey( 'colors', $grouped );
		$this->assertArrayHasKey( 'typography', $grouped );
		$this->assertArrayHasKey( 'spacing', $grouped );
		$this->assertArrayHasKey( 'borders', $grouped );
		$this->assertArrayHasKey( 'emails', $grouped );
	}

	/**
	 * Should return a flat ID => value array.
	 *
	 * @return void
	 */
	public function test_get_values_map() {
		$map = $this->registry->get_values_map();
		$this->assertIsArray( $map );
		$this->assertArrayHasKey( 'color_surface', $map );
		$this->assertSame( '#1a1a1a', $map['color_surface'] );
	}

	/**
	 * The email group values map should contain the palette.
	 *
	 * @return void
	 */
	public function test_get_group_values_emails() {
		$emails = $this->registry->get_group_values( 'emails' );
		$this->assertArrayHasKey( 'email_page_bg', $emails );
		$this->assertArrayHasKey( 'email_card_bg_dark', $emails );
		$this->assertGreaterThan( 10, count( $emails ) );
	}

	/**
	 * Applying the Ecommerce preset should override token values.
	 *
	 * @return void
	 */
	public function test_apply_ecommerce_preset() {
		$this->registry->apply_preset( 'NV_oOS_Design_System_Preset_Ecommerce' );

		$token = $this->registry->get( 'color_surface' );
		$this->assertSame( '#ffffff', $token->value, 'Ecommerce preset should set surface to white.' );
	}

	/**
	 * Applying a preset should also restyle the email palette.
	 *
	 * @return void
	 */
	public function test_apply_preset_updates_email_palette() {
		$this->registry->apply_preset( 'NV_oOS_Design_System_Preset_Directory' );

		$token = $this->registry->get( 'email_page_bg' );
		$this->assertSame( '#eef0f3', $token->value, 'Directory preset should set the email page background.' );
	}

	/**
	 * Hex color values should be sanitized correctly.
	 *
	 * @return void
	 */
	public function test_sanitize_color_hex() {
		$this->registry->set( 'color_surface', '#ff0000' );
		$this->assertSame( '#ff0000', $this->registry->get( 'color_surface' )->value );
	}

	/**
	 * CSS var() references should be preserved during sanitization.
	 *
	 * @return void
	 */
	public function test_sanitize_color_var_reference() {
		$this->registry->set( 'color_surface', 'var(--e-global-color-primary)' );
		$this->assertSame( 'var(--e-global-color-primary)', $this->registry->get( 'color_surface' )->value );
	}

	/**
	 * Size values with units should pass sanitization.
	 *
	 * @return void
	 */
	public function test_sanitize_size_with_unit() {
		$this->registry->set( 'space_md', '24px' );
		$this->assertSame( '24px', $this->registry->get( 'space_md' )->value );
	}
}
