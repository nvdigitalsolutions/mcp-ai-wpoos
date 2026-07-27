<?php
/** Resize Image. @package Nvoos\Core @since 2.0.0 @license MIT */
declare(strict_types=1); namespace Nvoos\Core\Tool;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\ImageProcessingInterface;

class ResizeImageTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e, private readonly ImageProcessingInterface $img ) { parent::__construct( $e ); }
	public function getSlug(): string { return 'resize_image'; }
	public function getName(): string { return 'Resize Image'; }
	public function getDescription(): string { return 'Resize an image to specified dimensions with optional cropping.'; }
	public function getParametersSchema(): array {
		return array('type'=>'object','properties'=>array(
			'image_url'=>array('type'=>'string','description'=>'URL or path of the source image.'),
			'width'=>array('type'=>'integer','description'=>'Target width in pixels.','minimum'=>1),
			'height'=>array('type'=>'integer','description'=>'Target height in pixels.','minimum'=>1),
			'crop'=>array('type'=>'boolean','description'=>'Whether to crop to exact dimensions. Default: true.','default'=>true),
			'quality'=>array('type'=>'integer','description'=>'Output quality (1-100).','minimum'=>1,'maximum'=>100),
		),'required'=>array('image_url','width','height'),'additionalProperties'=>false);
	}
	public function getRequiredCapability(): string { return 'upload_files'; }

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$imageUrl = $this->stringParam( $arguments, 'image_url' );
		$width    = $this->intParam( $arguments, 'width' );
		$height   = $this->intParam( $arguments, 'height' );
		if ( '' === $imageUrl || $width <= 0 || $height <= 0 ) return $this->errors->validationFailed( 'image_url, width, and height are required.', array('image_url'=>array('Required.'),'width'=>array('Required.'),'height'=>array('Required.')) );

		if ( ! $this->img->isAvailable() ) return $this->errors->create( 'no_library', 'No image processing library available.' );

		try {
			$crop = !isset($arguments['crop']) || (bool)$arguments['crop'];
			$quality = $arguments['quality'] ?? null;
			$result = $this->img->resize( $imageUrl, $width, $height, array( 'crop'=>$crop, 'quality'=>$quality ) );
			return $this->success( 'Image resized successfully.', array( 'width'=>$result['width'], 'height'=>$result['height'], 'mime_type'=>$result['mime_type'], 'bytes'=>$result['bytes'] ) );
		} catch ( \Throwable $e ) { return $this->errors->create( 'resize_failed', $e->getMessage() ); }
	}
}
