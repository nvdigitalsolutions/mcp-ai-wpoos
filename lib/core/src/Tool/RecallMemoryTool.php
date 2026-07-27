<?php
/** Recall Memory — hierarchical memory recall via MemoryStoreInterface. @package Nvoos\Core @since 2.0.0 @license MIT */
declare(strict_types=1); namespace Nvoos\Core\Tool;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface; use Nvoos\Core\Domain\Contract\MemoryStoreInterface;
class RecallMemoryTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e, private readonly MemoryStoreInterface $m ) { parent::__construct( $e ); }
	public function getSlug(): string { return 'recall_memory'; }
	public function getName(): string { return 'Recall Memory (Hierarchical)'; }
	public function getDescription(): string { return 'Hierarchical memory recall filtered by agent, wing, room, and tier, with semantic ranking.'; }
	public function getParametersSchema(): array { return array('type'=>'object','properties'=>array('agent_id'=>array('type'=>array('integer','string'),'description'=>'Agent ID.'), 'wing'=>array('type'=>'string','description'=>'Wing scope (project/client/matter).'), 'room'=>array('type'=>'string','description'=>'Optional room sub-scope.'), 'query'=>array('type'=>'string','description'=>'Optional semantic search query.'), 'limit'=>array('type'=>'integer','description'=>'Max results (1-50).','minimum'=>1,'maximum'=>50,'default'=>10), 'include_tiers'=>array('type'=>'array','description'=>'Tiers to include.','items'=>array('type'=>'string','enum'=>array('core','recall','archival')),'default'=>array('core','recall')) ),'required'=>array('agent_id','wing'),'additionalProperties'=>false); }
	public function getRequiredCapability(): string { return 'edit_posts'; }
	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$agentId = (string)($arguments['agent_id']??''); $wing = $this->stringParam($arguments,'wing'); $room = $this->stringParam($arguments,'room'); $query = $this->stringParam($arguments,'query'); $limit = \max(1,\min(50,$this->intParam($arguments,'limit',10)));
		if ( '' === $agentId || '' === $wing ) return $this->errors->validationFailed( 'agent_id and wing are required.', array('agent_id'=>array('Required.'),'wing'=>array('Required.')) );

		$filters = array( 'wing'=>$wing, 'tier'=>$this->arrayParam($arguments,'include_tiers',array('core','recall')) );
		if ( '' !== $room ) $filters['room'] = $room;

		try {
			$results = '' !== $query ? $this->m->search( $query, $filters, $limit ) : $this->m->listByAgent( $agentId, $filters, $limit )['memories'] ?? array();
			return $this->success( 'Memory recall complete.', array( 'wing'=>$wing, 'room'=>$room, 'memories'=>$results, 'count'=>\count($results) ) );
		} catch ( \Throwable $e ) { return $this->errors->create( 'recall_failed', $e->getMessage() ); }
	}
}
