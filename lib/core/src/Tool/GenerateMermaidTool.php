<?php
/** Generate Mermaid — Mermaid.js diagram builder. @package Nvoos\Core @since 2.0.0 @license MIT */
declare(strict_types=1); namespace Nvoos\Core\Tool;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;

class GenerateMermaidTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e ) { parent::__construct( $e ); }
	public function getSlug(): string { return 'generate_mermaid'; }
	public function getName(): string { return 'Generate Mermaid Diagram'; }
	public function getDescription(): string { return 'Generates Mermaid.js diagrams (flowchart, sequence, gantt, class) from code.'; }
	public function getParametersSchema(): array {
		return array('type'=>'object','properties'=>array(
			'type'=>array('type'=>'string','description'=>'Diagram type.','enum'=>array('flowchart','sequence','gantt','class')),
			'code'=>array('type'=>'string','description'=>'Mermaid diagram code.'),
			'theme'=>array('type'=>'string','description'=>'Theme.','enum'=>array('default','forest','dark','neutral'),'default'=>'default'),
		),'required'=>array('type','code'),'additionalProperties'=>false);
	}
	public function getRequiredCapability(): string { return 'edit_posts'; }

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$type = $this->stringParam( $arguments, 'type' );
		$code = $this->stringParam( $arguments, 'code' );
		if ( '' === $type || !\in_array($type,array('flowchart','sequence','gantt','class'),true) ) return $this->errors->validationFailed( 'Invalid diagram type.', array('type'=>array('Must be: flowchart, sequence, gantt, or class.')) );
		if ( '' === $code ) return $this->errors->validationFailed( 'Mermaid code is required.', array('code'=>array('Required.')) );

		$theme = $this->stringParam( $arguments, 'theme', 'default' );
		return $this->success( "Generated {$type} diagram.", array( 'type'=>$type, 'mermaid_code'=>$code, 'theme'=>$theme, 'diagram_id'=>'mermaid-'.bin2hex(random_bytes(4)) ) );
	}
}
