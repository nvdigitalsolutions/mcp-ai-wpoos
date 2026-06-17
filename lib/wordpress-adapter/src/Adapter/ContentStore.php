<?php
/**
 * WordPress adapter: ContentStoreInterface implementation.
 *
 * Wraps WordPress post functions (get_post, wp_insert_post, WP_Query,
 * get_post_meta, wp_get_post_terms) behind the framework-agnostic
 * ContentStoreInterface.
 *
 * @package Nvoos\WordPress
 * @since   1.0.0
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Nvoos\WordPress\Adapter;

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

	public function find( int $id, ?int $userId = null ): ?ContentItem {
		$post = \get_post( $id );

		if ( ! $post instanceof \WP_Post ) {
			return null;
		}

		// If a user context is provided, check read permission.
		if ( null !== $userId && ! $this->userCanAccess( $id, $userId, 'read' ) ) {
			return null;
		}

		return $this->hydrateContentItem( $post );
	}

	public function query( ContentQuery $query ): ContentCollection {
		$args = array(
			'post_type'      => array() === $query->types ? 'any' : $query->types,
			'post_status'    => $query->statuses,
			's'              => $query->search,
			'author'         => $query->authorId,
			'post__in'       => $query->include,
			'post__not_in'   => $query->exclude,
			'orderby'        => $query->orderBy,
			'order'          => $query->order,
			'posts_per_page' => $query->perPage,
			'paged'          => $query->page,
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'meta_query'     => $query->metaQuery,
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			'tax_query'      => $query->taxQuery,
		);

		$wpQuery = new \WP_Query( $args );

		$items = array();
		foreach ( $wpQuery->posts as $post ) {
			if ( $post instanceof \WP_Post ) {
				$items[] = $this->hydrateContentItem( $post );
			}
		}

		$total      = (int) ( $wpQuery->found_posts ?? 0 );
		$totalPages = $query->perPage > 0
			? (int) ceil( $total / $query->perPage )
			: 1;

		return new ContentCollection(
			items: $items,
			total: $total,
			page: $query->page,
			perPage: $query->perPage,
			totalPages: $totalPages,
		);
	}

	public function create( CreateContentCommand $command ): ContentItem {
		$postData = array(
			'post_title'   => $command->title,
			'post_type'    => $command->type,
			'post_status'  => $command->status,
			'post_content' => $command->content,
			'post_author'  => $command->authorId,
			'post_excerpt' => $command->excerpt,
		);

		$postId = \wp_insert_post( $postData, true );

		if ( \is_wp_error( $postId ) ) {
			throw new ValidationException(
				$postId->get_error_message(),
				array( 'title' => array( $postId->get_error_message() ) ),
			);
		}

		// Set meta fields.
		foreach ( $command->meta as $key => $value ) {
			\update_post_meta( $postId, \sanitize_key( $key ), $value );
		}

		// Set taxonomy terms.
		foreach ( $command->taxonomyInput as $taxonomy => $terms ) {
			\wp_set_post_terms( $postId, $terms, \sanitize_key( $taxonomy ) );
		}

		$post = \get_post( $postId );
		if ( ! $post instanceof \WP_Post ) {
			throw new NotFoundException( 'Created post not found.', 'post', $postId );
		}

		return $this->hydrateContentItem( $post );
	}

	public function update( int $id, UpdateContentCommand $command ): ContentItem {
		$post = \get_post( $id );
		if ( ! $post instanceof \WP_Post ) {
			throw new NotFoundException( 'Post not found.', 'post', $id );
		}

		if ( ! $this->userCanAccess( $id, $command->userId, 'edit' ) ) {
			throw new AccessDeniedException(
				'You do not have permission to edit this post.',
				$command->userId,
				'edit_post',
				$id,
			);
		}

		$postData = array( 'ID' => $id );

		if ( null !== $command->title ) {
			$postData['post_title'] = $command->title;
		}
		if ( null !== $command->content ) {
			$postData['post_content'] = $command->content;
		}
		if ( null !== $command->status ) {
			$postData['post_status'] = $command->status;
		}
		if ( null !== $command->excerpt ) {
			$postData['post_excerpt'] = $command->excerpt;
		}

		$result = \wp_update_post( $postData, true );

		if ( \is_wp_error( $result ) ) {
			throw new ValidationException(
				$result->get_error_message(),
			);
		}

		// Merge meta fields.
		foreach ( $command->meta as $key => $value ) {
			\update_post_meta( $id, \sanitize_key( $key ), $value );
		}

		// Set taxonomy terms (replace, not append).
		foreach ( $command->taxonomyInput as $taxonomy => $terms ) {
			\wp_set_post_terms( $id, $terms, \sanitize_key( $taxonomy ) );
		}

		$post = \get_post( $id );
		if ( ! $post instanceof \WP_Post ) {
			throw new NotFoundException( 'Post not found after update.', 'post', $id );
		}

		return $this->hydrateContentItem( $post );
	}

	public function delete( int $id, int $userId ): void {
		if ( ! $this->userCanAccess( $id, $userId, 'delete' ) ) {
			throw new AccessDeniedException(
				'You do not have permission to delete this post.',
				$userId,
				'delete_post',
				$id,
			);
		}

		$result = \wp_delete_post( $id, true );

		if ( ! $result || ( \is_wp_error( $result ) && 'trash' !== \get_post_status( $id ) ) ) {
			throw new NotFoundException( 'Post could not be deleted.', 'post', $id );
		}
	}

	public function getMeta( int $id ): array {
		$meta = \get_post_meta( $id );

		if ( ! is_array( $meta ) ) {
			return array();
		}

		// WordPress stores all meta values as arrays; unwrap single values.
		$unwrapped = array();
		foreach ( $meta as $key => $values ) {
			if ( is_array( $values ) && 1 === count( $values ) ) {
				$unwrapped[ $key ] = reset( $values );
			} else {
				$unwrapped[ $key ] = $values;
			}
		}

		return $unwrapped;
	}

	public function getTaxonomyTerms( int $id ): array {
		$post = \get_post( $id );
		if ( ! $post instanceof \WP_Post ) {
			return array();
		}

		$taxonomies = \get_object_taxonomies( $post->post_type, 'names' );
		$terms      = array();

		foreach ( $taxonomies as $taxonomy ) {
			$postTerms = \wp_get_post_terms( $id, $taxonomy, array( 'fields' => 'names' ) );
			if ( is_array( $postTerms ) && array() !== $postTerms ) {
				$terms[ $taxonomy ] = $postTerms;
			}
		}

		return $terms;
	}

	public function userCanAccess( int $id, int $userId, string $operation = 'read' ): bool {
		$post = \get_post( $id );
		if ( ! $post instanceof \WP_Post ) {
			return false;
		}

		// Map domain operation to WordPress capability.
		$capabilityMap = array(
			'read'   => 'read_post',
			'edit'   => 'edit_post',
			'delete' => 'delete_post',
		);

		$capability = $capabilityMap[ $operation ] ?? 'read_post';

		return \user_can( $userId, $capability, $id );
	}

	/**
	 * Convert a WP_Post to the framework-agnostic ContentItem.
	 */
	private function hydrateContentItem( \WP_Post $post ): ContentItem {
		$createdAt = \DateTimeImmutable::createFromFormat(
			'Y-m-d H:i:s',
			$post->post_date_gmt,
			new \DateTimeZone( 'UTC' ),
		) ?: new \DateTimeImmutable();

		$updatedAt = \DateTimeImmutable::createFromFormat(
			'Y-m-d H:i:s',
			$post->post_modified_gmt,
			new \DateTimeZone( 'UTC' ),
		) ?: $createdAt;

		return new ContentItem(
			id: $post->ID,
			title: $post->post_title,
			content: $post->post_content,
			status: $post->post_status,
			type: $post->post_type,
			authorId: (int) $post->post_author,
			createdAt: $createdAt,
			updatedAt: $updatedAt,
			meta: $this->getMeta( $post->ID ),
			taxonomy: $this->getTaxonomyTerms( $post->ID ),
			excerpt: $post->post_excerpt ?: null,
			slug: $post->post_name ?: null,
		);
	}
}
