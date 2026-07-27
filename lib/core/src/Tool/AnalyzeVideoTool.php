<?php
/**
 * Analyze Video — AI-powered video content analysis.
 *
 * Framework-agnostic equivalent of WP_MCP_AI_Tool_Analyze_Video.
 * Returns analysis instructions; actual video model call is handled
 * by the agentic loop / provider layer.
 *
 * @package Nvoos\Core @since 2.0.0 @license MIT
 */
declare(strict_types=1);
namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;

class AnalyzeVideoTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e ) { parent::__construct( $e ); }
	public function getSlug(): string { return 'analyze_video'; }
	public function getName(): string { return 'Analyze Video'; }
	public function getDescription(): string { return 'Analyzes video content to extract information, describe scenes, and identify objects using AI vision.'; }
	public function getParametersSchema(): array {
		return array(
			'type'=>'object',
			'properties'=>array(
				'video_url'=>array( 'type'=>'string','description'=>'URL of the video to analyze.' ),
				'prompt'=>array( 'type'=>'string','description'=>'Specific question or analysis prompt. If omitted, a general description is generated.' ),
				'analysis_type'=>array( 'type'=>'string','description'=>'Analysis type.','enum'=>array('general','scene_breakdown','timeline','detailed'),'default'=>'general' ),
			),
			'additionalProperties'=>false,
		);
	}
	public function getRequiredCapability(): string { return 'edit_posts'; }

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$videoUrl     = $this->stringParam( $arguments, 'video_url' );
		$userPrompt   = $this->stringParam( $arguments, 'prompt' );
		$analysisType = $this->stringParam( $arguments, 'analysis_type', 'general' );

		if ( '' === $videoUrl && '' === $userPrompt ) {
			return $this->errors->validationFailed( 'Provide a video URL or analysis prompt.', array( 'video_url'=>array('Video source is required.') ) );
		}

		$prompt = '' !== $userPrompt ? $userPrompt : match ( $analysisType ) {
			'scene_breakdown' => 'Analyze this video and provide a scene-by-scene breakdown with descriptions of setting, subjects, actions, and transitions.',
			'timeline'        => 'Create a chronological timeline of key events in this video with descriptions.',
			'detailed'        => 'Provide a comprehensive detailed analysis including subjects, actions, setting, visual elements, tone, and technical aspects.',
			default           => 'Please analyze this video and provide a detailed description including main subjects, actions, setting, visual elements, tone, and any visible text.',
		};

		return $this->success( 'Video analysis prompt prepared.', array(
			'video_url'     => $videoUrl,
			'prompt'         => $prompt,
			'analysis_type'  => $analysisType,
		) );
	}
}
