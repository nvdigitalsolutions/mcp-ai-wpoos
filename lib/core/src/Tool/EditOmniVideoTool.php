<?php
/**
 * Edit Omni Video — edit an existing Omni-generated video with multi-turn editing.
 *
 * @package Nvoos\Core @since 2.0.0 @license MIT
 */
declare(strict_types=1);
namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;

class EditOmniVideoTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e, private readonly SettingsStoreInterface $s, private readonly HttpClientInterface $h ) { parent::__construct( $e ); }
	public function getSlug(): string { return 'edit_omni_video'; }
	public function getName(): string { return 'Edit Omni Video'; }
	public function getDescription(): string { return 'Edits an existing Omni-generated video using multi-turn conversational editing.'; }
	public function getParametersSchema(): array {
		return array( 'type'=>'object','properties'=>array( 'video_id'=>array('type'=>'string','description'=>'ID of the video to edit.'), 'prompt'=>array('type'=>'string','description'=>'Edit instructions describing what to change.') ),'required'=>array('video_id','prompt'),'additionalProperties'=>false );
	}
	public function getRequiredCapability(): string { return 'edit_posts'; }

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$videoId = $this->stringParam( $arguments, 'video_id' );
		$prompt  = $this->stringParam( $arguments, 'prompt' );
		if ( '' === $videoId || '' === $prompt ) return $this->errors->validationFailed( 'video_id and prompt are required.', array('video_id'=>array('Required.'),'prompt'=>array('Required.')) );

		$key = $this->s->getApiKey( 'gemini' );
		if ( null === $key || '' === $key ) return $this->errors->create( 'missing_key', 'No Gemini API key configured.' );

		try {
			$r = $this->h->send( 'POST', "https://generativelanguage.googleapis.com/v1beta/models/gemini-omni-flash:edit?key={$key}", array( 'Content-Type'=>'application/json' ), \json_encode( array( 'instances'=>array( array( 'video_id'=>$videoId, 'prompt'=>$prompt ) ) ) ) );
			$d = \json_decode( $r->body, true );
			if ( $r->statusCode >= 400 ) { $err = $d['error']['message'] ?? 'Omni edit error.'; return $this->errors->create( 'omni_error', $err ); }

			return $this->success( 'Video edit initiated.', array( 'video_id'=>$videoId, 'prompt'=>$prompt, 'job_id'=>$d['name']??null, 'status'=>'queued' ) );
		} catch ( \Throwable $e ) { return $this->errors->create( 'request_failed', $e->getMessage() ); }
	}
}
