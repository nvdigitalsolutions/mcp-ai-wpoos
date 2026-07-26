<?php
/** Crop Image. @package Nvoos\Core @since 2.0.0 @license MIT */
declare(strict_types=1); namespace Nvoos\Core\Tool;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\ImageProcessingInterface;

class CropImageTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e, private readonly ImageProcessingInterface $img ) { parent::__construct( $e ); }
	public function getSlug(): string { return 'crop_image'; }
	public function getName(): string { return 'Crop Image'; }
	public function getDescription(): string { return 'Crop an image to a specific region by providing x, y, width, and height.'; }
	public function getParametersSchema(): array {
		return array('type'=>'object','properties'=>array(
			'image_url'=>array('type'=>'string','description'=>'URL or path of the source image.'),
			'x'=>array('type'=>'integer','description'=>'X offset from top-left.','minimum'=>0,'default'=>0),
			'y'=>array('type'=>'integer','description'=>'Y offset from top-left.','minimum'=>0,'default'=>0),
			'width'=>array('type'=>'integer','description'=>'Crop width in pixels.','minimum'=>1),
			'height'=>array('type'=>'integer','description'=>'Crop height in pixels.','minimum'=>1),
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
			$x = $this->intParam( $arguments, 'x', 0 );
			$y = $this->intParam( $arguments, 'y', 0 );
			$result = $this->img->crop( $imageUrl, $x, $y, $width, $height );
			return $this->success( 'Image cropped successfully.', array( 'x'=>$x, 'y'=>$y, 'width'=>$result['width'], 'height'=>$result['height'], 'mime_type'=>$result['mime_type'], 'bytes'=>$result['bytes'] ) );
		} catch ( \Throwable $e ) { return $this->errors->create( 'crop_failed', $e->getMessage() ); }
	}
}
