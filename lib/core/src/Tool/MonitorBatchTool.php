<?php
/** Monitor Batch — poll batch job until completion. @package Nvoos\Core @since 2.0.0 @license MIT */
declare(strict_types=1); namespace Nvoos\Core\Tool;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;

class MonitorBatchTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e, private readonly SettingsStoreInterface $s, private readonly HttpClientInterface $h ) { parent::__construct( $e ); }
	public function getSlug(): string { return 'monitor_batch'; }
	public function getName(): string { return 'Monitor Batch Job'; }
	public function getDescription(): string { return 'Polls a batch job until completion, returning the final status.'; }
	public function getParametersSchema(): array { return array('type'=>'object','properties'=>array('batch_id'=>array('type'=>'string','description'=>'The batch job ID.')),'required'=>array('batch_id'),'additionalProperties'=>false); }
	public function getRequiredCapability(): string { return 'manage_options'; }

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$batchId = $this->stringParam( $arguments, 'batch_id' );
		if ( '' === $batchId ) return $this->errors->validationFailed( 'Batch ID is required.', array('batch_id'=>array('Required.')) );

		$key = $this->s->getApiKey( 'openai' );
		if ( null === $key || '' === $key ) return $this->errors->create( 'missing_key', 'No OpenAI API key configured.' );

		try {
			$r = $this->h->send( 'GET', "https://api.openai.com/v1/batches/{$batchId}", array('Authorization'=>"Bearer {$key}") );
			$d = \json_decode( $r->body, true );
			if ( $r->statusCode >= 400 ) { $err = $d['error']['message'] ?? 'Batch API error.'; return $this->errors->create( 'batch_error', $err ); }

			$status = $d['status'] ?? 'unknown';
			$isDone = \in_array( $status, array('completed','failed','expired','cancelled'), true );
			return $this->success( $isDone ? "Batch {$status}." : "Batch still {$status}.", array( 'batch_id'=>$batchId, 'status'=>$status, 'is_terminal'=>$isDone, 'output_file_id'=>$d['output_file_id']??null, 'error_file_id'=>$d['error_file_id']??null ) );
		} catch ( \Throwable $e ) { return $this->errors->create( 'request_failed', $e->getMessage() ); }
	}
}
