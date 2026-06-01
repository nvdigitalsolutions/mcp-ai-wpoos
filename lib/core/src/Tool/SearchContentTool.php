<?php
/** @package Oos\Core @since 1.0.0 @license MIT */
declare(strict_types=1);
namespace Oos\Core\Tool;

use Oos\Core\Domain\Contract\ErrorFactoryInterface;
use Oos\Core\Domain\Contract\ContentStoreInterface;
use Oos\Core\Domain\Entity\ContentQuery;
class SearchContentTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e, private readonly ContentStoreInterface $c ) {
		parent::__construct( $e );}
	public function getSlug(): string {
		return 'search_content'; }
	public function getName(): string {
		return 'Search Content'; }
	public function getDescription(): string {
		return 'Searches content items by title and body text.'; }
	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'query' => array(
					'type'        => 'string',
					'description' => 'Search query',
				),
				'type'  => array(
					'type'        => 'string',
					'description' => 'Content type slug. Default: any.',
					'default'     => 'any',
				),
				'count' => array(
					'type'        => 'integer',
					'description' => 'Results per page (1-50). Default: 10.',
					'minimum'     => 1,
					'maximum'     => 50,
					'default'     => 10,
				),
				'page'  => array(
					'type'        => 'integer',
					'description' => 'Page number. Default: 1.',
					'minimum'     => 1,
					'default'     => 1,
				),
			),
			'required'             => array( 'query' ),
			'additionalProperties' => false,
		); }
	public function getRequiredCapability(): string {
		return 'read'; }
	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$query = $this->stringParam( $arguments, 'query' );
		if ( '' === $query ) {
			return $this->errors->validationFailed( 'query is required.', array( 'query' => array( 'Search query is required.' ) ) );
		}
		$type   = $this->stringParam( $arguments, 'type', 'any' );
		$types  = 'any' === $type ? array() : array( $type );
		$cq     = new ContentQuery( types:$types, statuses:array( 'publish' ), search:$query, perPage:$this->intParam( $arguments, 'count', 10 ), page:$this->intParam( $arguments, 'page', 1 ), userId:$context['user_id'] ?? null );
		$result = $this->c->query( $cq );
		if ( ! $result->hasItems() ) {
			return $this->emptyResult( "No results found for: {$query}" );
		}
		$items = array_map(
			fn( $i )=>array(
				'id'         => $i->id,
				'title'      => $i->title,
				'type'       => $i->type,
				'excerpt'    => $i->excerpt,
				'slug'       => $i->slug,
				'created_at' => $i->createdAt->format( 'c' ),
			),
			$result->items
		);
		return $this->collection( "Found {$result->total} results for: {$query}", $items, $result->total );
	}
}
