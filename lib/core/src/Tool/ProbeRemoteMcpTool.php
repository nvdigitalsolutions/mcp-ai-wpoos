<?php
/** Probe Remote MCP — connectivity test for remote MCP endpoints.
 *
 * @package Oos\Core @since 1.0.0 @license MIT */
declare(strict_types=1);
namespace Oos\Core\Tool;

use Oos\Core\Domain\Contract\ErrorFactoryInterface;
use Psr\Http\Client\ClientInterface as HttpClientInterface;
class ProbeRemoteMcpTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e, private readonly HttpClientInterface $h ) {
		parent::__construct( $e );}
	public function getSlug(): string {
		return 'probe_remote_mcp'; }
	public function getName(): string {
		return 'Probe Remote MCP'; }
	public function getDescription(): string {
		return 'Tests connectivity to a remote MCP (Model Context Protocol) server endpoint.'; }
	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'url' => array(
					'type'        => 'string',
					'description' => 'Remote MCP server URL',
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
			return $this->errors->validationFailed( 'url is required.', array( 'url' => array( 'URL is required.' ) ) );
		}
		try {
			$start    = microtime( true );
			$request  = new \Nyholm\Psr7\Request( 'GET', $url, array( 'Accept' => 'application/json' ) );
			$response = $this->h->sendRequest( $request );
			$latency  = round( ( microtime( true ) - $start ) * 1000 );
			return $this->success(
				"MCP endpoint responded in {$latency}ms.",
				array(
					'url'        => $url,
					'status'     => $response->getStatusCode(),
					'latency_ms' => $latency,
					'reachable'  => $response->getStatusCode() < 500,
				)
			);
		} catch ( \Exception $e ) {
			return $this->errors->create(
				'probe_failed',
				"Could not reach {$url}: {$e->getMessage()}",
				array(
					'url'       => $url,
					'reachable' => false,
				)
			);
		}
	}
}
