<?php
/** Create Chart — chart creation with validation. @package Nvoos\Core @since 2.0.0 @license MIT */
declare(strict_types=1); namespace Nvoos\Core\Tool;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;

class CreateChartTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e ) { parent::__construct( $e ); }
	public function getSlug(): string { return 'create_chart'; }
	public function getName(): string { return 'Create Chart'; }
	public function getDescription(): string { return 'Creates interactive charts from data with validation and Chart.js configuration.'; }
	public function getParametersSchema(): array {
		return array('type'=>'object','properties'=>array(
			'type'=>array('type'=>'string','description'=>'Chart type.','enum'=>array('bar','line','pie','doughnut','radar','polarArea','scatter','bubble')),
			'data'=>array('type'=>'object','description'=>'Chart data.'),
			'title'=>array('type'=>'string','description'=>'Optional chart title.'),
			'width'=>array('type'=>'integer','description'=>'Chart width in pixels.','minimum'=>200,'maximum'=>4000,'default'=>800),
			'height'=>array('type'=>'integer','description'=>'Chart height in pixels.','minimum'=>100,'maximum'=>4000,'default'=>400),
			'colors'=>array('type'=>'array','description'=>'Custom color palette.','items'=>array('type'=>'string')),
		),'required'=>array('type','data'),'additionalProperties'=>false);
	}
	public function getRequiredCapability(): string { return 'edit_posts'; }

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$type = $this->stringParam( $arguments, 'type' );
		if ( '' === $type || !\in_array($type,array('bar','line','pie','doughnut','radar','polarArea','scatter','bubble'),true) ) return $this->errors->validationFailed( 'Invalid chart type.', array('type'=>array('Invalid.')) );

		$data = $arguments['data'] ?? null;
		if ( !\is_array($data) ) return $this->errors->validationFailed( 'Chart data is required.', array('data'=>array('Required.')) );

		$config = array( 'type'=>$type, 'data'=>$data );
		$title = $this->stringParam( $arguments, 'title' );
		$width  = \max(200,\min(4000,$this->intParam($arguments,'width',800)));
		$height = \max(100,\min(4000,$this->intParam($arguments,'height',400)));
		$colors = $this->arrayParam( $arguments, 'colors' );

		if ( '' !== $title ) $config['options']['plugins']['title'] = array('display'=>true,'text'=>$title);
		if ( array() !== $colors ) $config['data']['datasets'][0]['backgroundColor'] = $colors;

		return $this->success( "Created {$type} chart.", array( 'type'=>$type, 'chart_config'=>$config, 'title'=>$title?:null, 'width'=>$width, 'height'=>$height ) );
	}
}
