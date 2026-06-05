<?php
/** Crawl4AI Job — triggers a crawl job via Crawl4AI REST API.
 *
 * @package Nvoos\Core @since 1.0.0 @license MIT */
declare(strict_types=1);
namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Psr\Http\Client\ClientInterface as HttpClientInterface;
class RunCrawl4AiJobTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e, private readonly SettingsStoreInterface $s, private readonly HttpClientInterface $h ) {
		parent::__construct( $e );}
	public function getSlug(): string {
		return 'run_crawl4ai_job'; }
	public function getName(): string {
		return 'Run Crawl4AI Job'; }
	public function getDescription(): string {
		return 'Triggers a web crawling job using the Crawl4AI REST API.'; }
	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'url'  => array(
					'type'        => 'string',
					'description' => 'URL to crawl',
				),
				'mode' => array(
					'type'        => 'string',
					'description' => 'Crawl mode',
					'enum'        => array( 'single', 'deep' ),
					'default'     => 'single',
				),
			),
			'required'             => array( 'url' ),
			'additionalProperties' => false,
		); }
	public function getRequiredCapability(): string {
		return 'read'; }
	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$url = $this->stringParam( $arguments, 'url' );
		if ( '' === $url ) {
			return $this->errors->validationFailed( 'url is required.', array( 'url' => array( 'URL to crawl is required.' ) ) );
		}
		$baseUrl = $this->s->getApiBaseUrl( 'crawl4ai' ) ?? 'http://localhost:11235';
		try {
			$body     = json_encode(
				array(
					'urls' => array( $url ),
					'mode' => $this->stringParam( $arguments, 'mode', 'single' ),
				)
			);
			$request  = new \Nyholm\Psr7\Request( 'POST', $baseUrl . '/crawl', array( 'Content-Type' => 'application/json' ), $body );
			$response = $this->h->sendRequest( $request );
			$data     = json_decode( (string) $response->getBody(), true );
			return $this->success(
				'Crawl job submitted.',
				array(
					'job_id' => $data['job_id'] ?? '',
					'status' => $data['status'] ?? 'queued',
					'url'    => $url,
				)
			);
		} catch ( \Exception $e ) {
			return $this->errors->create( 'crawl4ai_failed', "Crawl4AI request failed: {$e->getMessage()}" ); }
	}
}
