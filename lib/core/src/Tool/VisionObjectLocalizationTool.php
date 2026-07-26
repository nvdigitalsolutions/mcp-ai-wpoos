<?php
/** Vision Object Localization — detect objects in images. @package Nvoos\Core @since 2.0.0 @license MIT */
declare(strict_types=1); namespace Nvoos\Core\Tool;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
class VisionObjectLocalizationTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e ) { parent::__construct( $e ); }
	public function getSlug(): string { return 'vision_object_localization'; }
	public function getName(): string { return 'Vision Object Localization'; }
	public function getDescription(): string { return 'Detects and localizes objects in images using AI vision.'; }
	public function getParametersSchema(): array { return array('type'=>'object','properties'=>array('image_url'=>array('type'=>'string','description'=>'URL of the image.'),'objects'=>array('type'=>'array','description'=>'Specific objects to look for.','items'=>array('type'=>'string'))),'additionalProperties'=>false); }
	public function getRequiredCapability(): string { return 'read'; }
	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$url = $this->stringParam( $arguments, 'image_url' );
		if ( '' === $url ) return $this->errors->validationFailed( 'An image URL is required.', array('image_url'=>array('Required.')) );
		$objects = $this->arrayParam( $arguments, 'objects' );
		$prompt = array() !== $objects ? 'Detect and localize these objects in the image: '.\implode(', ',$objects).'. Describe their positions and bounding regions.' : 'Detect and describe all objects visible in this image, noting their positions.';
		return $this->success( 'Object detection prompt prepared.', array( 'image_url'=>$url, 'prompt'=>$prompt ) );
	}
}
