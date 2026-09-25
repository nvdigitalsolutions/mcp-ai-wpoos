<?php
/**
 * Cancel Higgsfield Request — cancel a queued generation request.
 *
 * Framework-agnostic equivalent of WP_MCP_AI_Tool_Cancel_Higgsfield_Request.
 * 202 = canceled; 400 = already started and can no longer be canceled.
 *
 * @package Nvoos\Core @since 2.0.0 @license MIT
 */
declare(strict_types=1);
namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;

class CancelHiggsfieldRequestTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e, private readonly SettingsStoreInterface $s, private readonly HttpClientInterface $h ) { parent::__construct( $e ); }
	public function getSlug(): string { return 'cancel_higgsfield_request'; }
	public function getName(): string { return 'Cancel Higgsfield Request'; }
	public function getDescription(): string { return 'Cancels a queued Higgsfield generation request that has not started processing.'; }
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
			$r = $this->h->send( 'POST', 'https://api.higgsfield.ai/requests/' . \rawurlencode( $requestId ) . '/cancel', $headers );
			$d = \json_decode( $r->body, true );

			if ( 202 === $r->statusCode ) {
				return $this->success( 'Request canceled.', array( 'request_id' => $requestId, 'status' => 'canceled' ) );
			}

			$err = $d['detail'] ?? 'Failed to cancel the request.';
			return $this->errors->create( 400 === $r->statusCode ? 'cancel_started' : 'cancel_failed', (string) $err );
		} catch ( \Throwable $e ) {
			return $this->errors->create( 'request_failed', $e->getMessage() );
		}
	}
}
