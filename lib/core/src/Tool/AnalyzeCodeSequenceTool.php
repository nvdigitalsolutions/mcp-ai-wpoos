<?php
/** Analyze Code Sequence — code analysis prompt builder. @package Nvoos\Core @since 2.0.0 @license MIT */
declare(strict_types=1); namespace Nvoos\Core\Tool;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
class AnalyzeCodeSequenceTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e ) { parent::__construct( $e ); }
	public function getSlug(): string { return 'analyze_code_sequence'; }
	public function getName(): string { return 'Analyze Code Sequence'; }
	public function getDescription(): string { return 'Analyzes code for optimization, validation, and security issues.'; }
	public function getParametersSchema(): array {
		return array('type'=>'object','properties'=>array( 'code'=>array('type'=>'string','description'=>'Code to analyze.'), 'language'=>array('type'=>'string','description'=>'Programming language.','default'=>'php') ),'required'=>array('code'),'additionalProperties'=>false);
	}
	public function getRequiredCapability(): string { return 'edit_posts'; }
	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$code = $this->stringParam( $arguments, 'code' );
		if ( '' === $code ) return $this->errors->validationFailed( 'Code is required.', array('code'=>array('Required.')) );
		$lang = $this->stringParam( $arguments, 'language', 'php' );
		return $this->success( 'Code analysis prompt prepared.', array( 'prompt'=>"Analyze this {$lang} code for bugs, security issues, performance problems, and style improvements. Provide specific recommendations.\n\n```{$lang}\n{$code}\n```", 'language'=>$lang, 'code_length'=>\strlen($code) ) );
	}
}
