<?php
/** Wait For User — pause agent execution for user input. @package Nvoos\Core @since 2.0.0 @license MIT */
declare(strict_types=1); namespace Nvoos\Core\Tool; use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
class WaitForUserTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e ) { parent::__construct( $e ); }
	public function getSlug(): string { return 'wait_for_user'; } public function getName(): string { return 'Wait For User'; }
	public function getDescription(): string { return 'Pauses agent execution to wait for user input or confirmation.'; }
	public function getParametersSchema(): array { return array('type'=>'object','properties'=>array('prompt'=>array('type'=>'string','description'=>'Message to display to the user while waiting.'),'timeout_seconds'=>array('type'=>'integer','description'=>'Max wait time.','minimum'=>10,'maximum'=>3600,'default'=>300)),'required'=>array('prompt'),'additionalProperties'=>false); }
	public function getRequiredCapability(): string { return 'read'; }
	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$prompt = $this->stringParam($arguments,'prompt'); if (''===$prompt) return $this->errors->validationFailed('Prompt required.',array('prompt'=>array('Required.')));
		return $this->success( 'Waiting for user input.', array('prompt'=>$prompt,'timeout'=>$this->intParam($arguments,'timeout_seconds',300),'status'=>'awaiting_user') );
	}
}
