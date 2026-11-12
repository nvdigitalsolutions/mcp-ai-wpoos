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
use Illuminate\Database\Eloquent\Builder;
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

		// Filter by status. WP_Query's 'any' means "no status filter"; the
		// Laravel column has no such value, so translating it to whereIn()
		// would return zero rows.
		if ( array() !== $query->statuses && ! in_array( 'any', $query->statuses, true ) ) {
			$builder->whereIn( 'status', $query->statuses );
		}

		// Full-text search. WP_Query's `s` covers title, excerpt and content;
		// matching only two of the three changes the result set.
		if ( null !== $query->search && '' !== $query->search ) {
			$builder->where( function ( $q ) use ( $query ) {
				$q->where( 'title', 'like', "%{$query->search}%" )
				  ->orWhere( 'excerpt', 'like', "%{$query->search}%" )
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

		// WP_Query-shaped meta and taxonomy clauses are part of the
		// ContentQuery contract. Dropping either returns unfiltered rows while
		// still looking like a successful query, which is worse than failing.
		if ( array() !== $query->metaQuery ) {
			$this->applyMetaQuery( $builder, $query->metaQuery );
		}
		if ( array() !== $query->taxQuery ) {
			$this->applyTaxQuery( $builder, $query->taxQuery );
		}

		// Ordering. ContentQuery speaks WP_Query field names (`date`,
		// `modified`); the table uses created_at / updated_at.
		$order = in_array( strtoupper( $query->order ), array( 'ASC', 'DESC' ), true )
			? strtoupper( $query->order )
			: 'DESC';
		$builder->orderBy( $this->orderColumn( $query->orderBy ), $order );

		// Pagination (documented range is 1–200; 0 means "use the default").
		// A negative value is WP_Query's "return everything" idiom, which this
		// contract does not offer — clamping it to one row would be worse than
		// failing.
		if ( $query->perPage < 0 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- developer-facing.
			throw new \InvalidArgumentException( "perPage must be 0-200; got {$query->perPage}." );
		}

		$perPage = min( 200, max( 1, $query->perPage > 0 ? $query->perPage : 20 ) );
		$page    = max( 1, $query->page );

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
	 * Map a ContentQuery order field to a table column.
	 *
	 * @param string $orderBy ContentQuery order field.
	 * @return string
	 */
	private function orderColumn( string $orderBy ): string {
		switch ( strtolower( trim( $orderBy ) ) ) {
			case '':
			case 'date':
				return 'created_at';
			case 'modified':
				return 'updated_at';
			case 'title':
				return 'title';
			case 'id':
				return 'id';
			default:
				// ContentQuery documents exactly four fields. Silently sorting by
				// created_at for anything else would hide a caller bug.
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- developer-facing.
				throw new \InvalidArgumentException( "Unsupported order field '{$orderBy}'." );
		}
	}

	/**
	 * Apply a WP_Query-style meta clause list.
	 *
	 * @param Builder               $builder Builder.
	 * @param array<string, mixed>  $clauses Clause list, `relation` optional.
	 * @return void
	 */
	private function applyMetaQuery( Builder $builder, array $clauses ): void {
		$relation = 'or' === strtolower( (string) ( $clauses['relation'] ?? 'AND' ) ) ? 'or' : 'and';

		$builder->where(
			function ( Builder $group ) use ( $clauses, $relation ): void {
				foreach ( $clauses as $name => $clause ) {
					if ( 'relation' === $name || ! is_array( $clause ) ) {
						continue;
					}

					if ( $this->isClauseGroup( $clause ) ) {
						$group->where(
							function ( Builder $nested ) use ( $clause ): void {
								$this->applyMetaQuery( $nested, $clause );
							},
							null,
							null,
							$relation
						);
					} else {
						$this->applyMetaClause( $group, $clause, $relation );
					}
				}
			}
		);
	}

	/**
	 * Whether a meta clause element is a nested clause group.
	 *
	 * @param array<string, mixed> $clause Clause element.
	 * @return bool
	 */
	private function isClauseGroup( array $clause ): bool {
		if ( isset( $clause['key'] ) ) {
			return false;
		}

		foreach ( $clause as $value ) {
			if ( is_array( $value ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Apply one meta clause to a group builder.
	 *
	 * @param Builder              $group   Group builder.
	 * @param array<string, mixed> $clause  Clause (`key`, `value`, `compare`).
	 * @param string               $boolean `and` or `or`.
	 * @return void
	 */
	private function applyMetaClause( Builder $group, array $clause, string $boolean ): void {
		$key = isset( $clause['key'] ) ? (string) $clause['key'] : '';

		if ( '' === $key ) {
			return;
		}

		// Meta keys become JSON paths; anything else is a programming error.
		if ( 1 !== preg_match( '/^[A-Za-z0-9_.-]+$/', $key ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- developer-facing.
			throw new \InvalidArgumentException( "Unsupported meta key '{$key}'." );
		}

		$compare = strtoupper( (string) ( $clause['compare'] ?? '=' ) );
		$value   = $clause['value'] ?? null;
		$path    = 'meta->' . $key;

		// WP_Meta_Query casts to a numeric type only when the clause declares
		// one. An untyped `>` is a CHAR comparison, which is how date ranges
		// (`2026-01-10` > `2026-03-01`) work.
		$type    = strtoupper( trim( (string) ( $clause['type'] ?? '' ) ) );
		$numeric = 1 === preg_match(
			'/^(?:NUMERIC|SIGNED|UNSIGNED|DECIMAL(?:\(\d+(?:,\s?\d+)?\))?)$/',
			$type
		);

		switch ( $compare ) {
			case 'EXISTS':
				$group->whereNotNull( $path, $boolean );
				return;

			case 'NOT EXISTS':
				$group->whereNull( $path, $boolean );
				return;

			case 'IN':
			case 'NOT IN':
				$values = is_array( $value ) ? array_values( $value ) : array( $value );
				if ( 'IN' === $compare ) {
					$group->whereIn( $path, $values, $boolean );
				} else {
					$group->whereNotIn( $path, $values, $boolean );
				}
				return;

			case 'BETWEEN':
			case 'NOT BETWEEN':
				$values = is_array( $value ) ? array_values( $value ) : array();
				if ( 2 !== count( $values ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- developer-facing.
					throw new \InvalidArgumentException( "BETWEEN needs exactly two values for '{$key}'." );
				}
				if ( $numeric ) {
					$this->whereMetaNumeric( $group, $path, $compare, array( $values[0], $values[1] ), $boolean );
				} elseif ( 'BETWEEN' === $compare ) {
					$group->whereBetween( $path, array( $values[0], $values[1] ), $boolean );
				} else {
					$group->whereNotBetween( $path, array( $values[0], $values[1] ), $boolean );
				}
				return;

			case '!=':
				// WP_Query's documented quirk: a missing key satisfies `!=`.
				$group->where(
					function ( Builder $nested ) use ( $path, $value ): void {
						$nested->whereNull( $path )->orWhere( $path, '!=', $value );
					},
					null,
					null,
					$boolean
				);
				return;

			case 'NOT LIKE':
				// Plain SQL semantics. WP_Query's NOT LIKE behaviour for
				// absent keys is not documented; do not guess.
				$group->where( $path, 'NOT LIKE', $value, $boolean );
				return;

			default:
				$supported = array( '=', '>', '>=', '<', '<=', 'LIKE' );
				if ( ! in_array( $compare, $supported, true ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- developer-facing.
					throw new \InvalidArgumentException( "Unsupported meta compare '{$compare}'." );
				}

				// Ordering operators cast only when the clause declares a numeric
				// type; untyped comparisons stay lexical, matching WP_Meta_Query.
				if ( $numeric && in_array( $compare, array( '>', '>=', '<', '<=' ), true ) ) {
					$this->whereMetaNumeric( $group, $path, $compare, $value, $boolean );
					return;
				}

				$group->where( $path, $compare, $value, $boolean );
			}
		}

	/**
	 * Compare a JSON meta path numerically, the way WP_Meta_Query does.
	 *
	 * A plain `where( $path, '>', 19.99 )` binds the operand as text, and SQL
	 * orders numbers before strings — so every numeric row fails the predicate
	 * and the query silently returns zero rows. WP_Meta_Query casts the meta
	 * value to DECIMAL (or SIGNED) for numeric operators; casting both sides
	 * here restores that behaviour, including numeric strings.
	 *
	 * @param Builder      $group    Group builder.
	 * @param string       $path     JSON path (`meta->key`).
	 * @param string       $operator One of `>`, `>=`, `<`, `<=`, `BETWEEN`, `NOT BETWEEN`.
	 * @param mixed        $value    Operand, or `[low, high]` for BETWEEN.
	 * @param string       $boolean  `and` or `or`.
	 * @return void
	 */
	private function whereMetaNumeric( Builder $group, string $path, string $operator, mixed $value, string $boolean ): void {
		$allowed = array( '>', '>=', '<', '<=', 'BETWEEN', 'NOT BETWEEN' );

		if ( ! in_array( $operator, $allowed, true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- developer-facing.
			throw new \InvalidArgumentException( "Unsupported numeric compare '{$operator}'." );
		}

		$connection = $group->getConnection();
		$cast       = $this->numericCastType( $connection->getDriverName() );
		$selector   = $connection->getQueryGrammar()->wrap( $path );

		$operands = is_array( $value ) ? array_values( $value ) : array( $value );
		$placeholders = implode( ' AND ', array_fill( 0, count( $operands ), "CAST(? AS {$cast})" ) );

		$group->whereRaw(
			"CAST({$selector} AS {$cast}) {$operator} {$placeholders}",
			$operands,
			$boolean
		);
	}

	/**
	 * Numeric cast type for the active driver.
	 *
	 * SQLite is dynamically typed and accepts NUMERIC; MySQL needs a concrete
	 * DECIMAL precision; Postgres is strict, so a non-numeric meta value fails
	 * loudly there instead of coercing to zero (the MySQL/SQLite behaviour, and
	 * what WP_Meta_Query's SIGNED cast does).
	 */
	private function numericCastType( string $driver ): string {
		return match ( $driver ) {
			'mysql', 'mariadb' => 'DECIMAL(65,10)',
			'pgsql' => 'DOUBLE PRECISION',
			default => 'NUMERIC',
		};
	}

	/**
	 * Apply a WP_Query-style taxonomy clause list.
	 *
	 * Requires the content model to expose a `terms()` relation.
	 *
	 * @param Builder              $builder Builder.
	 * @param array<string, mixed> $clauses Clause list, `relation` optional.
	 * @return void
	 */
	private function applyTaxQuery( Builder $builder, array $clauses ): void {
		$relation = 'or' === strtolower( (string) ( $clauses['relation'] ?? 'AND' ) ) ? 'or' : 'and';

		$builder->where(
			function ( Builder $group ) use ( $clauses, $relation ): void {
				foreach ( $clauses as $name => $clause ) {
					if ( 'relation' === $name || ! is_array( $clause ) || ! isset( $clause['taxonomy'] ) ) {
						continue;
					}

					$taxonomy = (string) $clause['taxonomy'];
					$terms    = $clause['terms'] ?? array();
					$terms    = is_array( $terms ) ? array_values( $terms ) : array( $terms );
					$field    = (string) ( $clause['field'] ?? 'term_id' );
					$operator = strtoupper( (string) ( $clause['operator'] ?? 'IN' ) );

					$column = match ( $field ) {
						'slug' => 'slug',
						'name' => 'name',
						default => 'id',
					};

					$constraint = function ( Builder $q ) use ( $taxonomy, $column, $terms, $operator ): void {
						$q->where( 'taxonomy', $taxonomy );

						if ( 'AND' === $operator ) {
							foreach ( $terms as $term ) {
								$q->where( $column, $term );
							}
						} else {
							$q->whereIn( $column, $terms );
						}
					};

					$group->where(
						function ( Builder $nested ) use ( $constraint, $operator ): void {
							if ( 'NOT IN' === $operator ) {
								$nested->whereDoesntHave( 'terms', $constraint );
							} else {
								$nested->whereHas( 'terms', $constraint );
							}
						},
						null,
						null,
						$relation
					);
				}
			}
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
