<?php
/**
 * Get Model Information — retrieves details about a specific AI model.
 *
 * Calls the OpenAI API to get model metadata. Zero WordPress dependencies.
 *
 * @package Nvoos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Psr\Http\Client\ClientInterface as HttpClientInterface;

class GetModelInformationTool extends AbstractTool {

	public function __construct(
		ErrorFactoryInterface $errors,
		private readonly SettingsStoreInterface $settings,
		private readonly HttpClientInterface $http,
	) {
		parent::__construct( $errors );
	}

	public function getSlug(): string {
		return 'get_model_information'; }
	public function getName(): string {
		return 'Get Model Information'; }

	public function getDescription(): string {
		return 'Retrieves detailed information about a specific AI model from its provider API.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'model'    => array(
					'type'        => 'string',
					'description' => 'Model identifier (e.g., "gpt-4o", "gemini-2.0-flash").',
				),
				'provider' => array(
					'type'        => 'string',
					'description' => 'Provider slug. Default: openai.',
					'default'     => 'openai',
				),
			),
			'required'             => array( 'model' ),
			'additionalProperties' => false,
		);
	}

	public function getRequiredCapability(): string {
		return 'read'; }

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$model    = $this->stringParam( $arguments, 'model' );
		$provider = $this->stringParam( $arguments, 'provider', 'openai' );

		$apiKey  = $this->settings->getApiKey( $provider );
		$baseUrl = $this->settings->getApiBaseUrl( $provider );

		if ( null === $apiKey || '' === $apiKey ) {
			return $this->errors->create(
				'missing_api_key',
				"No API key configured for provider '{$provider}'.",
				array( 'status' => 400 ),
			);
		}

		$defaults = array(
			'openai' => 'https://api.openai.com/v1',
			'gemini' => 'https://generativelanguage.googleapis.com/v1beta',
		);
		$baseUrl  = $baseUrl ?? ( $defaults[ $provider ] ?? '' );

		if ( '' === $baseUrl ) {
			return $this->errors->create( 'unknown_provider', "Unknown provider: {$provider}" );
		}

		try {
			$request  = new \Nyholm\Psr7\Request(
				'GET',
				$baseUrl . '/models/' . \urlencode( $model ),
				array( 'Authorization' => "Bearer {$apiKey}" ),
			);
			$response = $this->http->sendRequest( $request );

			if ( $response->getStatusCode() >= 400 ) {
				return $this->errors->create(
					'model_not_found',
					"Model '{$model}' not found or not accessible. HTTP {$response->getStatusCode()}.",
				);
			}

			$data = \json_decode( (string) $response->getBody(), true );

			return $this->success( 'Model information retrieved.', $data );

		} catch ( \Exception $e ) {
			return $this->errors->create( 'model_info_failed', $e->getMessage() );
		}
	}
}
