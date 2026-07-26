<?php
/** Generate Image Alt Text — AI alt-text generation. @package Nvoos\Core @since 2.0.0 @license MIT */
declare(strict_types=1);
namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;

class GenerateImageAltTextTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e ) { parent::__construct( $e ); }
	public function getSlug(): string { return 'generate_image_alt_text'; }
	public function getName(): string { return 'Generate Image Alt Text'; }
	public function getDescription(): string { return 'Generates descriptive alt text for images using AI vision for accessibility.'; }
	public function getParametersSchema(): array {
		return array('type'=>'object','properties'=>array( 'image_url'=>array('type'=>'string','description'=>'URL of the image.'), 'context'=>array('type'=>'string','description'=>'Optional context about where the image is used.') ),'additionalProperties'=>false);
	}
	public function getRequiredCapability(): string { return 'read'; }

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$imageUrl = $this->stringParam( $arguments, 'image_url' );
		$ctx      = $this->stringParam( $arguments, 'context' );
		if ( '' === $imageUrl ) return $this->errors->validationFailed( 'An image URL is required.', array('image_url'=>array('Required.')) );

		$prompt = 'Generate concise, descriptive alt text for this image suitable for accessibility (125 characters or fewer). Describe the key visual elements.';
		if ( '' !== $ctx ) $prompt = "Context: {$ctx}\\n\\n{$prompt}";
		return $this->success( 'Alt text prompt prepared.', array( 'image_url'=>$imageUrl, 'prompt'=>$prompt ) );
	}
}
