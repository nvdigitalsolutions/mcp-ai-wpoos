<?php
/** Analyze Comment Content — comment analysis prompt builder. @package Nvoos\Core @since 2.0.0 @license MIT */
declare(strict_types=1); namespace Nvoos\Core\Tool;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
class AnalyzeCommentContentTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e ) { parent::__construct( $e ); }
	public function getSlug(): string { return 'analyze_comment_content'; }
	public function getName(): string { return 'Analyze Comment Content'; }
	public function getDescription(): string { return 'Analyzes comment content for sentiment, spam detection, and moderation.'; }
	public function getParametersSchema(): array {
		return array('type'=>'object','properties'=>array( 'comment_text'=>array('type'=>'string','description'=>'Comment text to analyze.'), 'post_title'=>array('type'=>'string','description'=>'Title of the post being commented on.') ),'required'=>array('comment_text'),'additionalProperties'=>false);
	}
	public function getRequiredCapability(): string { return 'edit_posts'; }
	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$text = $this->stringParam( $arguments, 'comment_text' );
		if ( '' === $text ) return $this->errors->validationFailed( 'Comment text is required.', array('comment_text'=>array('Required.')) );
		$title = $this->stringParam( $arguments, 'post_title' );
		return $this->success( 'Comment analysis prompt prepared.', array( 'prompt'=>"Analyze this comment for sentiment (positive/negative/neutral), spam probability, and whether it needs moderation." . ($title?"\nPost: {$title}":'') . "\nComment: {$text}" ) );
	}
}
