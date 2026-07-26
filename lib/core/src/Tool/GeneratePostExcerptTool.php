<?php
/** Generate Post Excerpt — AI excerpt prompt builder. @package Nvoos\Core @since 2.0.0 @license MIT */
declare(strict_types=1); namespace Nvoos\Core\Tool;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
class GeneratePostExcerptTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e ) { parent::__construct( $e ); }
	public function getSlug(): string { return 'generate_post_excerpt'; }
	public function getName(): string { return 'Generate Post Excerpt'; }
	public function getDescription(): string { return 'Generates compelling post excerpts optimized for SEO and engagement.'; }
	public function getParametersSchema(): array {
		return array('type'=>'object','properties'=>array(
			'post_id'=>array('type'=>'integer','description'=>'Post ID to generate excerpt for.','minimum'=>1),
			'content'=>array('type'=>'string','description'=>'Content to generate excerpt from (if post_id not provided).'),
			'title'=>array('type'=>'string','description'=>'Post title for context.'),
			'length'=>array('type'=>'integer','description'=>'Maximum length in words (10-100).','minimum'=>10,'maximum'=>100,'default'=>55),
			'tone'=>array('type'=>'string','description'=>'Writing tone.','enum'=>array('professional','casual','engaging','informative','compelling'),'default'=>'engaging'),
			'include_cta'=>array('type'=>'boolean','description'=>'Include a call-to-action.','default'=>false),
		),'additionalProperties'=>false);
	}
	public function getRequiredCapability(): string { return 'edit_posts'; }
	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$content = $this->stringParam( $arguments, 'content' );
		$title   = $this->stringParam( $arguments, 'title' );
		if ( '' === $content && '' === $title && 0 === $this->intParam($arguments,'post_id') ) return $this->errors->validationFailed( 'Provide content, title, or post_id.', array('content'=>array('Required.')) );
		$length = \max(10,\min(100,$this->intParam($arguments,'length',55)));
		$tone   = $this->stringParam( $arguments, 'tone', 'engaging' );
		$cta    = !empty($arguments['include_cta']);
		$prompt = "Generate a compelling {$tone} excerpt (max {$length} words)." . ($cta?' Include a subtle call-to-action.':'') . ($title?"\nTitle: {$title}":'') . ($content?"\nContent: ".substr($content,0,1500):'');
		return $this->success( 'Excerpt prompt prepared.', array('prompt'=>$prompt,'length'=>$length,'tone'=>$tone) );
	}
}
