<?php
/** Content Recommendation Engine — prompt builder. @package Nvoos\Core @since 2.0.0 @license MIT */
declare(strict_types=1); namespace Nvoos\Core\Tool; use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
class ContentRecommendationEngineTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e ) { parent::__construct( $e ); }
	public function getSlug(): string { return 'content_recommendation_engine'; } public function getName(): string { return 'Content Recommendation Engine'; } public function getDescription(): string { return 'Recommends content based on user preferences, similarity, and trending topics.'; }
	public function getParametersSchema(): array { return array('type'=>'object','properties'=>array('content'=>array('type'=>'string','description'=>'Source content to base recommendations on.'), 'preferences'=>array('type'=>'array','description'=>'User preference keywords.','items'=>array('type'=>'string')), 'limit'=>array('type'=>'integer','description'=>'Max recommendations.','minimum'=>1,'maximum'=>20,'default'=>5)),'required'=>array('content'),'additionalProperties'=>false); }
	public function getRequiredCapability(): string { return 'edit_posts'; }
	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$content = $this->stringParam($arguments,'content'); if (''===$content) return $this->errors->validationFailed('Content required.',array('content'=>array('Required.')));
		$prefs = $this->arrayParam($arguments,'preferences'); $limit = \max(1,\min(20,$this->intParam($arguments,'limit',5)));
		$prompt = "Based on this content, recommend {$limit} related topics or articles.".(array()!==$prefs?' User prefers: '.\implode(', ',$prefs).'.':'')."\nContent: ".substr($content,0,2000);
		return $this->success('Recommendation prompt prepared.',array('prompt'=>$prompt,'limit'=>$limit));
	}
}
