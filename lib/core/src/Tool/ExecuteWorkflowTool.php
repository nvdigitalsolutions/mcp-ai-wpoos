<?php
/** Execute Workflow. @package Nvoos\Core @since 2.0.0 @license MIT */
declare(strict_types=1); namespace Nvoos\Core\Tool;
use Nvoos\Core\Domain\Contract\AgentOrchestrationInterface; use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
class ExecuteWorkflowTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e, private readonly AgentOrchestrationInterface $o ) { parent::__construct( $e ); }
	public function getSlug(): string { return 'execute_workflow'; } public function getName(): string { return 'Execute Workflow'; } public function getDescription(): string { return 'Executes a workflow with an assembled agent team.'; }
	public function getParametersSchema(): array { return array('type'=>'object','properties'=>array('team_id'=>array('type'=>'string','description'=>'Team ID.'), 'workflow'=>array('type'=>'object','description'=>'Workflow definition with steps.'), ),'required'=>array('team_id','workflow'),'additionalProperties'=>false); }
	public function getRequiredCapability(): string { return 'edit_posts'; }
	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$teamId = $this->stringParam($arguments,'team_id'); $workflow = $this->arrayParam($arguments,'workflow');
		if (''===$teamId||array()===$workflow) return $this->errors->validationFailed('team_id and workflow required.',array('team_id'=>array('Required.'),'workflow'=>array('Required.')));
		try { $r = $this->o->executeWorkflow($teamId,$workflow,$context); return $r['success'] ? $this->success('Workflow executed.',array('results'=>$r['results']??array())) : $this->errors->create('workflow_failed',$r['error']??'Unknown error.'); }
		catch (\Throwable $e) { return $this->errors->create('workflow_failed',$e->getMessage()); }
	}
}
