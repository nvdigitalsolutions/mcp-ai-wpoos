<?php
/**
 * Get NHC Active Storms — retrieves hurricane/tropical storm data from NOAA.
 *
 * Calls the public National Hurricane Center JSON feed. Zero WordPress dependencies.
 *
 * @package Nvoos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;

class GetNhcActiveStormsTool extends AbstractTool {

	private const API_URL = 'https://www.nhc.noaa.gov/CurrentStorms.json';

	public function __construct(
		ErrorFactoryInterface $errors,
		private readonly HttpClientInterface $http,
	) {
		parent::__construct( $errors );
	}

	public function getSlug(): string {
		return 'get_nhc_active_storms'; }
	public function getName(): string {
		return 'Get NHC Active Storms'; }

	public function getDescription(): string {
		return 'Retrieves active tropical storms and hurricanes from the NOAA National Hurricane Center.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
		);
	}

	public function getRequiredCapability(): string {
		return 'read'; }

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		try {
			$response = $this->http->send( 'GET', self::API_URL );
			$data     = \json_decode( $response->body, true );

			$storms = $data['activeStorms'] ?? array();

			if ( ! is_array( $storms ) || array() === $storms ) {
				return $this->success( 'No active tropical storms at this time.' );
			}

			$formatted = \array_map(
				function ( array $s ): array {
					return array(
						'name'           => $s['name'] ?? $s['stormName'] ?? '',
						'category'       => $s['category'] ?? '',
						'wind_speed_mph' => (int) ( $s['maxWindSpeed'] ?? 0 ),
						'pressure_mb'    => (int) ( $s['minCentralPressure'] ?? 0 ),
						'latitude'       => (float) ( $s['latitude'] ?? 0 ),
						'longitude'      => (float) ( $s['longitude'] ?? 0 ),
						'last_updated'   => $s['lastUpdate'] ?? '',
					);
				},
				$storms
			);

			return $this->collection(
				'Found ' . \count( $formatted ) . ' active storm(s).',
				$formatted,
				\count( $formatted ),
			);

		} catch ( \Exception $e ) {
			return $this->errors->create( 'nhc_failed', "NHC request failed: {$e->getMessage()}" );
		}
	}
}
