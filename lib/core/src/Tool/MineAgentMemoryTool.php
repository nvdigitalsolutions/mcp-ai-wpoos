<?php
/** Mine Agent Memory — semantic search across agent memories. @package Nvoos\Core @since 2.0.0 @license MIT */
declare(strict_types=1); namespace Nvoos\Core\Tool;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface; use Nvoos\Core\Domain\Contract\MemoryStoreInterface;
class MineAgentMemoryTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e, private readonly MemoryStoreInterface $m ) { parent::__construct( $e ); }
	public function getSlug(): string { return 'mine_agent_memory'; }
	public function getName(): string { return 'Mine Agent Memory'; }
	public function getDescription(): string { return 'Searches across agent memories using semantic or keyword queries.'; }
	public function getParametersSchema(): array { return array('type'=>'object','properties'=>array('agent_id'=>array('type'=>array('integer','string'),'description'=>'Agent ID.'), 'query'=>array('type'=>'string','description'=>'Search query.'), 'wing'=>array('type'=>'string','description'=>'Wing filter.'), 'limit'=>array('type'=>'integer','description'=>'Max results.','minimum'=>1,'maximum'=>100,'default'=>20), ),'required'=>array('agent_id'),'additionalProperties'=>false); }
	public function getRequiredCapability(): string { return 'edit_posts'; }
	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$agentId = (string)($arguments['agent_id']??''); if ( '' === $agentId ) return $this->errors->validationFailed( 'agent_id required.', array('agent_id'=>array('Required.')) );
		$query = $this->stringParam($arguments,'query'); $wing = $this->stringParam($arguments,'wing'); $limit = \max(1,\min(100,$this->intParam($arguments,'limit',20)));
		$filters = array(); if ( '' !== $wing ) $filters['wing'] = $wing;
		try {
			$results = '' !== $query ? $this->m->search( $query, $filters, $limit ) : $this->m->listByAgent( $agentId, $filters, $limit )['memories'] ?? array();
			return $this->collection( 'Found '.\count($results).' memories.', $results, \count($results) );
		} catch ( \Throwable $e ) { return $this->errors->create( 'mine_failed', $e->getMessage() ); }
	}
}
