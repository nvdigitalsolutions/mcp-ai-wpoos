<?php
/** Generate Chart — Chart.js data structuring. @package Nvoos\Core @since 2.0.0 @license MIT */
declare(strict_types=1); namespace Nvoos\Core\Tool;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;

class GenerateChartTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e ) { parent::__construct( $e ); }
	public function getSlug(): string { return 'generate_chart'; }
	public function getName(): string { return 'Generate Chart'; }
	public function getDescription(): string { return 'Generates interactive chart configurations (line, bar, pie, doughnut, scatter, radar) from data for Chart.js rendering.'; }
	public function getParametersSchema(): array {
		return array('type'=>'object','properties'=>array(
			'type'=>array('type'=>'string','description'=>'Chart type.','enum'=>array('line','bar','pie','doughnut','scatter','radar')),
			'data'=>array('type'=>'object','description'=>'Chart data with labels and datasets.','properties'=>array(
				'labels'=>array('type'=>'array','description'=>'Data labels.','items'=>array('type'=>'string')),
				'datasets'=>array('type'=>'array','description'=>'Data series.','items'=>array('type'=>'object','properties'=>array('label'=>array('type'=>'string'),'data'=>array('type'=>'array','items'=>array('type'=>'number'))))),
			)),
			'title'=>array('type'=>'string','description'=>'Optional chart title.'),
			'options'=>array('type'=>'object','description'=>'Additional Chart.js options.'),
		),'required'=>array('type','data'),'additionalProperties'=>false);
	}
	public function getRequiredCapability(): string { return 'edit_posts'; }

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$type = $this->stringParam( $arguments, 'type' );
		$data = $arguments['data'] ?? null;
		if ( '' === $type || !\in_array($type,array('line','bar','pie','doughnut','scatter','radar'),true) ) return $this->errors->validationFailed( 'Invalid chart type.', array('type'=>array('Must be one of: line, bar, pie, doughnut, scatter, radar.')) );
		if ( !\is_array($data) ) return $this->errors->validationFailed( 'Chart data is required.', array('data'=>array('Required.')) );

		$config = array( 'type'=>$type, 'data'=>$data );
		$title = $this->stringParam( $arguments, 'title' );
		if ( '' !== $title ) $config['options']['plugins']['title'] = array( 'display'=>true, 'text'=>$title );
		$opts = $arguments['options'] ?? null;
		if ( \is_array($opts) ) $config['options'] = \array_merge_recursive( $config['options']??array(), $opts );

		return $this->success( "Generated {$type} chart configuration.", array( 'type'=>$type, 'chart_config'=>$config, 'title'=>$title?:null ) );
	}
}
