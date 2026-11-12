<?php
/**
 * Toolkit research service tests.
 *
 * @package  Nvoos\Core\Tests
 * @since    2.1.0
 * @license  MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Application\Toolkit;

use Nvoos\Core\Application\Provider\ProviderRouter;
use Nvoos\Core\Application\Toolkit\ToolkitResearchService;
use Nvoos\Core\Domain\ValueObject\ToolkitManifest;
use PHPUnit\Framework\TestCase;

final class ToolkitResearchServiceTest extends TestCase {

	private string $systemPrompt = '';

	private function manifest(): ToolkitManifest {
		return ToolkitManifest::fromArray(
			array(
				'slug'     => 'commerce',
				'name'     => 'Commerce',
				'version'  => '1.0.0',
				'feature'  => 'commerce',
				'screens'  => array( 'research' ),
				'entities' => array(
					array(
						'key'      => 'product',
						'label'    => 'Products',
						'singular' => 'Product',
						'id_field' => 'sku',
						'columns'  => array(
							array(
								'key'      => 'sku',
								'label'    => 'SKU',
								'type'     => 'string',
								'required' => true,
							),
							array(
								'key'   => 'name',
								'label' => 'Name',
								'type'  => 'string',
							),
							array(
								'key'   => 'status',
								'label' => 'Status',
								'type'  => 'enum',
								'enum'  => array( 'draft', 'active' ),
							),
						),
					),
				),
				'dashboard' => array( 'cards' => array() ),
			)
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function entity(): array {
		return (array) $this->manifest()->entity( 'product' );
	}

	/**
	 * @param mixed $answer What ProviderRouter::chat() returns.
	 */
	private function serviceReturning( mixed $answer ): ToolkitResearchService {
		$router = $this->createMock( ProviderRouter::class );

		$router->expects( $this->once() )
			->method( 'chat' )
			->with( $this->callback(
				function ( array $messages ): bool {
					$this->systemPrompt = (string) ( $messages[0]['content'] ?? '' );

					return 'Add the A-100 widget' === ( $messages[1]['content'] ?? null );
				}
			) )
			->willReturn( $answer );

		return new ToolkitResearchService( $router );
	}

	private function serviceReturningContent( string $content ): ToolkitResearchService {
		return $this->serviceReturning(
			array(
				'model'   => 'gpt-test',
				'choices' => array(
					array( 'message' => array( 'content' => $content ) ),
				),
			)
		);
	}

	public function test_it_drafts_only_the_declared_columns_and_strips_the_fence(): void {
		$service = $this->serviceReturningContent(
			"```json\n{\"sku\":\"A-100\",\"name\":\"Widget\",\"ghost\":\"drop me\"}\n```"
		);

		$result = $service->draft( $this->manifest(), $this->entity(), 'Add the A-100 widget' );

		$this->assertNull( $result['error'] );
		$this->assertSame( 'gpt-test', $result['provider'] );
		$this->assertSame(
			array(
				'sku'  => 'A-100',
				'name' => 'Widget',
			),
			$result['draft']
		);
	}

	public function test_the_prompt_names_every_column_with_its_enum_and_required_flag(): void {
		$service = $this->serviceReturningContent( '{"sku":"A-100"}' );

		$service->draft( $this->manifest(), $this->entity(), 'Add the A-100 widget' );

		$this->assertStringContainsString( 'Commerce', $this->systemPrompt );
		$this->assertStringContainsString( '- sku (string; required)', $this->systemPrompt );
		$this->assertStringContainsString( '- name (string)', $this->systemPrompt );
		$this->assertStringContainsString( 'one of: draft, active', $this->systemPrompt );
		$this->assertStringContainsString( 'single JSON object', $this->systemPrompt );
	}

	public function test_a_provider_error_becomes_ai_unavailable(): void {
		$service = $this->serviceReturning( new \RuntimeException( 'No provider configured.' ) );

		$result = $service->draft( $this->manifest(), $this->entity(), 'Add the A-100 widget' );

		$this->assertSame( 'ai_unavailable', $result['error'] );
		$this->assertSame( 'No provider configured.', $result['message'] );
		$this->assertSame( array(), $result['draft'] );
	}

	public function test_non_json_content_becomes_ai_invalid_response(): void {
		$service = $this->serviceReturningContent( 'Sure! Here is your product.' );

		$result = $service->draft( $this->manifest(), $this->entity(), 'Add the A-100 widget' );

		$this->assertSame( 'ai_invalid_response', $result['error'] );
	}

	public function test_a_reply_with_no_known_columns_is_an_invalid_response(): void {
		$service = $this->serviceReturningContent( '{"ghost":"boo"}' );

		$result = $service->draft( $this->manifest(), $this->entity(), 'Add the A-100 widget' );

		$this->assertSame( 'ai_invalid_response', $result['error'] );
	}

	public function test_an_empty_query_short_circuits_without_calling_the_provider(): void {
		$router = $this->createMock( ProviderRouter::class );

		$router->expects( $this->never() )->method( 'chat' );

		$result = ( new ToolkitResearchService( $router ) )
			->draft( $this->manifest(), $this->entity(), '   ' );

		$this->assertSame( 'invalid_query', $result['error'] );
	}
}
