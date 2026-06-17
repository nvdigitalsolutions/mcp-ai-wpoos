<?php
/**
 * Abstract HuggingFace tool base — shared logic for all 11 dataset tools.
 *
 * All HuggingFace dataset tools call the same API (datasets-server.huggingface.co)
 * and share the same auth pattern (optional HF token). This base eliminates
 * duplication across the 11 tools.
 *
 * @package Nvoos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;

abstract class AbstractHuggingFaceTool extends AbstractTool {

	protected const API_BASE = 'https://datasets-server.huggingface.co';

	public function __construct(
		ErrorFactoryInterface $errors,
		protected readonly SettingsStoreInterface $settings,
		protected readonly HttpClientInterface $http,
	) {
		parent::__construct( $errors );
	}

	public function getRequiredCapability(): string {
		return 'read'; }

	/**
	 * Build auth headers — HuggingFace optionally uses a bearer token.
	 */
	protected function buildHeaders(): array {
		$headers = array( 'Accept' => 'application/json' );
		$token   = $this->settings->getApiKey( 'huggingface' );

		if ( null !== $token && '' !== $token ) {
			$headers['Authorization'] = "Bearer {$token}";
		}

		return $headers;
	}

	/**
	 * Make a GET request to the HuggingFace Datasets API.
	 */
	protected function apiGet( string $path, array $query = array() ): mixed {
		$url = self::API_BASE . $path;
		if ( array() !== $query ) {
			$url .= '?' . \http_build_query( $query );
		}

		$response = $this->http->send( 'GET', $url, $this->buildHeaders() );

		if ( $response->statusCode >= 400 ) {
			return $this->errors->create(
				'hf_api_error',
				"HuggingFace API returned HTTP {$response->statusCode}.",
				array( 'status' => $response->statusCode ),
			);
		}

		return \json_decode( $response->body, true );
	}

	/**
	 * Validate that a dataset name parameter is present.
	 */
	protected function requireDataset( array $arguments ): string {
		$dataset = $this->stringParam( $arguments, 'dataset' );
		if ( '' === $dataset ) {
			// Return type is mixed from requireParam but we know this path.
			$result = $this->requireParam( $arguments, 'dataset' );
			if ( $this->errors->isError( $result ) ) {
				return ''; // Error already handled — caller should propagate.
			}
			return (string) $result;
		}
		return $dataset;
	}
}
