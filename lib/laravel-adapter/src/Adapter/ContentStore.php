<?php
/**
 * Laravel adapter: ContentStoreInterface implementation.
 *
 * Wraps Eloquent models behind the framework-agnostic ContentStoreInterface.
 * The content model class is configurable via the 'oos.content_model' config
 * key, defaulting to a generic NvoosPost model. Tools query and mutate content
 * through this adapter without importing any Eloquent classes.
 *
 * @package Nvoos\Laravel
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Adapter;

use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Entity\ContentCollection;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\ContentQuery;
use Nvoos\Core\Domain\Entity\CreateContentCommand;
use Nvoos\Core\Domain\Entity\UpdateContentCommand;
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Domain\Error\NotFoundException;
use Nvoos\Core\Domain\Error\ValidationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

class ContentStore implements ContentStoreInterface {

	/**
	 * Fully-qualified Eloquent model class for content items.
	 *
	 * @var class-string<Model>
	 */
	private string $modelClass;

	/**
	 * @param string $modelClass  Eloquent model FQCN for content items.
	 *                            Defaults to the config value or NvoosPost.
	 */
	public function __construct( string $modelClass = '' ) {
		$this->modelClass = '' !== $modelClass
			? $modelClass
			: ( config( 'oos.content_model', \Nvoos\Laravel\Models\NvoosPost::class ) ?: \Nvoos\Laravel\Models\NvoosPost::class );
	}

	/**
	 * Create a new model instance.
	 *
	 * @return Model
	 */
	private function newModel(): Model {
		$class = $this->modelClass;
		return new $class();
	}

	/**
	 * Find a single content item by ID.
	 *
	 * @param int      $id      Content item identifier.
	 * @param int|null $userId  Optional user context for permission filtering.
	 *
	 * @return ContentItem|null  Null when not found or not accessible.
	 */
	public function find( int $id, ?int $userId = null ): ?ContentItem {
		$modelClass = $this->modelClass;
		$model      = $modelClass::find( $id );

		if ( null === $model ) {
			return null;
		}

		if ( null !== $userId && ! $this->userCanAccess( $id, $userId, 'read' ) ) {
			return null;
		}

		return $this->hydrateContentItem( $model );
	}

	/**
	 * Query content with filtering and pagination.
	 *
	 * Maps ContentQuery fields to Eloquent query builder methods.
	 *
	 * @param ContentQuery $query  Query parameters.
	 * @return ContentCollection
	 */
	public function query( ContentQuery $query ): ContentCollection {
		$modelClass = $this->modelClass;
		$builder    = $modelClass::query();

		// Filter by type (mapped to a 'type' column or model scope).
		if ( array() !== $query->types ) {
			$builder->whereIn( 'type', $query->types );
		}

		// Filter by status.
		if ( array() !== $query->statuses ) {
			$builder->whereIn( 'status', $query->statuses );
		}

		// Full-text search.
		if ( '' !== $query->search ) {
			$builder->where( function ( $q ) use ( $query ) {
				$q->where( 'title', 'like', "%{$query->search}%" )
				  ->orWhere( 'content', 'like', "%{$query->search}%" );
			} );
		}

		// Author filter.
		if ( null !== $query->authorId ) {
			$builder->where( 'author_id', $query->authorId );
		}

		// Include/exclude specific IDs.
		if ( array() !== $query->include ) {
			$builder->whereIn( 'id', $query->include );
		}
		if ( array() !== $query->exclude ) {
			$builder->whereNotIn( 'id', $query->exclude );
		}

		// Ordering.
		$orderBy    = '' !== $query->orderBy ? $query->orderBy : 'created_at';
		$order      = in_array( strtoupper( $query->order ), array( 'ASC', 'DESC' ), true )
			? strtoupper( $query->order )
			: 'DESC';
		$builder->orderBy( $orderBy, $order );

		// Pagination.
		$perPage = $query->perPage > 0 ? $query->perPage : 15;
		$page    = $query->page > 0 ? $query->page : 1;

		$paginator = $builder->paginate(
			perPage: $perPage,
			columns: array( '*' ),
			page: $page,
		);

		$items = array();
		foreach ( $paginator->items() as $model ) {
			$items[] = $this->hydrateContentItem( $model );
		}

		return new ContentCollection(
			items: $items,
			total: $paginator->total(),
			page: $paginator->currentPage(),
			perPage: $paginator->perPage(),
			totalPages: $paginator->lastPage(),
		);
	}

	/**
	 * Create a new content item.
	 *
	 * @param CreateContentCommand $command  Creation parameters.
	 * @return ContentItem
	 *
	 * @throws ValidationException    When data fails validation.
	 * @throws AccessDeniedException  When user lacks permission.
	 */
	public function create( CreateContentCommand $command ): ContentItem {
		$modelClass = $this->modelClass;

		$model = new $modelClass();
		$model->fill( array(
			'title'      => $command->title,
			'type'       => $command->type,
			'status'     => $command->status,
			'content'    => $command->content,
			'author_id'  => $command->authorId,
			'excerpt'    => $command->excerpt,
		) );

		try {
			$model->save();
		} catch ( \Illuminate\Database\QueryException $e ) {
			throw new ValidationException(
				$e->getMessage(),
				array( 'title' => array( $e->getMessage() ) ),
			);
		}

		// Set meta fields as JSON column.
		if ( array() !== $command->meta && $model->isFillable( 'meta' ) ) {
			$model->meta = $command->meta;
			$model->save();
		}

		// Attach taxonomy terms via many-to-many.
		$this->syncTaxonomyTerms( $model, $command->taxonomyInput );

		return $this->hydrateContentItem( $model->fresh() );
	}

	/**
	 * Update an existing content item.
	 *
	 * Only non-null fields in the command are applied. Meta is merged,
	 * not replaced.
	 *
	 * @param int                  $id       Content item ID.
	 * @param UpdateContentCommand $command  Update parameters.
	 * @return ContentItem
	 *
	 * @throws NotFoundException       When the item does not exist.
	 * @throws AccessDeniedException   When user lacks permission.
	 */
	public function update( int $id, UpdateContentCommand $command ): ContentItem {
		$modelClass = $this->modelClass;
		$model      = $modelClass::find( $id );

		if ( null === $model ) {
			throw new NotFoundException( 'Content not found.', 'content', $id );
		}

		if ( ! $this->userCanAccess( $id, $command->userId, 'edit' ) ) {
			throw new AccessDeniedException(
				'You do not have permission to edit this content.',
				$command->userId,
				'edit_content',
				$id,
			);
		}

		$fillable = array_filter( array(
			'title'   => $command->title,
			'content' => $command->content,
			'status'  => $command->status,
			'excerpt' => $command->excerpt,
		), fn ( $v ) => null !== $v );

		if ( array() !== $fillable ) {
			$model->fill( $fillable )->save();
		}

		// Merge meta fields.
		if ( array() !== $command->meta && $model->isFillable( 'meta' ) ) {
			$currentMeta = (array) ( $model->meta ?? array() );
			$model->meta = array_merge( $currentMeta, $command->meta );
			$model->save();
		}

		// Sync taxonomy terms.
		$this->syncTaxonomyTerms( $model, $command->taxonomyInput );

		return $this->hydrateContentItem( $model->fresh() );
	}

	/**
	 * Delete a content item permanently.
	 *
	 * @param int $id      Content item ID.
	 * @param int $userId  User performing the deletion.
	 *
	 * @throws NotFoundException       When the item does not exist.
	 * @throws AccessDeniedException   When user lacks permission.
	 */
	public function delete( int $id, int $userId ): void {
		$modelClass = $this->modelClass;
		$model      = $modelClass::find( $id );

		if ( null === $model ) {
			throw new NotFoundException( 'Content not found.', 'content', $id );
		}

		if ( ! $this->userCanAccess( $id, $userId, 'delete' ) ) {
			throw new AccessDeniedException(
				'You do not have permission to delete this content.',
				$userId,
				'delete_content',
				$id,
			);
		}

		$model->delete();
	}

	/**
	 * Get all metadata for a content item.
	 *
	 * Reads from a JSON 'meta' column on the model.
	 *
	 * @param int $id  Content item ID.
	 * @return array<string, mixed>
	 */
	public function getMeta( int $id ): array {
		$modelClass = $this->modelClass;
		$model      = $modelClass::find( $id );

		if ( null === $model ) {
			return array();
		}

		$meta = $model->meta ?? array();

		return is_array( $meta ) ? $meta : array();
	}

	/**
	 * Get taxonomy terms assigned to a content item.
	 *
	 * Reads from a many-to-many 'terms' relationship on the model.
	 *
	 * @param int $id  Content item ID.
	 * @return array<string, array<int, string>>  Taxonomy slug → [term names].
	 */
	public function getTaxonomyTerms( int $id ): array {
		$modelClass = $this->modelClass;
		$model      = $modelClass::with( 'terms' )->find( $id );

		if ( null === $model || ! method_exists( $model, 'terms' ) ) {
			return array();
		}

		$terms = array();
		foreach ( $model->terms as $term ) {
			$taxonomy = $term->taxonomy ?? 'category';
			$name     = $term->name ?? (string) $term->id;
			$terms[ $taxonomy ][] = $name;
		}

		return $terms;
	}

	/**
	 * Check if a user can perform an operation on a content item.
	 *
	 * Uses Laravel Gates with the convention: '{operation}_content'
	 * (e.g., 'read_content', 'edit_content', 'delete_content').
	 *
	 * @param int    $id         Content item ID.
	 * @param int    $userId     User ID.
	 * @param string $operation  One of: 'read', 'edit', 'delete'.
	 * @return bool
	 */
	public function userCanAccess( int $id, int $userId, string $operation = 'read' ): bool {
		$abilityMap = array(
			'read'   => 'read_content',
			'edit'   => 'edit_content',
			'delete' => 'delete_content',
		);

		$ability = $abilityMap[ $operation ] ?? 'read_content';

		return Gate::allows( $ability, array( $id, $userId ) );
	}

	// ─── Private helpers ──────────────────────────────────────────────

	/**
	 * Convert an Eloquent model to the framework-agnostic ContentItem.
	 */
	private function hydrateContentItem( Model $model ): ContentItem {
		$createdAt = $model->created_at instanceof \DateTimeInterface
			? \DateTimeImmutable::createFromInterface( $model->created_at )
			: new \DateTimeImmutable();

		$updatedAt = $model->updated_at instanceof \DateTimeInterface
			? \DateTimeImmutable::createFromInterface( $model->updated_at )
			: $createdAt;

		$meta = array();
		if ( $model->isFillable( 'meta' ) || array_key_exists( 'meta', $model->getAttributes() ) ) {
			$rawMeta = $model->meta;
			$meta    = is_array( $rawMeta ) ? $rawMeta : array();
		}

		return new ContentItem(
			id: $model->getKey(),
			title: (string) ( $model->title ?? '' ),
			content: (string) ( $model->content ?? '' ),
			status: (string) ( $model->status ?? 'draft' ),
			type: (string) ( $model->type ?? 'post' ),
			authorId: (int) ( $model->author_id ?? $model->user_id ?? 0 ),
			createdAt: $createdAt,
			updatedAt: $updatedAt,
			meta: $meta,
			taxonomy: $this->getTaxonomyTerms( $model->getKey() ),
			excerpt: $model->excerpt ?? null,
			slug: $model->slug ?? null,
		);
	}

	/**
	 * Sync taxonomy terms for a model.
	 *
	 * @param Model                              $model          The content model.
	 * @param array<string, array<int, string>>  $taxonomyInput  Taxonomy → term names.
	 */
	private function syncTaxonomyTerms( Model $model, array $taxonomyInput ): void {
		if ( array() === $taxonomyInput || ! method_exists( $model, 'terms' ) ) {
			return;
		}

		foreach ( $taxonomyInput as $taxonomy => $termNames ) {
			$termIds = array();
			foreach ( $termNames as $name ) {
				$term = \Nvoos\Laravel\Models\NvoosTerm::firstOrCreate(
					array(
						'name'     => $name,
						'taxonomy' => $taxonomy,
					)
				);
				$termIds[] = $term->getKey();
			}

			if ( array() !== $termIds ) {
				$model->terms()->syncWithoutDetaching( $termIds );
			}
		}
	}
}
