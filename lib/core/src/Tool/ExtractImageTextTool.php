<?php
/** Extract Image Text — OCR via AI vision. @package Nvoos\Core @since 2.0.0 @license MIT */
declare(strict_types=1); namespace Nvoos\Core\Tool;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
class ExtractImageTextTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e ) { parent::__construct( $e ); }
	public function getSlug(): string { return 'extract_image_text'; }
	public function getName(): string { return 'Extract Image Text'; }
	public function getDescription(): string { return 'Extracts text from images using AI vision (OCR).'; }
	public function getParametersSchema(): array { return array('type'=>'object','properties'=>array('image_url'=>array('type'=>'string','description'=>'URL of the image.')),'additionalProperties'=>false); }
	public function getRequiredCapability(): string { return 'read'; }
	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$url = $this->stringParam( $arguments, 'image_url' );
		if ( '' === $url ) return $this->errors->validationFailed( 'An image URL is required.', array('image_url'=>array('Required.')) );
		return $this->success( 'OCR prompt prepared.', array( 'image_url'=>$url, 'prompt'=>'Extract all visible text from this image. Return the text exactly as it appears, preserving formatting where possible.' ) );
	}
}
