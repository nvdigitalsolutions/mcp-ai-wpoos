<?php
/** Create Batch — OpenAI Batch API. @package Nvoos\Core @since 2.0.0 @license MIT */
declare(strict_types=1); namespace Nvoos\Core\Tool;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;

class CreateBatchTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e, private readonly SettingsStoreInterface $s, private readonly HttpClientInterface $h ) { parent::__construct( $e ); }
	public function getSlug(): string { return 'create_batch'; }
	public function getName(): string { return 'Create Batch Job'; }
	public function getDescription(): string { return 'Creates an OpenAI batch processing job for async operations with 50% cost reduction.'; }
	public function getParametersSchema(): array {
		return array('type'=>'object','properties'=>array( 'input_file_id'=>array('type'=>'string','description'=>'ID of the uploaded JSONL input file.'), 'endpoint'=>array('type'=>'string','enum'=>array('/v1/chat/completions','/v1/embeddings','/v1/moderations'),'description'=>'The API endpoint for batch processing.'), 'completion_window'=>array('type'=>'string','enum'=>array('24h'),'default'=>'24h'), 'metadata'=>array('type'=>'object','description'=>'Custom metadata (up to 16 pairs).') ),'required'=>array('input_file_id','endpoint'),'additionalProperties'=>false);
	}
	public function getRequiredCapability(): string { return 'manage_options'; }

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$fileId   = $this->stringParam( $arguments, 'input_file_id' );
		$endpoint = $this->stringParam( $arguments, 'endpoint' );
		if ( '' === $fileId || '' === $endpoint ) return $this->errors->validationFailed( 'input_file_id and endpoint are required.', array('input_file_id'=>array('Required.'),'endpoint'=>array('Required.')) );

		$key = $this->s->getApiKey( 'openai' );
		if ( null === $key || '' === $key ) return $this->errors->create( 'missing_key', 'No OpenAI API key configured.' );

		$body = array( 'input_file_id'=>$fileId, 'endpoint'=>$endpoint, 'completion_window'=>$this->stringParam($arguments,'completion_window','24h') );
		$meta = $arguments['metadata'] ?? null;
		if ( \is_array( $meta ) && array() !== $meta ) $body['metadata'] = \array_slice( $meta, 0, 16 );

		try {
			$r = $this->h->send( 'POST', 'https://api.openai.com/v1/batches', array('Authorization'=>"Bearer {$key}",'Content-Type'=>'application/json'), \json_encode($body) );
			$d = \json_decode( $r->body, true );
			if ( $r->statusCode >= 400 ) { $err = $d['error']['message'] ?? 'Batch API error.'; return $this->errors->create( 'batch_error', $err ); }
			return $this->success( "Batch {$d['id']} created (Status: {$d['status']}).", array( 'batch_id'=>$d['id']??'', 'status'=>$d['status']??'', 'endpoint'=>$d['endpoint']??'', 'created_at'=>$d['created_at']??null ) );
		} catch ( \Throwable $e ) { return $this->errors->create( 'request_failed', $e->getMessage() ); }
	}
}
