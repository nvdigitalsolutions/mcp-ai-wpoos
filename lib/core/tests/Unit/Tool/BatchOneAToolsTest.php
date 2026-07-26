<?php
/**
 * Tests for Batch 1a tools — validated wrappers and upsert tools.
 *
 * Covers: CreatePostValidatedTool, GetRecentPostsValidatedTool,
 * SavePostTool, SavePostValidatedTool, SearchContentValidatedTool.
 *
 * @package Nvoos\Core\Tests
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Entity\ContentCollection;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\CreateContentCommand;
use Nvoos\Core\Domain\Entity\ContentQuery;
use Nvoos\Core\Domain\Entity\UpdateContentCommand;
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Domain\Error\NotFoundException;
use Nvoos\Core\Domain\Error\ValidationException;
use Nvoos\Core\Tool\CreatePostValidatedTool;
use Nvoos\Core\Tool\GetRecentPostsValidatedTool;
use Nvoos\Core\Tool\SavePostTool;
use Nvoos\Core\Tool\SavePostValidatedTool;
use Nvoos\Core\Tool\SearchContentValidatedTool;
use PHPUnit\Framework\TestCase;
use DateTimeImmutable;

final class BatchOneAToolsTest extends TestCase {

	private ContentStoreInterface $contentStore;
	private ErrorFactoryInterface $errorFactory;

	protected function setUp(): void {
		$this->contentStore = $this->createMock( ContentStoreInterface::class );
		$this->errorFactory = $this->createMock( ErrorFactoryInterface::class );
	}

	// ─── Helpers ────────────────────────────────────────────────────────

	private function errorResponse( string $code, string $message ): array {
		return array(
			'success' => false,
			'error'   => array( 'code' => $code, 'message' => $message ),
		);
	}

	private function makePost( int $id = 1, string $title = 'Test', string $type = 'post' ): ContentItem {
		return new ContentItem(
			id: $id,
			title: $title,
			content: 'Body content.',
			status: 'publish',
			type: $type,
			authorId: 1,
			createdAt: new DateTimeImmutable( '2026-01-01T00:00:00Z' ),
			updatedAt: new DateTimeImmutable( '2026-01-01T00:00:00Z' ),
		);
	}

	// ═══════════════════════════════════════════════════════════════════
	// CreatePostValidatedTool
	// ═══════════════════════════════════════════════════════════════════

	public function testCreatePostValidatedSlug(): void {
		$tool = new CreatePostValidatedTool( $this->errorFactory, $this->contentStore );
		$this->assertSame( 'create_post_validated', $tool->getSlug() );
	}

	public function testCreatePostValidatedSuccess(): void {
		$post = $this->makePost( 42, 'Hello' );

		$this->contentStore->expects( $this->once() )
			->method( 'create' )
			->willReturn( $post );

		$tool = new CreatePostValidatedTool( $this->errorFactory, $this->contentStore );

		$result = $tool->execute(
			array( 'title' => 'Hello', 'content' => 'World' ),
			array( 'user_id' => 7 ),
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'Post created successfully.', $result['message'] );
		$this->assertSame( 42, $result['data']['id'] );
	}

	public function testCreatePostValidatedWithExplicitAuthor(): void {
		$post = $this->makePost( 10, 'By Author 99' );

		$this->contentStore->expects( $this->once() )
			->method( 'create' )
			->with( $this->callback( function ( CreateContentCommand $cmd ): bool {
				return 99 === $cmd->authorId;
			} ) )
			->willReturn( $post );

		$tool = new CreatePostValidatedTool( $this->errorFactory, $this->contentStore );

		$result = $tool->execute(
			array( 'title' => 'By Author 99', 'user_id' => 99 ),
			array( 'user_id' => 7 ),
		);

		$this->assertTrue( $result['success'] );
	}

	public function testCreatePostValidatedMissingTitle(): void {
		$this->errorFactory->method( 'validationFailed' )
			->willReturn( $this->errorResponse( 'validation_failed', 'The title parameter is required.' ) );

		$tool   = new CreatePostValidatedTool( $this->errorFactory, $this->contentStore );
		$result = $tool->execute( array(), array( 'user_id' => 1 ) );

		$this->assertFalse( $result['success'] );
	}

	public function testCreatePostValidatedNotLoggedIn(): void {
		$this->errorFactory->method( 'forbidden' )
			->willReturn( $this->errorResponse( 'forbidden', 'You must be logged in to create content.' ) );

		$tool   = new CreatePostValidatedTool( $this->errorFactory, $this->contentStore );
		$result = $tool->execute( array( 'title' => 'Test' ), array() );

		$this->assertFalse( $result['success'] );
	}

	// ═══════════════════════════════════════════════════════════════════
	// GetRecentPostsValidatedTool
	// ═══════════════════════════════════════════════════════════════════

	public function testGetRecentPostsValidatedSlug(): void {
		$tool = new GetRecentPostsValidatedTool( $this->errorFactory, $this->contentStore );
		$this->assertSame( 'get_recent_posts_validated', $tool->getSlug() );
	}

	public function testGetRecentPostsValidatedReturnsCollection(): void {
		$post = $this->makePost( 1, 'Post A' );

		$collection = new ContentCollection(
			items: array( $post ),
			total: 1,
			page: 1,
			perPage: 10,
			totalPages: 1,
		);

		$this->contentStore->expects( $this->once() )
			->method( 'query' )
			->willReturn( $collection );

		$tool   = new GetRecentPostsValidatedTool( $this->errorFactory, $this->contentStore );
		$result = $tool->execute( array( 'limit' => 10 ), array( 'user_id' => 1 ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['data']['total'] );
		$this->assertCount( 1, $result['data']['items'] );
	}

	public function testGetRecentPostsValidatedEmpty(): void {
		$collection = new ContentCollection(
			items: array(),
			total: 0,
			page: 1,
			perPage: 10,
			totalPages: 0,
		);

		$this->contentStore->method( 'query' )->willReturn( $collection );

		$tool   = new GetRecentPostsValidatedTool( $this->errorFactory, $this->contentStore );
		$result = $tool->execute( array(), array() );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'No posts found.', $result['message'] );
	}

	public function testGetRecentPostsValidatedClampsLimit(): void {
		$collection = new ContentCollection( items: array(), total: 0, page: 1, perPage: 50, totalPages: 0 );

		$this->contentStore->expects( $this->once() )
			->method( 'query' )
			->with( $this->callback( function ( ContentQuery $q ): bool {
				return 50 === $q->perPage;
			} ) )
			->willReturn( $collection );

		$tool = new GetRecentPostsValidatedTool( $this->errorFactory, $this->contentStore );
		$tool->execute( array( 'limit' => 999 ), array() );

		$this->assertTrue( true ); // No exception = pass.
	}

	// ═══════════════════════════════════════════════════════════════════
	// SavePostTool
	// ═══════════════════════════════════════════════════════════════════

	public function testSavePostSlug(): void {
		$tool = new SavePostTool( $this->errorFactory, $this->contentStore );
		$this->assertSame( 'save_post', $tool->getSlug() );
	}

	public function testSavePostCreatesNew(): void {
		$post = $this->makePost( 99, 'New Post' );

		$this->contentStore->expects( $this->once() )
			->method( 'create' )
			->with( $this->callback( function ( CreateContentCommand $cmd ): bool {
				return 'New Post' === $cmd->title && 'Body here' === $cmd->content;
			} ) )
			->willReturn( $post );

		$tool   = new SavePostTool( $this->errorFactory, $this->contentStore );
		$result = $tool->execute(
			array( 'title' => 'New Post', 'content' => 'Body here' ),
			array( 'user_id' => 1 ),
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'Post created successfully.', $result['message'] );
	}

	public function testSavePostUpdatesExisting(): void {
		$existing = $this->makePost( 5, 'Old Title' );
		$updated  = $this->makePost( 5, 'New Title' );

		$this->contentStore->method( 'find' )
			->with( 5, 1 )
			->willReturn( $existing );

		$this->contentStore->expects( $this->once() )
			->method( 'update' )
			->with( 5, $this->isInstanceOf( UpdateContentCommand::class ) )
			->willReturn( $updated );

		$tool   = new SavePostTool( $this->errorFactory, $this->contentStore );
		$result = $tool->execute(
			array( 'post_id' => 5, 'title' => 'New Title', 'content' => 'Updated body' ),
			array( 'user_id' => 1 ),
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'Post updated successfully.', $result['message'] );
	}

	public function testSavePostUpdateNotFound(): void {
		$this->contentStore->method( 'find' )->willReturn( null );

		$this->errorFactory->method( 'notFound' )
			->willReturn( $this->errorResponse( 'not_found', 'The specified post does not exist.' ) );

		$tool   = new SavePostTool( $this->errorFactory, $this->contentStore );
		$result = $tool->execute(
			array( 'post_id' => 999, 'content' => 'Body' ),
			array( 'user_id' => 1 ),
		);

		$this->assertFalse( $result['success'] );
	}

	public function testSavePostCreateNeedsTitle(): void {
		$this->errorFactory->method( 'validationFailed' )
			->willReturn( $this->errorResponse( 'validation_failed', 'A title is required when creating a new post.' ) );

		$tool   = new SavePostTool( $this->errorFactory, $this->contentStore );
		$result = $tool->execute(
			array( 'content' => 'No title' ),
			array( 'user_id' => 1 ),
		);

		$this->assertFalse( $result['success'] );
	}

	public function testSavePostMissingContent(): void {
		$this->errorFactory->method( 'validationFailed' )
			->willReturn( $this->errorResponse( 'validation_failed', 'Post content is required.' ) );

		$tool   = new SavePostTool( $this->errorFactory, $this->contentStore );
		$result = $tool->execute( array(), array( 'user_id' => 1 ) );

		$this->assertFalse( $result['success'] );
	}

	// ═══════════════════════════════════════════════════════════════════
	// SavePostValidatedTool
	// ═══════════════════════════════════════════════════════════════════

	public function testSavePostValidatedSlug(): void {
		$tool = new SavePostValidatedTool( $this->errorFactory, $this->contentStore );
		$this->assertSame( 'save_post_validated', $tool->getSlug() );
	}

	public function testSavePostValidatedCreatesNew(): void {
		$post = $this->makePost( 10, 'Validated Create' );

		$this->contentStore->expects( $this->once() )
			->method( 'create' )
			->willReturn( $post );

		$tool   = new SavePostValidatedTool( $this->errorFactory, $this->contentStore );
		$result = $tool->execute(
			array( 'title' => 'Validated Create', 'content' => 'Body' ),
			array( 'user_id' => 2 ),
		);

		$this->assertTrue( $result['success'] );
	}

	public function testSavePostValidatedUpdatesExisting(): void {
		$existing = $this->makePost( 7, 'Old' );
		$updated  = $this->makePost( 7, 'Updated' );

		$this->contentStore->method( 'find' )->willReturn( $existing );
		$this->contentStore->method( 'update' )->willReturn( $updated );

		$tool   = new SavePostValidatedTool( $this->errorFactory, $this->contentStore );
		$result = $tool->execute(
			array( 'post_id' => 7, 'content' => 'New body' ),
			array( 'user_id' => 1 ),
		);

		$this->assertTrue( $result['success'] );
	}

	// ═══════════════════════════════════════════════════════════════════
	// SearchContentValidatedTool
	// ═══════════════════════════════════════════════════════════════════

	public function testSearchContentValidatedSlug(): void {
		$tool = new SearchContentValidatedTool( $this->errorFactory, $this->contentStore );
		$this->assertSame( 'search_content_validated', $tool->getSlug() );
	}

	public function testSearchContentValidatedWithSearchTerm(): void {
		$post       = $this->makePost( 1, 'Match' );
		$collection = new ContentCollection(
			items: array( $post ),
			total: 1,
			page: 1,
			perPage: 10,
			totalPages: 1,
		);

		$this->contentStore->expects( $this->once() )
			->method( 'query' )
			->with( $this->callback( function ( ContentQuery $q ): bool {
				return 'test query' === $q->search;
			} ) )
			->willReturn( $collection );

		$tool   = new SearchContentValidatedTool( $this->errorFactory, $this->contentStore );
		$result = $tool->execute(
			array( 'search_term' => 'test query' ),
			array( 'user_id' => 1 ),
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['data']['total'] );
	}

	public function testSearchContentValidatedWithTaxonomyFilters(): void {
		$collection = new ContentCollection( items: array(), total: 0, page: 1, perPage: 10, totalPages: 0 );

		$this->contentStore->expects( $this->once() )
			->method( 'query' )
			->with( $this->callback( function ( ContentQuery $q ): bool {
				$tax = $q->taxQuery;
				return isset( $tax[0]['taxonomy'] ) && 'category' === $tax[0]['taxonomy'];
			} ) )
			->willReturn( $collection );

		$tool = new SearchContentValidatedTool( $this->errorFactory, $this->contentStore );
		$tool->execute(
			array(
				'taxonomy_filters' => array(
					array(
						'taxonomy' => 'category',
						'terms'    => array( 'news' ),
					),
				),
			),
			array( 'user_id' => 1 ),
		);

		$this->assertTrue( true );
	}

	public function testSearchContentValidatedWithMetaFilters(): void {
		$collection = new ContentCollection( items: array(), total: 0, page: 1, perPage: 10, totalPages: 0 );

		$this->contentStore->expects( $this->once() )
			->method( 'query' )
			->with( $this->callback( function ( ContentQuery $q ): bool {
				$meta = $q->metaQuery;
				return isset( $meta[0]['key'] ) && '_custom' === $meta[0]['key'];
			} ) )
			->willReturn( $collection );

		$tool = new SearchContentValidatedTool( $this->errorFactory, $this->contentStore );
		$tool->execute(
			array(
				'meta_filters' => array(
					array( 'key' => '_custom', 'value' => 'yes' ),
				),
			),
			array( 'user_id' => 1 ),
		);

		$this->assertTrue( true );
	}

	public function testSearchContentValidatedEmptyCriteria(): void {
		$this->errorFactory->method( 'validationFailed' )
			->willReturn( $this->errorResponse( 'validation_failed', 'Provide a search term, taxonomy filter, or meta filter.' ) );

		$tool   = new SearchContentValidatedTool( $this->errorFactory, $this->contentStore );
		$result = $tool->execute( array(), array( 'user_id' => 1 ) );

		$this->assertFalse( $result['success'] );
	}

	public function testSearchContentValidatedEmptyResults(): void {
		$collection = new ContentCollection( items: array(), total: 0, page: 1, perPage: 10, totalPages: 0 );

		$this->contentStore->method( 'query' )->willReturn( $collection );

		$tool   = new SearchContentValidatedTool( $this->errorFactory, $this->contentStore );
		$result = $tool->execute(
			array( 'search_term' => 'nothing' ),
			array( 'user_id' => 1 ),
		);

		$this->assertTrue( $result['success'] );
		$this->assertStringContainsString( 'No results found', $result['message'] );
	}

	public function testAllSchemasReturnValidJsonSchema(): void {
		$tools = array(
			new CreatePostValidatedTool( $this->errorFactory, $this->contentStore ),
			new GetRecentPostsValidatedTool( $this->errorFactory, $this->contentStore ),
			new SavePostTool( $this->errorFactory, $this->contentStore ),
			new SavePostValidatedTool( $this->errorFactory, $this->contentStore ),
			new SearchContentValidatedTool( $this->errorFactory, $this->contentStore ),
		);

		foreach ( $tools as $tool ) {
			$schema = $tool->getParametersSchema();
			$this->assertIsArray( $schema );
			$this->assertSame( 'object', $schema['type'] );
			$this->assertArrayHasKey( 'properties', $schema );
			$this->assertFalse( $schema['additionalProperties'] ?? true, "{$tool->getSlug()} should disallow additional properties." );
		}
	}
}
