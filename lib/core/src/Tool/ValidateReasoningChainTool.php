<?php
/** Validate Reasoning Chain. @package Nvoos\Core @since 2.0.0 @license MIT */
declare(strict_types=1); namespace Nvoos\Core\Tool;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
class ValidateReasoningChainTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e ) { parent::__construct( $e ); }
	public function getSlug(): string { return 'validate_reasoning_chain'; } public function getName(): string { return 'Validate Reasoning Chain'; } public function getDescription(): string { return 'Validates a chain of reasoning steps for logical consistency.'; }
	public function getParametersSchema(): array { return array('type'=>'object','properties'=>array('chain'=>array('type'=>'array','description'=>'Array of reasoning steps.','items'=>array('type'=>'object','properties'=>array('step'=>array('type'=>'integer'),'premise'=>array('type'=>'string'),'conclusion'=>array('type'=>'string'))))),'required'=>array('chain'),'additionalProperties'=>false); }
	public function getRequiredCapability(): string { return 'edit_posts'; }
	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$chain = $this->arrayParam($arguments,'chain'); if (array()===$chain) return $this->errors->validationFailed('Reasoning chain required.',array('chain'=>array('Required.')));
		return $this->success( 'Reasoning chain validated.', array('step_count'=>\count($chain),'prompt'=>'Analyze this reasoning chain for logical fallacies, gaps, or inconsistencies. Provide feedback on each step.'."\n\nChain: ".\json_encode($chain)) );
	}
}
