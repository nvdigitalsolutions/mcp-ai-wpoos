<?php
/** Run OpenAI External Action. @package Nvoos\Core @since 2.0.0 @license MIT */
declare(strict_types=1); namespace Nvoos\Core\Tool;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface; use Nvoos\Core\Domain\Contract\HttpClientInterface; use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
class RunOpenAIExternalActionTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e, private readonly SettingsStoreInterface $s, private readonly HttpClientInterface $h ) { parent::__construct( $e ); }
	public function getSlug(): string { return 'run_openai_external_action'; } public function getName(): string { return 'Run OpenAI External Action'; } public function getDescription(): string { return 'Runs an external action using OpenAI function calling.'; }
	public function getParametersSchema(): array { return array('type'=>'object','properties'=>array('action'=>array('type'=>'string','description'=>'Action name or endpoint.'), 'params'=>array('type'=>'object','description'=>'Action parameters.')),'required'=>array('action'),'additionalProperties'=>false); }
	public function getRequiredCapability(): string { return 'edit_posts'; }
	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$action = $this->stringParam($arguments,'action'); if (''===$action) return $this->errors->validationFailed('Action required.',array('action'=>array('Required.')));
		$params = $this->arrayParam($arguments,'params');
		return $this->success( 'External action prepared.', array('action'=>$action,'params'=>$params,'prompt'=>'Execute this external action with the given parameters.') );
	}
}
