<?php
/** Retrieve Agent Memory — get specific memory by ID or list by agent. @package Nvoos\Core @since 2.0.0 @license MIT */
declare(strict_types=1); namespace Nvoos\Core\Tool;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface; use Nvoos\Core\Domain\Contract\MemoryStoreInterface;
class RetrieveAgentMemoryTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e, private readonly MemoryStoreInterface $m ) { parent::__construct( $e ); }
	public function getSlug(): string { return 'retrieve_agent_memory'; }
	public function getName(): string { return 'Retrieve Agent Memory'; }
	public function getDescription(): string { return 'Retrieves agent memory by ID or lists all memories for an agent.'; }
	public function getParametersSchema(): array { return array('type'=>'object','properties'=>array('agent_id'=>array('type'=>array('integer','string'),'description'=>'Agent ID.'), 'memory_id'=>array('type'=>'string','description'=>'Specific memory ID to retrieve.'), 'limit'=>array('type'=>'integer','description'=>'Max results when listing.','minimum'=>1,'maximum'=>100,'default'=>50), 'tier'=>array('type'=>'string','description'=>'Filter by tier.'), ),'required'=>array('agent_id'),'additionalProperties'=>false); }
	public function getRequiredCapability(): string { return 'edit_posts'; }
	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$agentId = (string)($arguments['agent_id']??''); if ( '' === $agentId ) return $this->errors->validationFailed( 'agent_id required.', array('agent_id'=>array('Required.')) );
		$memoryId = $this->stringParam( $arguments, 'memory_id' );
		try {
			if ( '' !== $memoryId ) { $r = $this->m->get( $memoryId ); return $r['found'] ? $this->success( 'Memory retrieved.', $r['record']??array() ) : $this->errors->notFound( 'Memory not found.' ); }
			$filters = array(); $tier = $this->stringParam($arguments,'tier'); if ( '' !== $tier ) $filters['tier'] = $tier;
			$r = $this->m->listByAgent( $agentId, $filters, \max(1,\min(100,$this->intParam($arguments,'limit',50))) );
			return $this->collection( 'Found '.\count($r['memories']??array()).' memories.', $r['memories']??array(), $r['total']??0 );
		} catch ( \Throwable $e ) { return $this->errors->create( 'retrieve_failed', $e->getMessage() ); }
	}
}
