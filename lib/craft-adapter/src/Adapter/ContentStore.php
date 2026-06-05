<?php
/**
 * Craft adapter: ContentStoreInterface implementation.
 *
 * Wraps Craft's element system behind the framework-agnostic
 * ContentStoreInterface. Uses Craft's core Entry elements
 * (or configurable element types) for content CRUD operations.
 *
 * @package Nvoos\Craft
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Craft\Adapter;

use Craft;
use craft\elements\Entry;
use craft\helpers\ElementHelper;
use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Entity\ContentCollection;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\ContentQuery;
use Nvoos\Core\Domain\Entity\CreateContentCommand;
use Nvoos\Core\Domain\Entity\UpdateContentCommand;
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Domain\Error\NotFoundException;
use Nvoos\Core\Domain\Error\ValidationException;

class ContentStore implements ContentStoreInterface {

	/**
	 * Craft section handle for content items (e.g., 'posts', 'pages').
	 */
	private string $sectionHandle;

	/**
	 * Element type class to query (defaults to Entry::class for entries).
	 *
	 * @var class-string<\craft\base\ElementInterface>
	 */
	private string $elementClass;

	/**
	 * @param string $sectionHandle  Craft section handle for content items.
	 * @param string $elementClass   Element type class. Defaults to Entry::class.
	 */
	public function __construct( string $sectionHandle = 'posts', string $elementClass = Entry::class ) {
		$this->sectionHandle = $sectionHandle;
		$this->elementClass  = $elementClass;
	}

	/**
	 * Find a single content item by ID.
	 *
	 * @param int      $id      Element ID.
	 * @param int|null $userId  Optional user context for permission filtering.
	 *
	 * @return ContentItem|null  Null when not found or not accessible.
	 */
	public function find( int $id, ?int $userId = null ): ?ContentItem {
		$element = Craft::$app->elements->getElementById( $id, $this->elementClass );

		if ( null === $element ) {
			return null;
		}

		if ( null !== $userId && ! $this->userCanAccess( $id, $userId, 'read' ) ) {
			return null;
		}

		return $this->hydrateContentItem( $element );
	}

	/**
	 * Query content with filtering and pagination.
	 *
	 * Uses Craft's element query builder. The section handle scopes
	 * queries to a specific section by default.
	 *
	 * @param ContentQuery $query  Query parameters.
	 * @return ContentCollection
	 */
	public function query( ContentQuery $query ): ContentCollection {
		$elementQuery = $this->elementClass::find()
			->section( $this->sectionHandle );

		// Filter by status.
		if ( array() !== $query->statuses ) {
			// Map domain statuses to Craft statuses.
			$craftStatuses = array_map(
				fn ( string $s ) => match ( $s ) {
					'publish'  => 'live',
					'draft'    => 'draft',
					'pending'  => 'pending',
					'trash'    => 'disabled',
					default    => $s,
				},
				$query->statuses,
			);
			$elementQuery->status( $craftStatuses );
		} else {
			$elementQuery->status( array( 'live' ) );
		}

		// Full-text search.
		if ( '' !== $query->search ) {
			$elementQuery->search( $query->search );
		}

		// Author filter.
		if ( null !== $query->authorId ) {
			$elementQuery->authorId( $query->authorId );
		}

		// Include/exclude specific IDs.
		if ( array() !== $query->include ) {
			$elementQuery->id( $query->include );
		}
		if ( array() !== $query->exclude ) {
			$elementQuery->id( array( 'not', $query->exclude ) );
		}

		// Ordering.
		$orderBy = '' !== $query->orderBy ? $query->orderBy : 'dateCreated';
		$order   = in_array( strtoupper( $query->order ), array( 'ASC', 'DESC' ), true )
			? ( SORT_ASC === constant( 'SORT_' . strtoupper( $query->order ) ) ? SORT_ASC : SORT_DESC )
			: SORT_DESC;
		$elementQuery->orderBy( array( $orderBy => $order ) );

		// Pagination.
		$perPage = $query->perPage > 0 ? $query->perPage : 15;
		$page    = $query->page > 0 ? $query->page : 1;
		$offset  = ( $page - 1 ) * $perPage;

		$elementQuery->limit( $perPage );
		$elementQuery->offset( $offset );

		$total    = $elementQuery->count();
		$elements = $elementQuery->all();

		$items = array();
		foreach ( $elements as $element ) {
			$items[] = $this->hydrateContentItem( $element );
		}

		return new ContentCollection(
			items: $items,
			total: $total,
			page: $page,
			perPage: $perPage,
			totalPages: $perPage > 0 ? (int) ceil( $total / $perPage ) : 1,
		);
	}

	/**
	 * Create a new content item.
	 *
	 * Creates a Craft Entry in the configured section.
	 *
	 * @param CreateContentCommand $command  Creation parameters.
	 * @return ContentItem
	 *
	 * @throws ValidationException  When data fails validation.
	 */
	public function create( CreateContentCommand $command ): ContentItem {
		$section = Craft::$app->sections->getSectionByHandle( $this->sectionHandle );
		if ( null === $section ) {
			throw new \RuntimeException( "Section '{$this->sectionHandle}' not found." );
		}

		$entryType = $section->getEntryTypes()[0] ?? null;
		if ( null === $entryType ) {
			throw new \RuntimeException( "No entry types found for section '{$this->sectionHandle}'." );
		}

		$entry = new Entry();
		$entry->sectionId   = $section->id;
		$entry->typeId      = $entryType->id;
		$entry->title       = $command->title;
		$entry->authorId    = $command->authorId;

		// Map domain status to Craft status.
		$entryStatus = match ( $command->status ) {
			'draft'   => false,
			'pending' => false,
			default   => true,
		};
		$entry->enabled = $entryStatus;

		// Set content via custom field or native attribute.
		if ( $command->content ) {
			$entry->setFieldValue( 'body', $command->content );
		}

		// Set meta fields.
		foreach ( $command->meta as $key => $value ) {
			$entry->setFieldValue( $key, $value );
		}

		if ( ! Craft::$app->elements->saveElement( $entry ) ) {
			$errors = implode( ', ', $entry->getErrorSummary( true ) );
			throw new ValidationException( $errors );
		}

		return $this->hydrateContentItem( $entry );
	}

	/**
	 * Update an existing content item.
	 *
	 * @param int                  $id       Element ID.
	 * @param UpdateContentCommand $command  Update parameters.
	 * @return ContentItem
	 *
	 * @throws NotFoundException       When the item does not exist.
	 * @throws AccessDeniedException   When user lacks permission.
	 */
	public function update( int $id, UpdateContentCommand $command ): ContentItem {
		$element = Craft::$app->elements->getElementById( $id, $this->elementClass );

		if ( null === $element ) {
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

		if ( null !== $command->title ) {
			$element->title = $command->title;
		}

		if ( null !== $command->content ) {
			$element->setFieldValue( 'body', $command->content );
		}

		if ( null !== $command->status ) {
			$element->enabled = 'publish' === $command->status;
		}

		// Merge meta fields.
		foreach ( $command->meta as $key => $value ) {
			$element->setFieldValue( $key, $value );
		}

		if ( ! Craft::$app->elements->saveElement( $element ) ) {
			$errors = implode( ', ', $element->getErrorSummary( true ) );
			throw new ValidationException( $errors );
		}

		return $this->hydrateContentItem( $element );
	}

	/**
	 * Delete a content item permanently.
	 *
	 * @param int $id      Element ID.
	 * @param int $userId  User performing the deletion.
	 *
	 * @throws NotFoundException       When the item does not exist.
	 * @throws AccessDeniedException   When user lacks permission.
	 */
	public function delete( int $id, int $userId ): void {
		$element = Craft::$app->elements->getElementById( $id, $this->elementClass );
		if ( null === $element ) {
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

		Craft::$app->elements->deleteElement( $element, true );
	}

	/**
	 * Get all metadata for a content item.
	 *
	 * Reads custom field values from the element.
	 *
	 * @param int $id  Element ID.
	 * @return array<string, mixed>
	 */
	public function getMeta( int $id ): array {
		$element = Craft::$app->elements->getElementById( $id, $this->elementClass );
		if ( null === $element ) {
			return array();
		}

		$meta = array();
		if ( method_exists( $element, 'getFieldValues' ) ) {
			$meta = $element->getFieldValues();
		}

		return is_array( $meta ) ? $meta : array();
	}

	/**
	 * Get taxonomy terms assigned to a content item.
	 *
	 * In Craft, categories and tags are element relations — this method
	 * reads category/tag fields attached to the element.
	 *
	 * @param int $id  Element ID.
	 * @return array<string, array<int, string>>
	 */
	public function getTaxonomyTerms( int $id ): array {
		$element = Craft::$app->elements->getElementById( $id, $this->elementClass );
		if ( null === $element ) {
			return array();
		}

		$terms = array();

		// Walk the element's field layout looking for category/tag fields.
		$fieldLayout = $element->getFieldLayout();
		if ( null !== $fieldLayout ) {
			foreach ( $fieldLayout->getCustomFields() as $field ) {
				if ( $field instanceof \craft\fields\Categories || $field instanceof \craft\fields\Tags ) {
					$related = $element->getFieldValue( $field->handle );
					if ( $related instanceof \craft\elements\db\ElementQueryInterface ) {
						$related = $related->all();
					}
					if ( is_iterable( $related ) ) {
						foreach ( $related as $relatedElement ) {
							$terms[ $field->handle ][] = $relatedElement->title ?? (string) $relatedElement->id;
						}
					}
				}
			}
		}

		return $terms;
	}

	/**
	 * Check if a user can perform an operation on a content item.
	 *
	 * Uses Craft's native element permission system.
	 *
	 * @param int    $id         Element ID.
	 * @param int    $userId     User ID.
	 * @param string $operation  One of: 'read', 'edit', 'delete'.
	 * @return bool
	 */
	public function userCanAccess( int $id, int $userId, string $operation = 'read' ): bool {
		$user = Craft::$app->users->getUserById( $userId );
		if ( null === $user ) {
			return false;
		}

		$element = Craft::$app->elements->getElementById( $id );

		return match ( $operation ) {
			'read'   => null !== $element && $user->can( "viewEntries:{$element->sectionId}" ),
			'edit'   => null !== $element && $user->can( "saveEntries:{$element->sectionId}" ),
			'delete' => null !== $element && $user->can( "deleteEntries:{$element->sectionId}" ),
			default  => false,
		};
	}

	// ─── Private helpers ──────────────────────────────────────────────

	/**
	 * Convert a Craft element to the framework-agnostic ContentItem.
	 *
	 * @param \craft\base\ElementInterface $element  Craft element (Entry, etc.).
	 */
	private function hydrateContentItem( \craft\base\ElementInterface $element ): ContentItem {
		$createdAt = $element->dateCreated instanceof \DateTimeInterface
			? \DateTimeImmutable::createFromInterface( $element->dateCreated )
			: new \DateTimeImmutable();

		$updatedAt = $element->dateUpdated instanceof \DateTimeInterface
			? \DateTimeImmutable::createFromInterface( $element->dateUpdated )
			: $createdAt;

		$type = $element instanceof Entry
			? $element->section->handle ?? 'entry'
			: get_class( $element );

		return new ContentItem(
			id: $element->id,
			title: (string) ( $element->title ?? '' ),
			content: (string) ( $element->getFieldValue( 'body' ) ?? '' ),
			status: $element->enabled ? 'publish' : 'draft',
			type: $type,
			authorId: $element->authorId ?? 0,
			createdAt: $createdAt,
			updatedAt: $updatedAt,
			meta: $this->getMeta( $element->id ),
			taxonomy: $this->getTaxonomyTerms( $element->id ),
			excerpt: $element instanceof Entry ? ( $element->getFieldValue( 'excerpt' ) ?: null ) : null,
			slug: $element->slug ?? null,
		);
	}
}
