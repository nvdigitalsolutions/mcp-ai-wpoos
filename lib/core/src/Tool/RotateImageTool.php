<?php
/** Rotate Image. @package Nvoos\Core @since 2.0.0 @license MIT */
declare(strict_types=1); namespace Nvoos\Core\Tool;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\ImageProcessingInterface;

class RotateImageTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e, private readonly ImageProcessingInterface $img ) { parent::__construct( $e ); }
	public function getSlug(): string { return 'rotate_image'; }
	public function getName(): string { return 'Rotate Image'; }
	public function getDescription(): string { return 'Rotate an image by a specified angle in degrees.'; }
	public function getParametersSchema(): array {
		return array('type'=>'object','properties'=>array(
			'image_url'=>array('type'=>'string','description'=>'URL or path of the source image.'),
			'angle'=>array('type'=>'number','description'=>'Rotation angle in degrees (clockwise).','default'=>90),
			'background'=>array('type'=>'string','description'=>'Background color hex for uncovered areas. Default: #ffffff.','default'=>'#ffffff'),
		),'required'=>array('image_url'),'additionalProperties'=>false);
	}
	public function getRequiredCapability(): string { return 'upload_files'; }

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$imageUrl = $this->stringParam( $arguments, 'image_url' );
		if ( '' === $imageUrl ) return $this->errors->validationFailed( 'image_url is required.', array('image_url'=>array('Required.')) );

		if ( ! $this->img->isAvailable() ) return $this->errors->create( 'no_library', 'No image processing library available.' );

		try {
			$angle = (float)($arguments['angle'] ?? 90);
			$bg    = $this->stringParam( $arguments, 'background', '#ffffff' );
			$result = $this->img->rotate( $imageUrl, $angle, $bg );
			return $this->success( "Image rotated {$angle}°.", array( 'angle'=>$angle, 'width'=>$result['width'], 'height'=>$result['height'], 'mime_type'=>$result['mime_type'], 'bytes'=>$result['bytes'] ) );
		} catch ( \Throwable $e ) { return $this->errors->create( 'rotate_failed', $e->getMessage() ); }
	}
}
