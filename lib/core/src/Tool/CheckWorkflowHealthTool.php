<?php
/** Check Workflow Health. @package Nvoos\Core @since 2.0.0 @license MIT */
declare(strict_types=1); namespace Nvoos\Core\Tool;
use Nvoos\Core\Domain\Contract\AgentOrchestrationInterface; use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
class CheckWorkflowHealthTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e, private readonly AgentOrchestrationInterface $o ) { parent::__construct( $e ); }
	public function getSlug(): string { return 'check_workflow_health'; } public function getName(): string { return 'Check Workflow Health'; } public function getDescription(): string { return 'Checks the health and status of an agent team or workflow.'; }
	public function getParametersSchema(): array { return array('type'=>'object','properties'=>array('team_id'=>array('type'=>'string','description'=>'Team ID to check.')),'required'=>array('team_id'),'additionalProperties'=>false); }
	public function getRequiredCapability(): string { return 'edit_posts'; }
	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$teamId = $this->stringParam($arguments,'team_id'); if (''===$teamId) return $this->errors->validationFailed('Team ID required.',array('team_id'=>array('Required.')));
		try { $r = $this->o->getTeamStatus($teamId); return $r['found'] ? $this->success('Team status retrieved.',$r['team']??array()) : $this->errors->notFound('Team not found.'); }
		catch (\Throwable $e) { return $this->errors->create('health_failed',$e->getMessage()); }
	}
}
