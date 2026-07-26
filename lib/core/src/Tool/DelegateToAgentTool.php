<?php
/** Delegate to Agent. @package Nvoos\Core @since 2.0.0 @license MIT */
declare(strict_types=1); namespace Nvoos\Core\Tool;
use Nvoos\Core\Domain\Contract\AgentOrchestrationInterface; use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
class DelegateToAgentTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e, private readonly AgentOrchestrationInterface $o ) { parent::__construct( $e ); }
	public function getSlug(): string { return 'delegate_to_agent'; } public function getName(): string { return 'Delegate to Agent'; } public function getDescription(): string { return 'Delegates a subtask to a specific agent within a team.'; }
	public function getParametersSchema(): array { return array('type'=>'object','properties'=>array('team_id'=>array('type'=>'string','description'=>'Team ID.'), 'agent_id'=>array('type'=>'string','description'=>'Agent ID.'), 'task'=>array('type'=>'object','description'=>'Task definition with instructions and parameters.')),'required'=>array('team_id','agent_id','task'),'additionalProperties'=>false); }
	public function getRequiredCapability(): string { return 'edit_posts'; }
	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$teamId = $this->stringParam($arguments,'team_id'); $agentId = $this->stringParam($arguments,'agent_id'); $task = $this->arrayParam($arguments,'task');
		if (''===$teamId||''===$agentId||array()===$task) return $this->errors->validationFailed('team_id, agent_id, and task required.',array('team_id'=>array('Required.'),'agent_id'=>array('Required.'),'task'=>array('Required.')));
		try { $r = $this->o->delegateToAgent($teamId,$agentId,$task,$context); return $r['success'] ? $this->success('Task delegated.',array('result'=>$r['result']??null)) : $this->errors->create('delegate_failed',$r['error']??'Unknown error.'); }
		catch (\Throwable $e) { return $this->errors->create('delegate_failed',$e->getMessage()); }
	}
}
