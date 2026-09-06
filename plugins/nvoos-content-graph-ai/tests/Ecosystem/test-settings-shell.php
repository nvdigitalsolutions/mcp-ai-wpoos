<?php
/**
 * Settings shell tests (Wave D-UI-5).
 *
 * Characterization suite for the ported settings shell: the
 * `SettingsValidator` utility contract (mirrors the base plugin's
 * `WP_MCP_AI_Settings_Validator`), the `AiSection` validate-then-sanitize
 * flow, the `SettingsRegistry` facade over the parent plugin's registry
 * (consumed, never modified), the public platform section-registration
 * hook, `AiSettingsPage` registration, and the real validations wired
 * onto the provider-selection and chat-settings sections.
 *
 * @package NvoosContentGraphAi\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tests;

use NvoosContentGraphAi\Admin\AiSettingsPage;
use NvoosContentGraphAi\Admin\Sections\ChatSettings;
use NvoosContentGraphAi\Admin\Sections\ProviderSelection;
use NvoosContentGraphAi\Admin\Settings\AiSection;
use NvoosContentGraphAi\Admin\Settings\SettingsRegistry;
use NvoosContentGraphAi\Admin\Settings\SettingsValidator;

/**
 * @group settings
 */
class Test_Settings_Shell extends \WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		\remove_all_actions( 'nvoos_content_graph_ai/register_settings_sections' );
	}

	public function tearDown(): void {
		\remove_all_actions( 'nvoos_content_graph_ai/register_settings_sections' );

		parent::tearDown();
	}

	// ─── Validator contract ─────────────────────────────────────────

	public function test_validator_url_contract(): void {
		$this->assertTrue( SettingsValidator::validate_url( '' ) );
		$this->assertTrue( SettingsValidator::validate_url( 'https://example.com/endpoint' ) );

		$error = SettingsValidator::validate_url( 'not-a-url' );
		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( 'invalid_url', $error->get_error_code() );
	}

	public function test_validator_email_contract(): void {
		$this->assertTrue( SettingsValidator::validate_email( '' ) );
		$this->assertTrue( SettingsValidator::validate_email( 'a@b.com' ) );

		$error = SettingsValidator::validate_email( 'nope' );
		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( 'invalid_email', $error->get_error_code() );
	}

	public function test_validator_required_contract(): void {
		$this->assertTrue( SettingsValidator::validate_required( 'x' ) );
		$this->assertTrue( SettingsValidator::validate_required( 0 ) );

		$error = SettingsValidator::validate_required( '', 'Model' );
		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( 'required_field', $error->get_error_code() );
		$this->assertStringContainsString( 'Model', $error->get_error_message() );
	}

	public function test_validator_number_contract(): void {
		$this->assertTrue( SettingsValidator::validate_number( '' ) );
		$this->assertTrue( SettingsValidator::validate_number( '5' ) );

		$this->assertSame( 'invalid_number', SettingsValidator::validate_number( 'abc' )->get_error_code() );
		$this->assertSame( 'number_too_small', SettingsValidator::validate_number( 0, 1 )->get_error_code() );
		$this->assertSame( 'number_too_large', SettingsValidator::validate_number( 3, 0, 2 )->get_error_code() );
	}

	public function test_validator_enum_contract(): void {
		$this->assertTrue( SettingsValidator::validate_enum( '', array( 'a', 'b' ) ) );
		$this->assertTrue( SettingsValidator::validate_enum( 'a', array( 'a', 'b' ) ) );

		$error = SettingsValidator::validate_enum( 'z', array( 'a', 'b' ) );
		$this->assertSame( 'invalid_option', $error->get_error_code() );
	}

	public function test_validator_api_key_contract(): void {
		$this->assertTrue( SettingsValidator::validate_api_key( '' ) );
		$this->assertTrue( SettingsValidator::validate_api_key( 'abc-123_XYZ' ) );

		// Dotted keys (e.g. sk-...) fail the conservative check — that is
		// the documented reason live key fields do not wire this method.
		$error = SettingsValidator::validate_api_key( 'sk-proj.abcd' );
		$this->assertSame( 'invalid_api_key', $error->get_error_code() );
	}

	public function test_validator_json_contract(): void {
		$this->assertTrue( SettingsValidator::validate_json( '' ) );
		$this->assertTrue( SettingsValidator::validate_json( '{"a":1}' ) );

		$error = SettingsValidator::validate_json( '{oops' );
		$this->assertSame( 'invalid_json', $error->get_error_code() );
	}

	// ─── AiSection validate-then-sanitize flow ──────────────────────

	/**
	 * Concrete section for exercising the AiSection contract.
	 */
	private function concrete_section( bool $reject ): AiSection {
		return new class( $reject ) extends AiSection {
			/** @var bool */
			private $reject;

			public function __construct( bool $reject ) {
				$this->reject = $reject;
			}

			public function get_id(): string {
				return 'test_contract';
			}

			public function get_title(): string {
				return 'Contract';
			}

			public function get_tab(): string {
				return 'test_tab';
			}

			public function get_fields(): array {
				return array(
					'field' => array(
						'type'  => 'text',
						'label' => 'Field',
					),
				);
			}

			public function validate( array $input ) {
				if ( $this->reject ) {
					return new \WP_Error( 'rejected', 'Nope.' );
				}
				return $input;
			}
		};
	}

	public function test_ai_section_validate_pass_through(): void {
		$section = $this->concrete_section( false );

		$out = $section->sanitize( array( 'field' => '  hello  ' ) );
		$this->assertSame( 'hello', $out['field'] );
	}

	public function test_ai_section_validate_rejection_preserves_and_records(): void {
		$section = $this->concrete_section( true );

		$out = $section->sanitize( array( 'field' => 'x' ) );

		// Rejected input yields an empty merge (previous values preserved
		// by the parent's merge-on-existing flow)…
		$this->assertSame( array(), $out );

		// …and a settings error is recorded for settings_errors().
		$errors = \get_settings_errors();
		$this->assertNotEmpty( $errors );
		$this->assertContains( 'nvoos_cg_ai_test_contract', wp_list_pluck( $errors, 'code' ) );
	}

	// ─── Registry facade + platform hook ────────────────────────────

	public function test_registry_facade_forwards_to_parent(): void {
		SettingsRegistry::register_tab( 'shell_test_tab', 'Shell Test' );
		$this->assertArrayHasKey( 'shell_test_tab', SettingsRegistry::get_tabs() );

		$section = $this->concrete_section( false );
		SettingsRegistry::register_section( $section );

		$sections = SettingsRegistry::get_sections( 'test_tab' );
		$this->assertContains( $section, $sections );
	}

	public function test_platform_hook_fires_for_consumers(): void {
		$fired = false;

		\add_action(
			'nvoos_content_graph_ai/register_settings_sections',
			static function () use ( &$fired ): void {
				$fired = true;
				SettingsRegistry::register_tab( 'platform_tab', 'Platform' );
			}
		);

		SettingsRegistry::register_from_hook();

		$this->assertTrue( $fired );
		$this->assertArrayHasKey( 'platform_tab', SettingsRegistry::get_tabs() );
	}

	public function test_ai_settings_page_registers_tabs_and_sections(): void {
		( new AiSettingsPage() )->registerSections();

		$tabs = SettingsRegistry::get_tabs();
		$this->assertArrayHasKey( 'ai_providers', $tabs );
		$this->assertArrayHasKey( 'ai_chat', $tabs );
		$this->assertArrayHasKey( 'ai_chat_ui', $tabs );

		$providers = SettingsRegistry::get_sections( 'ai_providers' );
		$this->assertCount( 2, $providers );
		$this->assertSame( 'ai_provider_selection', $providers[0]->get_id() );
		$this->assertSame( 'ai_api_keys', $providers[1]->get_id() );

		$chat = SettingsRegistry::get_sections( 'ai_chat' );
		$this->assertCount( 1, $chat );
		$this->assertSame( 'ai_chat_settings', $chat[0]->get_id() );
	}

	// ─── Section validations ────────────────────────────────────────

	public function test_chat_settings_validation_ranges(): void {
		$section = new ChatSettings();

		// Valid values sanitize normally.
		$out = $section->sanitize(
			array(
				'ai_temperature' => '0.7',
				'ai_max_tokens'  => '4096',
			)
		);
		$this->assertSame( '0.7', $out['ai_temperature'] );
		$this->assertSame( 4096, $out['ai_max_tokens'] );

		// Temperature above the 0–2 range is rejected.
		$out = $section->sanitize( array( 'ai_temperature' => '3' ) );
		$this->assertSame( array(), $out );
		$errors = \get_settings_errors();
		$this->assertContains( 'nvoos_cg_ai_ai_chat_settings', wp_list_pluck( $errors, 'code' ) );

		// Max tokens below the minimum is rejected.
		$out = $section->sanitize( array( 'ai_max_tokens' => '0' ) );
		$this->assertSame( array(), $out );
	}

	public function test_provider_selection_validation_model_required(): void {
		$section = new ProviderSelection();

		// Chat enabled without a model is rejected.
		$out = $section->sanitize(
			array(
				'ai_chat_enabled'     => '1',
				'ai_default_provider' => 'openai',
				'ai_default_model'    => '',
			)
		);
		$this->assertSame( array(), $out );
		$errors = \get_settings_errors();
		$this->assertContains( 'nvoos_cg_ai_ai_provider_selection', wp_list_pluck( $errors, 'code' ) );

		// Unknown provider is rejected.
		$out = $section->sanitize(
			array(
				'ai_chat_enabled'     => '1',
				'ai_default_provider' => 'evil',
				'ai_default_model'    => 'gpt-4o',
			)
		);
		$this->assertSame( array(), $out );

		// Chat disabled without a model is fine.
		$out = $section->sanitize( array( 'ai_default_provider' => 'openai' ) );
		$this->assertSame( 'openai', $out['ai_default_provider'] );
	}
}
