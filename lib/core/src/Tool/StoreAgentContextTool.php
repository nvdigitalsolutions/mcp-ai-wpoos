<?php
/** Store Agent Context — persist agent memory. @package Nvoos\Core @since 2.0.0 @license MIT */
declare(strict_types=1); namespace Nvoos\Core\Tool;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface; use Nvoos\Core\Domain\Contract\MemoryStoreInterface;
class StoreAgentContextTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e, private readonly MemoryStoreInterface $m ) { parent::__construct( $e ); }
	public function getSlug(): string { return 'store_agent_context'; }
	public function getName(): string { return 'Store Agent Context'; }
	public function getDescription(): string { return 'Stores agent context/memory with tier, tags, and optional TTL.'; }
	public function getParametersSchema(): array { return array('type'=>'object','properties'=>array('agent_id'=>array('type'=>array('integer','string'),'description'=>'Agent ID.'), 'content'=>array('type'=>'string','description'=>'Memory content.'), 'title'=>array('type'=>'string','description'=>'Optional title.'), 'tags'=>array('type'=>'array','description'=>'Optional tags.','items'=>array('type'=>'string')), 'tier'=>array('type'=>'string','description'=>'Memory tier.','enum'=>array('core','recall','archival'),'default'=>'recall'), 'importance'=>array('type'=>'number','description'=>'Importance score (0-1).','minimum'=>0,'maximum'=>1,'default'=>0.5), 'wing'=>array('type'=>'string','description'=>'Wing scope.'), 'room'=>array('type'=>'string','description'=>'Room sub-scope.'), 'ttl'=>array('type'=>'integer','description'=>'TTL in seconds.'), ),'required'=>array('agent_id','content'),'additionalProperties'=>false); }
	public function getRequiredCapability(): string { return 'edit_posts'; }
	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$agentId = (string)($arguments['agent_id']??''); $content = $this->stringParam($arguments,'content');
		if ( '' === $agentId || '' === $content ) return $this->errors->validationFailed( 'agent_id and content required.', array('agent_id'=>array('Required.'),'content'=>array('Required.')) );
		$record = array( 'agent_id'=>$agentId, 'content'=>$content, 'title'=>$this->stringParam($arguments,'title'), 'tags'=>$this->arrayParam($arguments,'tags'), 'tier'=>$this->stringParam($arguments,'tier','recall'), 'importance'=>(float)($arguments['importance']??0.5), 'wing'=>$this->stringParam($arguments,'wing'), 'room'=>$this->stringParam($arguments,'room') );
		$ttl = $this->intParam( $arguments, 'ttl' ); if ( $ttl > 0 ) $record['ttl'] = $ttl;
		try { $r = $this->m->store( $record ); return $r['success'] ? $this->success( 'Memory stored.', array('memory_id'=>$r['memory_id']??null) ) : $this->errors->create( 'store_failed', $r['error']??'Unknown error.' ); }
		catch ( \Throwable $e ) { return $this->errors->create( 'store_failed', $e->getMessage() ); }
	}
}
