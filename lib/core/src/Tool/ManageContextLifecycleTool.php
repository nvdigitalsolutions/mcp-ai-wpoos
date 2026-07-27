<?php
/** Manage Context Lifecycle — update, refresh, prune, delete agent memories. @package Nvoos\Core @since 2.0.0 @license MIT */
declare(strict_types=1); namespace Nvoos\Core\Tool;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface; use Nvoos\Core\Domain\Contract\MemoryStoreInterface;
class ManageContextLifecycleTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e, private readonly MemoryStoreInterface $m ) { parent::__construct( $e ); }
	public function getSlug(): string { return 'manage_context_lifecycle'; }
	public function getName(): string { return 'Manage Context Lifecycle'; }
	public function getDescription(): string { return 'Updates, deletes, or prunes agent context memories.'; }
	public function getParametersSchema(): array { return array('type'=>'object','properties'=>array('action'=>array('type'=>'string','description'=>'Action.','enum'=>array('update','delete','prune')), 'memory_id'=>array('type'=>'string','description'=>'Memory ID to act on.'), 'patch'=>array('type'=>'object','description'=>'Fields to update (for update action).'), 'agent_id'=>array('type'=>array('integer','string'),'description'=>'Agent ID (for prune).'), ),'required'=>array('action','memory_id'),'additionalProperties'=>false); }
	public function getRequiredCapability(): string { return 'edit_posts'; }
	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$action = $this->stringParam($arguments,'action'); $memoryId = $this->stringParam($arguments,'memory_id');
		if ( '' === $action || '' === $memoryId ) return $this->errors->validationFailed( 'action and memory_id required.', array('action'=>array('Required.'),'memory_id'=>array('Required.')) );
		try { return match($action) {
			'update' => ($r=$this->m->update($memoryId,$this->arrayParam($arguments,'patch'))), $this->success($r['success']?'Updated.':'Update failed.',$r),
			'delete' => ($r=$this->m->delete($memoryId)), $this->success($r['success']?'Deleted.':'Delete failed.',$r),
			'prune'  => $this->doPrune($arguments),
			default  => $this->errors->validationFailed('Invalid action.',array('action'=>array('Must be update, delete, or prune.')))
		}; } catch ( \Throwable $e ) { return $this->errors->create( 'lifecycle_failed', $e->getMessage() ); }
	}
	private function doPrune( array $arguments ): mixed {
		$agentId = (string)($arguments['agent_id']??''); if ( '' === $agentId ) return $this->errors->validationFailed( 'agent_id required for prune.', array('agent_id'=>array('Required.')) );
		$memories = $this->m->listByAgent( $agentId, array(), 100 )['memories'] ?? array();
		$pruned = 0; foreach ( $memories as $mem ) { if ( 'archival' === ($mem['tier']??'') ) { $this->m->delete( $mem['memory_id']??'' ); $pruned++; } }
		return $this->success( "Pruned {$pruned} archival memories." );
	}
}
