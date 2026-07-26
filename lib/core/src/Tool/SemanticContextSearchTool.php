<?php
/** Semantic Context Search — semantic memory search. @package Nvoos\Core @since 2.0.0 @license MIT */
declare(strict_types=1); namespace Nvoos\Core\Tool;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface; use Nvoos\Core\Domain\Contract\MemoryStoreInterface;
class SemanticContextSearchTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e, private readonly MemoryStoreInterface $m ) { parent::__construct( $e ); }
	public function getSlug(): string { return 'semantic_context_search'; }
	public function getName(): string { return 'Semantic Context Search'; }
	public function getDescription(): string { return 'Searches agent contexts using semantic similarity.'; }
	public function getParametersSchema(): array { return array('type'=>'object','properties'=>array('agent_id'=>array('type'=>array('integer','string'),'description'=>'Agent ID.'), 'query'=>array('type'=>'string','description'=>'Natural language search query.'), 'limit'=>array('type'=>'integer','description'=>'Max results (1-50).','minimum'=>1,'maximum'=>50,'default'=>10), 'filters'=>array('type'=>'object','description'=>'Optional filters (context_types, importance).'), ),'required'=>array('agent_id','query'),'additionalProperties'=>false); }
	public function getRequiredCapability(): string { return 'edit_posts'; }
	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$agentId = (string)($arguments['agent_id']??''); $query = $this->stringParam($arguments,'query');
		if ( '' === $agentId || '' === $query ) return $this->errors->validationFailed( 'agent_id and query required.', array('agent_id'=>array('Required.'),'query'=>array('Required.')) );
		$filters = $this->arrayParam($arguments,'filters'); $limit = \max(1,\min(50,$this->intParam($arguments,'limit',10)));
		try { $results = $this->m->search( $query, $filters, $limit ); return $this->success( 'Found '.\count($results).' semantically similar contexts.', array('contexts'=>$results,'count'=>\count($results),'query'=>$query) ); }
		catch ( \Throwable $e ) { return $this->errors->create( 'search_failed', $e->getMessage() ); }
	}
}
