<?php
/** Create Agent Team. @package Nvoos\Core @since 2.0.0 @license MIT */
declare(strict_types=1); namespace Nvoos\Core\Tool;
use Nvoos\Core\Domain\Contract\AgentOrchestrationInterface; use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
class CreateAgentTeamTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e, private readonly AgentOrchestrationInterface $o ) { parent::__construct( $e ); }
	public function getSlug(): string { return 'create_agent_team'; } public function getName(): string { return 'Create Agent Team'; } public function getDescription(): string { return 'Composes an agent team for a task with specific roles and requirements.'; }
	public function getParametersSchema(): array { return array('type'=>'object','properties'=>array('task_description'=>array('type'=>'string','description'=>'Description of the task.'), 'roles'=>array('type'=>'array','description'=>'Required agent roles.','items'=>array('type'=>'string')), 'priority'=>array('type'=>'string','enum'=>array('low','medium','high','critical'),'default'=>'medium')),'required'=>array('task_description'),'additionalProperties'=>false); }
	public function getRequiredCapability(): string { return 'edit_posts'; }
	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$desc = $this->stringParam($arguments,'task_description'); if (''===$desc) return $this->errors->validationFailed('Task description required.',array('task_description'=>array('Required.')));
		try { $r = $this->o->composeTeam(array('task'=>$desc,'roles'=>$this->arrayParam($arguments,'roles'),'priority'=>$this->stringParam($arguments,'priority','medium'))); return $r['success'] ? $this->success('Team composed.',$r['team']??array()) : $this->errors->create('team_failed',$r['error']??'Unknown error.'); }
		catch (\Throwable $e) { return $this->errors->create('team_failed',$e->getMessage()); }
	}
}
