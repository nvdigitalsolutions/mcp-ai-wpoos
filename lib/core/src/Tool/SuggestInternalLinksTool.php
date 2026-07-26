<?php
/** Suggest Internal Links — SEO internal link prompt builder. @package Nvoos\Core @since 2.0.0 @license MIT */
declare(strict_types=1); namespace Nvoos\Core\Tool;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
class SuggestInternalLinksTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e ) { parent::__construct( $e ); }
	public function getSlug(): string { return 'suggest_internal_links'; }
	public function getName(): string { return 'Suggest Internal Links'; }
	public function getDescription(): string { return 'Analyzes content and suggests relevant internal links for SEO.'; }
	public function getParametersSchema(): array {
		return array('type'=>'object','properties'=>array(
			'post_id'=>array('type'=>'integer','description'=>'Post ID to analyze.','minimum'=>1),
			'content'=>array('type'=>'string','description'=>'Content to analyze.'),
			'title'=>array('type'=>'string','description'=>'Post title.'),
			'max_suggestions'=>array('type'=>'integer','description'=>'Maximum suggestions (1-20).','minimum'=>1,'maximum'=>20,'default'=>5),
			'min_relevance'=>array('type'=>'number','description'=>'Minimum relevance score (0-1).','minimum'=>0,'maximum'=>1,'default'=>0.5),
			'candidate_urls'=>array('type'=>'array','description'=>'Candidate URLs to consider for linking.','items'=>array('type'=>'string')),
		),'additionalProperties'=>false);
	}
	public function getRequiredCapability(): string { return 'edit_posts'; }
	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$content = $this->stringParam( $arguments, 'content' );
		$title   = $this->stringParam( $arguments, 'title' );
		if ( '' === $content && '' === $title ) return $this->errors->validationFailed( 'Provide content or title.', array('content'=>array('Required.')) );
		$candidates = $this->arrayParam( $arguments, 'candidate_urls' );
		$max   = \max(1,\min(20,$this->intParam($arguments,'max_suggestions',5)));
		$prompt = "Suggest up to {$max} relevant internal links for this content." . ($title?"\nTitle: {$title}":'') . ($content?"\nContent: ".substr($content,0,2000):'') . (array()!==$candidates?"\nCandidate URLs: ".\implode(', ',$candidates):'');
		return $this->success( 'Link suggestions prompt prepared.', array('prompt'=>$prompt,'max_suggestions'=>$max,'candidate_count'=>count($candidates)) );
	}
}
