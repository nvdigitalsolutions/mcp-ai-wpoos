<?php
/**
 * Test: Token Registry.
 *
 * @package NV_oOS_Crocoblock_DS
 */

use PHPUnit\Framework\TestCase;

/**
 * Tests for the Token_Registry class.
 *
 * @covers NV_oOS_Crocoblock_DS_Token_Registry
 * @covers NV_oOS_Crocoblock_DS_Data_Token
 * @covers NV_oOS_Crocoblock_DS_Preset_Minimal
 */
class Test_Token_Registry extends TestCase {

	/**
	 * Token registry instance.
	 *
	 * @var NV_oOS_Crocoblock_DS_Token_Registry
	 */
	private $registry;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->registry = new NV_oOS_Crocoblock_DS_Token_Registry();
	}

	/**
	 * Registry should contain at least 30 tokens.
	 *
	 * @return void
	 */
	public function test_registry_contains_all_tokens() {
		$tokens = $this->registry->get_all();
		$this->assertNotEmpty( $tokens );
		$this->assertGreaterThan( 30, count( $tokens ), 'Registry should contain at least 30 tokens.' );
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
	 * CSS variable name should use the --cds-{group}-{id} format.
	 *
	 * @return void
	 */
	public function test_css_var_format() {
		$token = $this->registry->get( 'color_surface' );
		$this->assertSame( '--cds-color-surface', $token->css_var() );
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
	 * Applying the Ecommerce preset should override token values.
	 *
	 * @return void
	 */
	public function test_apply_ecommerce_preset() {
		$this->registry->apply_preset( 'NV_oOS_Crocoblock_DS_Preset_Ecommerce' );

		$token = $this->registry->get( 'color_surface' );
		$this->assertSame( '#ffffff', $token->value, 'Ecommerce preset should set surface to white.' );
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
