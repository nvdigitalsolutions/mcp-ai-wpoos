<?php
/**
 * Get Open-Meteo Forecast — retrieves weather forecasts from Open-Meteo.
 *
 * Calls the free Open-Meteo API (no auth required). Zero WordPress dependencies.
 *
 * @package Oos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Oos\Core\Tool;

use Oos\Core\Domain\Contract\ErrorFactoryInterface;
use Psr\Http\Client\ClientInterface as HttpClientInterface;

class GetOpenMeteoForecastTool extends AbstractTool {

	private const API_URL = 'https://api.open-meteo.com/v1/forecast';

	public function __construct(
		ErrorFactoryInterface $errors,
		private readonly HttpClientInterface $http,
	) {
		parent::__construct( $errors );
	}

	public function getSlug(): string {
		return 'get_open_meteo_forecast'; }
	public function getName(): string {
		return 'Get Open-Meteo Forecast'; }

	public function getDescription(): string {
		return 'Retrieves weather forecasts from Open-Meteo for any latitude/longitude. Free, no API key required.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'latitude'  => array(
					'type'        => 'number',
					'description' => 'Latitude (-90 to 90).',
					'minimum'     => -90,
					'maximum'     => 90,
				),
				'longitude' => array(
					'type'        => 'number',
					'description' => 'Longitude (-180 to 180).',
					'minimum'     => -180,
					'maximum'     => 180,
				),
				'hourly'    => array(
					'type'        => 'string',
					'description' => 'Comma-separated hourly variables (temperature_2m, precipitation, etc.). Default: temperature_2m,precipitation,weathercode.',
					'default'     => 'temperature_2m,precipitation,weathercode,windspeed_10m',
				),
				'days'      => array(
					'type'        => 'integer',
					'description' => 'Forecast days (1-16). Default: 3.',
					'minimum'     => 1,
					'maximum'     => 16,
					'default'     => 3,
				),
			),
			'required'             => array( 'latitude', 'longitude' ),
			'additionalProperties' => false,
		);
	}

	public function getRequiredCapability(): string {
		return 'read'; }

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$latitude  = (float) ( $arguments['latitude'] ?? 0 );
		$longitude = (float) ( $arguments['longitude'] ?? 0 );

		$params = array(
			'latitude'      => $latitude,
			'longitude'     => $longitude,
			'hourly'        => $this->stringParam( $arguments, 'hourly', 'temperature_2m,precipitation,weathercode,windspeed_10m' ),
			'forecast_days' => $this->intParam( $arguments, 'days', 3 ),
			'timezone'      => 'auto',
		);

		try {
			$request  = new \Nyholm\Psr7\Request( 'GET', self::API_URL . '?' . \http_build_query( $params ) );
			$response = $this->http->sendRequest( $request );
			$data     = \json_decode( (string) $response->getBody(), true );

			if ( ! is_array( $data ) || isset( $data['error'] ) ) {
				return $this->errors->create(
					'forecast_failed',
					$data['reason'] ?? $data['error'] ?? 'Open-Meteo returned an error.',
				);
			}

			return $this->success(
				'Weather forecast retrieved.',
				array(
					'latitude'     => $data['latitude'] ?? $latitude,
					'longitude'    => $data['longitude'] ?? $longitude,
					'timezone'     => $data['timezone'] ?? '',
					'hourly_units' => $data['hourly_units'] ?? array(),
					'hourly'       => $data['hourly'] ?? array(),
				)
			);

		} catch ( \Exception $e ) {
			return $this->errors->create( 'forecast_failed', "Open-Meteo request failed: {$e->getMessage()}" );
		}
	}
}
