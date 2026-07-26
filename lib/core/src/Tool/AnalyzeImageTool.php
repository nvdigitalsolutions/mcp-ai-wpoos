<?php
/** Analyze Image — AI vision analysis prompt builder. @package Nvoos\Core @since 2.0.0 @license MIT */
declare(strict_types=1);
namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;

class AnalyzeImageTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e ) { parent::__construct( $e ); }
	public function getSlug(): string { return 'analyze_image'; }
	public function getName(): string { return 'Analyze Image'; }
	public function getDescription(): string { return 'Analyzes image content using AI vision to extract descriptions, objects, text, and insights.'; }
	public function getParametersSchema(): array {
		return array('type'=>'object','properties'=>array(
			'image_url'=>array('type'=>'string','description'=>'URL of the image to analyze.'),
			'prompt'=>array('type'=>'string','description'=>'Specific question or analysis prompt.'),
		),'additionalProperties'=>false);
	}
	public function getRequiredCapability(): string { return 'read'; }

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$imageUrl = $this->stringParam( $arguments, 'image_url' );
		$prompt   = $this->stringParam( $arguments, 'prompt' );
		if ( '' === $imageUrl ) return $this->errors->validationFailed( 'An image URL is required.', array('image_url'=>array('Required.')) );

		$analysisPrompt = '' !== $prompt ? $prompt : 'Please analyze this image and provide a detailed description including subjects, objects, setting, colors, text, and overall composition.';
		return $this->success( 'Image analysis prompt prepared.', array( 'image_url'=>$imageUrl, 'prompt'=>$analysisPrompt ) );
	}
}
