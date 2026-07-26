<?php
/** Validate Workflow — validate workflow definition. @package Nvoos\Core @since 2.0.0 @license MIT */
declare(strict_types=1); namespace Nvoos\Core\Tool;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
class ValidateWorkflowTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e ) { parent::__construct( $e ); }
	public function getSlug(): string { return 'validate_workflow'; } public function getName(): string { return 'Validate Workflow'; } public function getDescription(): string { return 'Validates a workflow definition for correctness and completeness.'; }
	public function getParametersSchema(): array { return array('type'=>'object','properties'=>array('workflow'=>array('type'=>'object','description'=>'Workflow definition to validate.')),'required'=>array('workflow'),'additionalProperties'=>false); }
	public function getRequiredCapability(): string { return 'edit_posts'; }
	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$wf = $this->arrayParam($arguments,'workflow'); if (array()===$wf) return $this->errors->validationFailed('Workflow required.',array('workflow'=>array('Required.')));
		$issues = array(); $steps = $wf['steps']??$wf; if (!\is_array($steps)||array()===$steps) $issues[]='No steps defined.';
		return $this->success( array()===$issues?'Workflow is valid.':'Validation issues found.', array('valid'=>array()===$issues,'issues'=>$issues,'step_count'=>\is_array($steps)?\count($steps):0) );
	}
}
