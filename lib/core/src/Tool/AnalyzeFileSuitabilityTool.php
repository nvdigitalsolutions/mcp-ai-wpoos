<?php
/** Analyze File Suitability — file analysis prompt builder. @package Nvoos\Core @since 2.0.0 @license MIT */
declare(strict_types=1); namespace Nvoos\Core\Tool;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
class AnalyzeFileSuitabilityTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e ) { parent::__construct( $e ); }
	public function getSlug(): string { return 'analyze_file_suitability'; }
	public function getName(): string { return 'Analyze File Suitability'; }
	public function getDescription(): string { return 'Analyzes file content to determine suitability for AI processing and provides recommendations.'; }
	public function getParametersSchema(): array {
		return array('type'=>'object','properties'=>array( 'file_name'=>array('type'=>'string','description'=>'File name with extension.'), 'file_content'=>array('type'=>'string','description'=>'File content or excerpt to analyze.'), 'file_size'=>array('type'=>'integer','description'=>'File size in bytes.'), 'intended_use'=>array('type'=>'string','description'=>'Intended AI use case (RAG, embedding, etc.).') ),'required'=>array('file_name'),'additionalProperties'=>false);
	}
	public function getRequiredCapability(): string { return 'edit_posts'; }
	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$name = $this->stringParam( $arguments, 'file_name' );
		if ( '' === $name ) return $this->errors->validationFailed( 'File name is required.', array('file_name'=>array('Required.')) );
		$content = $this->stringParam( $arguments, 'file_content' );
		$size    = $this->intParam( $arguments, 'file_size' );
		$use     = $this->stringParam( $arguments, 'intended_use', 'RAG' );
		$ext     = \strtolower(\pathinfo($name, \PATHINFO_EXTENSION));
		$supported = \in_array($ext, array('pdf','txt','md','json','html','docx','csv'), true);
		return $this->success( 'File analysis prompt prepared.', array( 'file_name'=>$name, 'extension'=>$ext, 'supported_format'=>$supported, 'file_size'=>$size, 'prompt'=>"Analyze this file ({$name}, {$size} bytes) for suitability as {$use} data." . ($content?"\nContent excerpt: ".substr($content,0,1000):'') ) );
	}
}
