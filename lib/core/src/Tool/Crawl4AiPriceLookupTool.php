<?php
/** Crawl4AI Price Lookup — web search via Crawl4AI endpoint.
 *
 * @package Oos\Core @since 1.0.0 @license MIT */
declare(strict_types=1);
namespace Oos\Core\Tool;

use Oos\Core\Domain\Contract\ErrorFactoryInterface;
use Oos\Core\Domain\Contract\SettingsStoreInterface;
use Psr\Http\Client\ClientInterface as HttpClientInterface;
class Crawl4AiPriceLookupTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e, private readonly SettingsStoreInterface $s, private readonly HttpClientInterface $h ) {
		parent::__construct( $e );}
	public function getSlug(): string {
		return 'crawl4ai_price_lookup'; }
	public function getName(): string {
		return 'Crawl4AI Price Lookup'; }
	public function getDescription(): string {
		return 'Performs a price/product lookup using Crawl4AI web search.'; }
	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'query' => array(
					'type'        => 'string',
					'description' => 'Product search query',
				),
			),
			'required'             => array( 'query' ),
			'additionalProperties' => false,
		); }
	public function getRequiredCapability(): string {
		return 'read'; }
	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$query = $this->stringParam( $arguments, 'query' );
		if ( '' === $query ) {
			return $this->errors->validationFailed( 'query is required.', array( 'query' => array( 'Search query is required.' ) ) );
		}
		$baseUrl = $this->s->getApiBaseUrl( 'crawl4ai' ) ?? 'http://localhost:11235';
		try {
			$request  = new \Nyholm\Psr7\Request( 'POST', $baseUrl . '/search', array( 'Content-Type' => 'application/json' ), json_encode( array( 'query' => $query ) ) );
			$response = $this->h->sendRequest( $request );
			$data     = json_decode( (string) $response->getBody(), true );
			$results  = $data['results'] ?? array();
			return $this->collection( 'Found ' . count( $results ) . ' results.', $results, count( $results ) );
		} catch ( \Exception $e ) {
			return $this->errors->create( 'crawl4ai_failed', "Crawl4AI lookup failed: {$e->getMessage()}" ); }
	}
}
