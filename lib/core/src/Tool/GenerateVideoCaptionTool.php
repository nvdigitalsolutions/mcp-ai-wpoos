<?php
/**
 * Generate Video Caption — AI-powered video captioning.
 *
 * Framework-agnostic equivalent of WP_MCP_AI_Tool_Generate_Video_Caption.
 *
 * @package Nvoos\Core @since 2.0.0 @license MIT
 */
declare(strict_types=1);
namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;

class GenerateVideoCaptionTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e ) { parent::__construct( $e ); }
	public function getSlug(): string { return 'generate_video_caption'; }
	public function getName(): string { return 'Generate Video Caption'; }
	public function getDescription(): string { return 'Generates concise, descriptive captions for videos using AI vision.'; }
	public function getParametersSchema(): array {
		return array(
			'type'=>'object',
			'properties'=>array(
				'video_url'=>array( 'type'=>'string','description'=>'URL of the video to caption.' ),
				'context'=>array( 'type'=>'string','description'=>'Optional context about the video.' ),
				'max_length'=>array( 'type'=>'integer','description'=>'Maximum caption length (50-500). Default: 200.','minimum'=>50,'maximum'=>500,'default'=>200 ),
			),
			'additionalProperties'=>false,
		);
	}
	public function getRequiredCapability(): string { return 'edit_posts'; }

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$videoUrl  = $this->stringParam( $arguments, 'video_url' );
		$ctx       = $this->stringParam( $arguments, 'context' );
		$maxLength = $this->intParam( $arguments, 'max_length', 200 );
		$maxLength = \max( 50, \min( 500, $maxLength ) );

		if ( '' === $videoUrl ) {
			return $this->errors->validationFailed( 'Provide a video URL.', array( 'video_url'=>array('Video URL is required.') ) );
		}

		$prompt = "Generate a concise, descriptive caption for this video in {$maxLength} characters or less. Describe what happens, including main subjects, actions, and setting.";
		if ( '' !== $ctx ) { $prompt = "Context: {$ctx}\\n\\n{$prompt}"; }

		return $this->success( 'Video caption prompt prepared.', array( 'video_url'=>$videoUrl, 'prompt'=>$prompt, 'max_length'=>$maxLength ) );
	}
}
