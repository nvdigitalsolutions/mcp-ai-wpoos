<?php
/** Semantic Content Search — semantic search across content. @package Nvoos\Core @since 2.0.0 @license MIT */
declare(strict_types=1); namespace Nvoos\Core\Tool;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface; use Nvoos\Core\Domain\Contract\MemoryStoreInterface;
class SemanticContentSearchTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e, private readonly MemoryStoreInterface $m ) { parent::__construct( $e ); }
	public function getSlug(): string { return 'semantic_content_search'; }
	public function getName(): string { return 'Semantic Content Search'; }
	public function getDescription(): string { return 'Searches content using semantic similarity via memory store.'; }
	public function getParametersSchema(): array { return array('type'=>'object','properties'=>array('query'=>array('type'=>'string','description'=>'Search query.'), 'limit'=>array('type'=>'integer','description'=>'Max results.','minimum'=>1,'maximum'=>50,'default'=>10), ),'required'=>array('query'),'additionalProperties'=>false); }
	public function getRequiredCapability(): string { return 'edit_posts'; }
	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$query = $this->stringParam($arguments,'query'); if ( '' === $query ) return $this->errors->validationFailed( 'Query required.', array('query'=>array('Required.')) );
		$limit = \max(1,\min(50,$this->intParam($arguments,'limit',10)));
		try { $results = $this->m->search( $query, array(), $limit ); return $this->collection( 'Found '.\count($results).' results.', $results, \count($results) ); }
		catch ( \Throwable $e ) { return $this->errors->create( 'search_failed', $e->getMessage() ); }
	}
}
