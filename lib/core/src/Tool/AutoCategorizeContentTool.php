<?php
/** Auto Categorize Content — AI categorization prompt builder. @package Nvoos\Core @since 2.0.0 @license MIT */
declare(strict_types=1); namespace Nvoos\Core\Tool;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
class AutoCategorizeContentTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e ) { parent::__construct( $e ); }
	public function getSlug(): string { return 'auto_categorize_content'; }
	public function getName(): string { return 'Auto-Categorize Content'; }
	public function getDescription(): string { return 'Analyzes content and suggests relevant categories based on context.'; }
	public function getParametersSchema(): array {
		return array('type'=>'object','properties'=>array(
			'post_id'=>array('type'=>'integer','description'=>'Post ID to categorize.','minimum'=>1),
			'content'=>array('type'=>'string','description'=>'Content to analyze.'),
			'title'=>array('type'=>'string','description'=>'Post title.'),
			'max_categories'=>array('type'=>'integer','description'=>'Maximum categories to suggest (1-10).','minimum'=>1,'maximum'=>10,'default'=>3),
			'taxonomy'=>array('type'=>'string','description'=>'Taxonomy slug.','default'=>'category'),
		),'additionalProperties'=>false);
	}
	public function getRequiredCapability(): string { return 'edit_posts'; }
	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$content = $this->stringParam( $arguments, 'content' );
		$title   = $this->stringParam( $arguments, 'title' );
		if ( '' === $content && '' === $title ) return $this->errors->validationFailed( 'Provide content or title.', array('content'=>array('Required.')) );
		$max   = \max(1,\min(10,$this->intParam($arguments,'max_categories',3)));
		$tax   = $this->stringParam( $arguments, 'taxonomy', 'category' );
		$prompt = "Suggest up to {$max} relevant {$tax} categories for this content. Respond with category names only.\n" . ($title?"Title: {$title}\n":'') . ($content?"Content: ".substr($content,0,2000):'');
		return $this->success( 'Categorization prompt prepared.', array('prompt'=>$prompt,'max_categories'=>$max,'taxonomy'=>$tax) );
	}
}
