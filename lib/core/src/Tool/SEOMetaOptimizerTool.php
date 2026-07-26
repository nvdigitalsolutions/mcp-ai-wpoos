<?php
/** SEO Meta Optimizer — prompt builder. @package Nvoos\Core @since 2.0.0 @license MIT */
declare(strict_types=1); namespace Nvoos\Core\Tool; use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
class SEOMetaOptimizerTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e ) { parent::__construct( $e ); }
	public function getSlug(): string { return 'seo_meta_optimizer'; } public function getName(): string { return 'SEO Meta Optimizer'; } public function getDescription(): string { return 'Optimizes SEO meta titles and descriptions for content.'; }
	public function getParametersSchema(): array { return array('type'=>'object','properties'=>array('content'=>array('type'=>'string','description'=>'Post content.'), 'title'=>array('type'=>'string','description'=>'Current title.'), 'focus_keyword'=>array('type'=>'string','description'=>'Target SEO keyword.')),'required'=>array('content'),'additionalProperties'=>false); }
	public function getRequiredCapability(): string { return 'edit_posts'; }
	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$content = $this->stringParam($arguments,'content'); if (''===$content) return $this->errors->validationFailed('Content required.',array('content'=>array('Required.')));
		$title = $this->stringParam($arguments,'title'); $kw = $this->stringParam($arguments,'focus_keyword');
		$prompt = 'Optimize SEO meta data for this content. Provide: 1) An optimized title (50-60 chars), 2) A compelling meta description (150-160 chars)'.(''!==$kw?" targeting keyword: {$kw}":'').(''!==$title?"\nCurrent title: {$title}":'')."\nContent: ".substr($content,0,2000);
		return $this->success('SEO optimization prompt prepared.',array('prompt'=>$prompt,'focus_keyword'=>$kw));
	}
}
