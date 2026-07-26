<?php
/** Generate Image Caption — AI image captioning. @package Nvoos\Core @since 2.0.0 @license MIT */
declare(strict_types=1);
namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;

class GenerateImageCaptionTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e ) { parent::__construct( $e ); }
	public function getSlug(): string { return 'generate_image_caption'; }
	public function getName(): string { return 'Generate Image Caption'; }
	public function getDescription(): string { return 'Generates descriptive captions for images using AI vision.'; }
	public function getParametersSchema(): array {
		return array('type'=>'object','properties'=>array( 'image_url'=>array('type'=>'string','description'=>'URL of the image.'), 'context'=>array('type'=>'string','description'=>'Optional context.'), 'max_length'=>array('type'=>'integer','description'=>'Maximum caption length (50-500).','minimum'=>50,'maximum'=>500,'default'=>200) ),'additionalProperties'=>false);
	}
	public function getRequiredCapability(): string { return 'read'; }

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$imageUrl  = $this->stringParam( $arguments, 'image_url' );
		$ctx       = $this->stringParam( $arguments, 'context' );
		$maxLength = \max( 50, \min( 500, $this->intParam( $arguments, 'max_length', 200 ) ) );
		if ( '' === $imageUrl ) return $this->errors->validationFailed( 'An image URL is required.', array('image_url'=>array('Required.')) );

		$prompt = "Generate a concise, descriptive caption for this image in {$maxLength} characters or less. Describe the main subjects, setting, and mood.";
		if ( '' !== $ctx ) $prompt = "Context: {$ctx}\\n\\n{$prompt}";
		return $this->success( 'Caption prompt prepared.', array( 'image_url'=>$imageUrl, 'prompt'=>$prompt, 'max_length'=>$maxLength ) );
	}
}
