<?php
/** Content Freshness Checker — age and relevance analysis. @package Nvoos\Core @since 2.0.0 @license MIT */
declare(strict_types=1); namespace Nvoos\Core\Tool;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
class ContentFreshnessCheckerTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e ) { parent::__construct( $e ); }
	public function getSlug(): string { return 'content_freshness_checker'; }
	public function getName(): string { return 'Content Freshness Checker'; }
	public function getDescription(): string { return 'Analyzes content age and identifies outdated information needing updates.'; }
	public function getParametersSchema(): array {
		return array('type'=>'object','properties'=>array(
			'content'=>array('type'=>'string','description'=>'Content to analyze.'),
			'title'=>array('type'=>'string','description'=>'Post title.'),
			'publish_date'=>array('type'=>'string','description'=>'Original publish date (ISO 8601).'),
			'modified_date'=>array('type'=>'string','description'=>'Last modified date (ISO 8601).'),
			'age_threshold_days'=>array('type'=>'integer','description'=>'Days to consider stale.','minimum'=>1,'default'=>365),
		),'required'=>array('content'),'additionalProperties'=>false);
	}
	public function getRequiredCapability(): string { return 'edit_posts'; }
	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$content = $this->stringParam( $arguments, 'content' );
		if ( '' === $content ) return $this->errors->validationFailed( 'Content is required.', array('content'=>array('Required.')) );
		$threshold = $this->intParam( $arguments, 'age_threshold_days', 365 );
		$pubDate   = $this->stringParam( $arguments, 'publish_date' );
		$modDate   = $this->stringParam( $arguments, 'modified_date' );

		$ageDays = null;
		if ( '' !== $modDate || '' !== $pubDate ) {
			$date  = '' !== $modDate ? $modDate : $pubDate;
			$ts    = \strtotime( $date );
			$ageDays = $ts ? (int)((\time() - $ts) / 86400) : null;
		}

		$timeWords = array('today','yesterday','this week','last week','this month','last month','currently','recent','latest','upcoming');
		$lower = \strtolower($content);
		$timeRefs = 0;
		foreach ($timeWords as $w) { if (\str_contains($lower, $w)) $timeRefs++; }

		$status = null !== $ageDays ? ($ageDays > $threshold*2 ? 'outdated' : ($ageDays > $threshold ? 'stale' : ($ageDays > $threshold/2 ? 'moderate' : 'fresh'))) : null;
		$prompt = "Analyze this content for freshness. Identify time-sensitive references, outdated statistics, or information that may need updating." . ($content?"\n\nContent: ".substr($content,0,2000):'');

		return $this->success( 'Freshness analysis prepared.', array( 'prompt'=>$prompt, 'age_days'=>$ageDays, 'status'=>$status, 'time_sensitive_refs'=>$timeRefs, 'needs_review'=>$timeRefs > 2 || (null!==$ageDays && $ageDays > $threshold) ) );
	}
}
