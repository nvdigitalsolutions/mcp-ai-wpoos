<?php
/** Get Batch Status — OpenAI batch job status. @package Nvoos\Core @since 2.0.0 @license MIT */
declare(strict_types=1); namespace Nvoos\Core\Tool;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;

class GetBatchStatusTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e, private readonly SettingsStoreInterface $s, private readonly HttpClientInterface $h ) { parent::__construct( $e ); }
	public function getSlug(): string { return 'get_batch_status'; }
	public function getName(): string { return 'Get Batch Status'; }
	public function getDescription(): string { return 'Retrieves the status and details of an OpenAI batch processing job.'; }
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

			$counts = $d['request_counts'] ?? array();
			$total  = $counts['total'] ?? 0;
			$done   = $counts['completed'] ?? 0;
			$failed = $counts['failed'] ?? 0;
			$pct    = $total > 0 ? \round( $done / $total * 100 ) : 0;

			return $this->success( "Batch {$d['id']}: {$d['status']} ({$pct}% complete)", array(
				'batch_id'=>$d['id']??'', 'status'=>$d['status']??'', 'endpoint'=>$d['endpoint']??'',
				'created_at'=>$d['created_at']??null, 'completed_at'=>$d['completed_at']??null,
				'output_file_id'=>$d['output_file_id']??null, 'error_file_id'=>$d['error_file_id']??null,
				'request_counts'=>array('total'=>$total,'completed'=>$done,'failed'=>$failed),
				'progress_pct'=>$pct, 'metadata'=>$d['metadata']??array(),
			) );
		} catch ( \Throwable $e ) { return $this->errors->create( 'request_failed', $e->getMessage() ); }
	}
}
