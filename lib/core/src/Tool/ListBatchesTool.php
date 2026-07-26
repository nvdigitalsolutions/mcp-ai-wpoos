<?php
/** List Batches — list OpenAI batch jobs. @package Nvoos\Core @since 2.0.0 @license MIT */
declare(strict_types=1); namespace Nvoos\Core\Tool;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;

class ListBatchesTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e, private readonly SettingsStoreInterface $s, private readonly HttpClientInterface $h ) { parent::__construct( $e ); }
	public function getSlug(): string { return 'list_batches'; }
	public function getName(): string { return 'List Batch Jobs'; }
	public function getDescription(): string { return 'Lists OpenAI batch processing jobs with optional filtering and pagination.'; }
	public function getParametersSchema(): array {
		return array('type'=>'object','properties'=>array( 'limit'=>array('type'=>'integer','description'=>'Maximum results (1-100).','minimum'=>1,'maximum'=>100,'default'=>20), 'after'=>array('type'=>'string','description'=>'Cursor for pagination (last batch ID from previous page).') ),'additionalProperties'=>false);
	}
	public function getRequiredCapability(): string { return 'manage_options'; }

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$key = $this->s->getApiKey( 'openai' );
		if ( null === $key || '' === $key ) return $this->errors->create( 'missing_key', 'No OpenAI API key configured.' );

		$limit = \max( 1, \min( 100, $this->intParam( $arguments, 'limit', 20 ) ) );
		$after = $this->stringParam( $arguments, 'after' );
		$query = \http_build_query( array_filter( array( 'limit'=>$limit, 'after'=>$after ) ) );
		$url   = "https://api.openai.com/v1/batches?{$query}";

		try {
			$r = $this->h->send( 'GET', $url, array('Authorization'=>"Bearer {$key}") );
			$d = \json_decode( $r->body, true );
			if ( $r->statusCode >= 400 ) { $err = $d['error']['message'] ?? 'Batch API error.'; return $this->errors->create( 'batch_error', $err ); }

			$batches = array();
			foreach ( $d['data'] ?? array() as $b ) { $batches[] = array( 'id'=>$b['id']??'', 'status'=>$b['status']??'', 'endpoint'=>$b['endpoint']??'', 'created_at'=>$b['created_at']??null, 'request_counts'=>$b['request_counts']??array() ); }
			return $this->collection( 'Found '.\count($batches).' batch job(s).', $batches, \count($batches) );
		} catch ( \Throwable $e ) { return $this->errors->create( 'request_failed', $e->getMessage() ); }
	}
}
