<?php
/** @package Oos\Core @since 1.0.0 @license MIT */
declare(strict_types=1);
namespace Oos\Core\Tool;

use Oos\Core\Domain\Contract\ErrorFactoryInterface;
use Oos\Core\Domain\Contract\SettingsStoreInterface;
use Psr\Http\Client\ClientInterface as HttpClientInterface;
class GeocodeAddressTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e, private readonly SettingsStoreInterface $s, private readonly HttpClientInterface $h ) {
		parent::__construct( $e );}
	public function getSlug(): string {
		return 'geocode_address'; }
	public function getName(): string {
		return 'Geocode Address'; }
	public function getDescription(): string {
		return 'Converts an address into geographic coordinates using the Google Maps Geocoding API.'; }
	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'address' => array(
					'type'        => 'string',
					'description' => 'Address to geocode',
				),
			),
			'required'             => array( 'address' ),
			'additionalProperties' => false,
		); }
	public function getRequiredCapability(): string {
		return 'read'; }
	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$address = $this->stringParam( $arguments, 'address' );
		if ( '' === $address ) {
			return $this->errors->validationFailed( 'address is required.', array( 'address' => array( 'Address is required.' ) ) );
		}
		$apiKey = $this->s->getApiKey( 'google_maps' );
		if ( null === $apiKey || '' === $apiKey ) {
			return $this->errors->create( 'missing_api_key', 'No Google Maps API key configured.', array( 'status' => 400 ) );
		}
		try {
			$url  = 'https://maps.googleapis.com/maps/api/geocode/json?' . http_build_query(
				array(
					'address' => $address,
					'key'     => $apiKey,
				)
			);
			$resp = $this->h->sendRequest( new \Nyholm\Psr7\Request( 'GET', $url ) );
			$data = json_decode( (string) $resp->getBody(), true );
			if ( ! is_array( $data ) || 'OK' !== ( $data['status'] ?? '' ) ) {
				return $this->errors->create( 'geocode_failed', $data['error_message'] ?? $data['status'] ?? 'Geocoding failed.' );
			}
			$r = $data['results'][0] ?? null;
			if ( ! $r ) {
				return $this->emptyResult( 'No results found for that address.' );
			}
			$loc = $r['geometry']['location'] ?? array();
			return $this->success(
				'Address geocoded.',
				array(
					'address'   => $r['formatted_address'] ?? $address,
					'latitude'  => (float) ( $loc['lat'] ?? 0 ),
					'longitude' => (float) ( $loc['lng'] ?? 0 ),
					'place_id'  => $r['place_id'] ?? '',
					'types'     => $r['types'] ?? array(),
				)
			);
		} catch ( \Exception $e ) {
			return $this->errors->create( 'geocode_failed', $e->getMessage() ); }
	}
}
