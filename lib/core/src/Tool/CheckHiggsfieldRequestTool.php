<?php
/**
 * Check Higgsfield Request — poll a generation request's state.
 *
 * Framework-agnostic equivalent of WP_MCP_AI_Tool_Check_Higgsfield_Request.
 * Terminal states: completed, failed, nsfw, canceled; active states:
 * queued, in_progress.
 *
 * @package Nvoos\Core @since 2.0.0 @license MIT
 */
declare(strict_types=1);
namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;

class CheckHiggsfieldRequestTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e, private readonly SettingsStoreInterface $s, private readonly HttpClientInterface $h ) { parent::__construct( $e ); }
	public function getSlug(): string { return 'check_higgsfield_request'; }
	public function getName(): string { return 'Check Higgsfield Request'; }
	public function getDescription(): string { return 'Checks the status of a Higgsfield generation request by request_id.'; }
	public function getParametersSchema(): array {
		return array( 'type' => 'object', 'properties' => array( 'request_id' => array( 'type' => 'string', 'description' => 'Higgsfield request ID (UUID).', 'minLength' => 1 ) ), 'required' => array( 'request_id' ), 'additionalProperties' => false );
	}
	public function getRequiredCapability(): string { return 'edit_posts'; }

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$requestId = $this->stringParam( $arguments, 'request_id' );
		if ( '' === $requestId ) return $this->errors->validationFailed( 'A request ID is required.', array( 'request_id' => array( 'Required.' ) ) );

		$credential = $this->s->getApiKey( 'higgsfield' );
		if ( null === $credential || '' === $credential ) return $this->errors->create( 'missing_key', 'No Higgsfield API credentials configured. Provide the combined "KEY_ID:KEY_SECRET" credential.' );

		$parts = \explode( ':', $credential, 2 );
		if ( 2 !== \count( $parts ) ) {
			return $this->errors->create( 'invalid_key', 'Invalid Higgsfield credentials. Expected "KEY_ID:KEY_SECRET".' );
		}

		$headers = array(
			'Authorization' => 'Key ' . \trim( $parts[0] ) . ':' . \trim( $parts[1] ),
		);

		try {
			$r = $this->h->send( 'GET', 'https://api.higgsfield.ai/requests/' . \rawurlencode( $requestId ) . '/status', $headers );
			$d = \json_decode( $r->body, true );
			if ( $r->statusCode >= 400 ) {
				$err = $d['detail'] ?? 'Higgsfield status error.';
				return $this->errors->create( 'higgsfield_error', (string) $err );
			}

			$status   = $d['status'] ?? 'unknown';
			$terminal = \in_array( $status, array( 'completed', 'failed', 'nsfw', 'canceled' ), true );

			return $this->success(
				"Request {$requestId}: {$status}",
				array(
					'request_id' => $requestId,
					'status'     => $status,
					'terminal'   => $terminal,
					'error'      => $d['error'] ?? null,
					'video_url'  => $d['video']['url'] ?? null,
					'image_urls' => \array_map( static fn ( array $i ): string => (string) ( $i['url'] ?? '' ), $d['images'] ?? array() ),
				)
			);
		} catch ( \Throwable $e ) {
			return $this->errors->create( 'request_failed', $e->getMessage() );
		}
	}
}
