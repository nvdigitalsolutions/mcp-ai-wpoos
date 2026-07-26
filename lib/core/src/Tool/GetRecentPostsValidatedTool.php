<?php
/**
 * Get Recent Posts (Validated) tool — retrieves recent content items.
 *
 * Variant of GetRecentPostsTool with a validated slug. Framework-agnostic
 * equivalent of WP_MCP_AI_Tool_Get_Recent_Posts_Validated.
 *
 * @package Nvoos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Entity\ContentQuery;

class GetRecentPostsValidatedTool extends AbstractTool {

	public function __construct(
		ErrorFactoryInterface $errors,
		private readonly ContentStoreInterface $content,
	) {
		parent::__construct( $errors );
	}

	public function getSlug(): string {
		return 'get_recent_posts_validated';
	}

	public function getName(): string {
		return 'Get Recent Posts (Validated)';
	}

	public function getDescription(): string {
		return 'Retrieves a list of recent content items, optionally filtered by type and count.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_type' => array(
					'type'        => 'string',
					'description' => 'Content type slug. Default: post.',
					'default'     => 'post',
				),
				'limit'     => array(
					'type'        => 'integer',
					'description' => 'Number of posts to return (1-50). Default: 10.',
					'minimum'     => 1,
					'maximum'     => 50,
					'default'     => 10,
				),
			),
			'additionalProperties' => false,
		);
	}

	public function getRequiredCapability(): string {
		return 'read';
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$type  = $this->stringParam( $arguments, 'post_type', 'post' );
		$count = $this->intParam( $arguments, 'limit', 10 );
		$count = \max( 1, \min( 50, $count ) );

		$query = new ContentQuery(
			types: array( $type ),
			statuses: array( 'publish' ),
			perPage: $count,
			page: 1,
			userId: $context['user_id'] ?? null,
		);

		$result = $this->content->query( $query );

		if ( ! $result->hasItems() ) {
			return $this->emptyResult( 'No posts found.' );
		}

		$items = array_map(
			fn( $i ) => array(
				'id'         => $i->id,
				'title'      => $i->title,
				'type'       => $i->type,
				'excerpt'    => $i->excerpt,
				'created_at' => $i->createdAt->format( 'c' ),
				'slug'       => $i->slug,
			),
			$result->items,
		);

		return $this->collection(
			'Retrieved ' . \count( $items ) . ' posts.',
			$items,
			$result->total,
		);
	}
}
