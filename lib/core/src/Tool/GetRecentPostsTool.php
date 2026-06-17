<?php
/** @package Nvoos\Core @since 1.0.0 @license MIT */
declare(strict_types=1);
namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Entity\ContentQuery;
class GetRecentPostsTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e, private readonly ContentStoreInterface $c ) {
		parent::__construct( $e );}
	public function getSlug(): string {
		return 'get_recent_posts'; }
	public function getName(): string {
		return 'Get Recent Posts'; }
	public function getDescription(): string {
		return 'Retrieves the most recent content items, optionally filtered by type and count.'; }
	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'type'   => array(
					'type'        => 'string',
					'description' => 'Content type slug. Default: post.',
					'default'     => 'post',
				),
				'count'  => array(
					'type'        => 'integer',
					'description' => 'Number of posts to return (1-50). Default: 10.',
					'minimum'     => 1,
					'maximum'     => 50,
					'default'     => 10,
				),
				'status' => array(
					'type'        => 'string',
					'description' => 'Publication status. Default: publish.',
					'enum'        => array( 'publish', 'draft', 'any' ),
					'default'     => 'publish',
				),
			),
			'additionalProperties' => false,
		); }
	public function getRequiredCapability(): string {
		return 'read'; }
	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$type     = $this->stringParam( $arguments, 'type', 'post' );
		$count    = $this->intParam( $arguments, 'count', 10 );
		$status   = $this->stringParam( $arguments, 'status', 'publish' );
		$statuses = 'any' === $status ? array( 'publish', 'draft' ) : array( $status );
		$query    = new ContentQuery( types:array( $type ), statuses:$statuses, perPage:$count, page:1, userId:$context['user_id'] ?? null );
		$result   = $this->c->query( $query );
		if ( ! $result->hasItems() ) {
			return $this->emptyResult( 'No posts found.' );
		}
		$items = array_map(
			fn( $i )=>array(
				'id'         => $i->id,
				'title'      => $i->title,
				'status'     => $i->status,
				'type'       => $i->type,
				'excerpt'    => $i->excerpt,
				'created_at' => $i->createdAt->format( 'c' ),
				'slug'       => $i->slug,
			),
			$result->items
		);
		return $this->collection( 'Retrieved ' . count( $items ) . ' posts.', $items, $result->total );
	}
}
