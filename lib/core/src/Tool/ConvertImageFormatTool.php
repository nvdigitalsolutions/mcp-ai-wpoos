<?php
/** Convert Image Format. @package Nvoos\Core @since 2.0.0 @license MIT */
declare(strict_types=1); namespace Nvoos\Core\Tool;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\ImageProcessingInterface;

class ConvertImageFormatTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e, private readonly ImageProcessingInterface $img ) { parent::__construct( $e ); }
	public function getSlug(): string { return 'convert_image_format'; }
	public function getName(): string { return 'Convert Image Format'; }
	public function getDescription(): string { return 'Convert an image to a different format (PNG, JPEG, WebP, GIF) with optional quality control.'; }
	public function getParametersSchema(): array {
		return array('type'=>'object','properties'=>array(
			'image_url'=>array('type'=>'string','description'=>'URL or path of the source image.'),
			'format'=>array('type'=>'string','description'=>'Target format.','enum'=>array('png','jpeg','jpg','webp','gif')),
			'quality'=>array('type'=>'integer','description'=>'Output quality (1-100).','minimum'=>1,'maximum'=>100,'default'=>90),
		),'required'=>array('image_url','format'),'additionalProperties'=>false);
	}
	public function getRequiredCapability(): string { return 'upload_files'; }

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$imageUrl = $this->stringParam( $arguments, 'image_url' );
		$format   = \strtolower($this->stringParam( $arguments, 'format' ));
		if ( '' === $imageUrl || '' === $format ) return $this->errors->validationFailed( 'image_url and format are required.', array('image_url'=>array('Required.'),'format'=>array('Required.')) );
		if ( 'jpg' === $format ) $format = 'jpeg';

		$quality = \max(1,\min(100,$this->intParam($arguments,'quality',90)));

		if ( ! $this->img->isAvailable() ) return $this->errors->create( 'no_library', 'No image processing library (GD or Imagick) is available.' );

		try {
			$result = $this->img->convert( $imageUrl, $format, $quality );
			return $this->success( "Image converted to {$format}.", array( 'format'=>$format, 'quality'=>$quality, 'width'=>$result['width'], 'height'=>$result['height'], 'mime_type'=>$result['mime_type'], 'bytes'=>$result['bytes'] ) );
		} catch ( \Throwable $e ) { return $this->errors->create( 'convert_failed', $e->getMessage() ); }
	}
}
