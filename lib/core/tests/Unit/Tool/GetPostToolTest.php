<?php
/**
 * Tests for GetPostTool — demonstrates the mock-adapter test pattern.
 *
 * The tool receives ContentStoreInterface and ErrorFactoryInterface via
 * constructor injection. Tests mock both interfaces to control responses
 * without any WordPress dependency.
 *
 * @package Nvoos\Core\Tests
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Tool\GetPostTool;
use PHPUnit\Framework\TestCase;
use DateTimeImmutable;

final class GetPostToolTest extends TestCase {

	private ContentStoreInterface $contentStore;
	private ErrorFactoryInterface $errorFactory;
	private GetPostTool $tool;

	protected function setUp(): void {
		$this->contentStore = $this->createMock( ContentStoreInterface::class );
		$this->errorFactory = $this->createMock( ErrorFactoryInterface::class );

		$this->tool = new GetPostTool(
			$this->errorFactory,
			$this->contentStore,
		);
	}

	public function testGetSlug(): void {
		$this->assertSame( 'get_post', $this->tool->getSlug() );
	}

	public function testGetName(): void {
		$this->assertSame( 'Get Post', $this->tool->getName() );
	}

	public function testGetDescription(): void {
		$this->assertIsString( $this->tool->getDescription() );
		$this->assertNotEmpty( $this->tool->getDescription() );
	}

	public function testGetParametersSchema(): void {
		$schema = $this->tool->getParametersSchema();

		$this->assertIsArray( $schema );
		$this->assertSame( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'post_id', $schema['properties'] );
		$this->assertSame( 'integer', $schema['properties']['post_id']['type'] );
		$this->assertContains( 'post_id', $schema['required'] );
	}

	public function testGetRequiredCapability(): void {
		$this->assertSame( 'edit_posts', $this->tool->getRequiredCapability() );
	}

	public function testExecuteReturnsPostWhenFound(): void {
		$expectedPost = new ContentItem(
			id: 42,
			title: 'Hello World',
			content: 'Post body content.',
			status: 'publish',
			type: 'post',
			authorId: 1,
			createdAt: new DateTimeImmutable( '2026-01-01T00:00:00Z' ),
			updatedAt: new DateTimeImmutable( '2026-01-02T00:00:00Z' ),
			meta: array( '_custom_field' => 'value' ),
			taxonomy: array( 'category' => array( 'News' ) ),
			excerpt: 'Summary.',
			slug: 'hello-world',
		);

		$this->contentStore->method( 'find' )
			->with( 42, null )
			->willReturn( $expectedPost );

		$result = $this->tool->execute(
			array( 'post_id' => 42 ),
			array(),
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'Post retrieved.', $result['message'] );

		$this->assertArrayHasKey( 'data', $result );
		$this->assertSame( 42, $result['data']['id'] );
		$this->assertSame( 'Hello World', $result['data']['title'] );
		$this->assertSame( 'publish', $result['data']['status'] );
		$this->assertSame( 'value', $result['data']['meta']['_custom_field'] );
		$this->assertSame( array( 'News' ), $result['data']['taxonomy']['category'] );
	}

	public function testExecuteOmitsMetaWhenRequested(): void {
		$post = new ContentItem(
			id: 1,
			title: 'Test',
			content: '',
			status: 'publish',
			type: 'post',
			authorId: 1,
			createdAt: new DateTimeImmutable(),
			updatedAt: new DateTimeImmutable(),
		);

		$this->contentStore->method( 'find' )->willReturn( $post );

		$result = $this->tool->execute(
			array( 'post_id' => 1, 'include_meta' => false ),
			array(),
		);

		$this->assertTrue( $result['success'] );
		$this->assertArrayNotHasKey( 'meta', $result['data'] );
	}

	public function testExecuteOmitsTaxonomiesWhenRequested(): void {
		$post = new ContentItem(
			id: 1,
			title: 'Test',
			content: '',
			status: 'publish',
			type: 'post',
			authorId: 1,
			createdAt: new DateTimeImmutable(),
			updatedAt: new DateTimeImmutable(),
		);

		$this->contentStore->method( 'find' )->willReturn( $post );

		$result = $this->tool->execute(
			array( 'post_id' => 1, 'include_taxonomies' => false ),
			array(),
		);

		$this->assertTrue( $result['success'] );
		$this->assertArrayNotHasKey( 'taxonomy', $result['data'] );
	}

	public function testExecuteReturnsErrorWhenNotFound(): void {
		$this->contentStore->method( 'find' )
			->with( 999, null )
			->willReturn( null );

		// Error factory returns a simple error array for "not found".
		// This is framework-agnostic — no WP_Error dependency.
		$expectedError = array(
			'success' => false,
			'error'   => array(
				'code'    => 'not_found',
				'message' => 'The requested post does not exist or you do not have permission to view it.',
			),
		);

		$this->errorFactory->method( 'notFound' )
			->willReturn( $expectedError );

		$result = $this->tool->execute(
			array( 'post_id' => 999 ),
			array(),
		);

		$this->assertSame( $expectedError, $result );
	}

	public function testExecuteReturnsErrorWhenPostIdInvalid(): void {
		$expectedError = array(
			'success' => false,
			'error'   => array(
				'code'    => 'validation_failed',
				'message' => 'post_id is required and must be a positive integer.',
			),
		);

		$this->errorFactory->method( 'validationFailed' )
			->willReturn( $expectedError );

		$result = $this->tool->execute(
			array( 'post_id' => 0 ),
			array(),
		);

		$this->assertSame( $expectedError, $result );
	}

	public function testExecuteWithUserIdContext(): void {
		$post = new ContentItem(
			id: 42,
			title: 'Test',
			content: '',
			status: 'publish',
			type: 'post',
			authorId: 3,
			createdAt: new DateTimeImmutable(),
			updatedAt: new DateTimeImmutable(),
		);

		// The tool should pass the user_id from context to ContentStore::find().
		$this->contentStore->expects( $this->once() )
			->method( 'find' )
			->with( 42, 5 )
			->willReturn( $post );

		$result = $this->tool->execute(
			array( 'post_id' => 42 ),
			array( 'user_id' => 5 ),
		);

		$this->assertTrue( $result['success'] );
	}
}
