<?php
/** Vision Product Search — visual product matching. @package Nvoos\Core @since 2.0.0 @license MIT */
declare(strict_types=1); namespace Nvoos\Core\Tool;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
class VisionProductSearchTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e ) { parent::__construct( $e ); }
	public function getSlug(): string { return 'vision_product_search'; }
	public function getName(): string { return 'Vision Product Search'; }
	public function getDescription(): string { return 'Identifies products in images and finds matching or similar products.'; }
	public function getParametersSchema(): array { return array('type'=>'object','properties'=>array('image_url'=>array('type'=>'string','description'=>'URL of the product image.')),'additionalProperties'=>false); }
	public function getRequiredCapability(): string { return 'read'; }
	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$url = $this->stringParam( $arguments, 'image_url' );
		if ( '' === $url ) return $this->errors->validationFailed( 'An image URL is required.', array('image_url'=>array('Required.')) );
		return $this->success( 'Product search prompt prepared.', array( 'image_url'=>$url, 'prompt'=>'Identify the product(s) in this image. Describe the product type, brand if visible, key features, materials, colors, and suggest similar or matching products.' ) );
	}
}
