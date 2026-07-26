<?php
/** Search Places — Google Places search. @package Nvoos\Core @since 2.0.0 @license MIT */
declare(strict_types=1); namespace Nvoos\Core\Tool;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface; use Nvoos\Core\Domain\Contract\HttpClientInterface; use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
class SearchPlacesTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e, private readonly SettingsStoreInterface $s, private readonly HttpClientInterface $h ) { parent::__construct( $e ); }
	public function getSlug(): string { return 'search_places'; }
	public function getName(): string { return 'Search Places'; }
	public function getDescription(): string { return 'Searches Google Places for businesses, landmarks, and locations.'; }
	public function getParametersSchema(): array { return array('type'=>'object','properties'=>array('query'=>array('type'=>'string','description'=>'Place search query.'),'location'=>array('type'=>'string','description'=>'Lat,lng for location bias (e.g. 40.7,-74.0).'),'radius'=>array('type'=>'integer','description'=>'Search radius in meters.','minimum'=>1,'maximum'=>50000,'default'=>5000)),'required'=>array('query'),'additionalProperties'=>false); }
	public function getRequiredCapability(): string { return 'edit_posts'; }
	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$q = $this->stringParam( $arguments, 'query' ); if ( '' === $q ) return $this->errors->validationFailed( 'Query required.', array('query'=>array('Required.')) );
		$key = $this->s->getApiKey( 'google' ) ?? $this->s->get( 'google_places_api_key', '' ); if ( '' === (string)$key ) return $this->errors->create( 'missing_key', 'Google Places API key not configured.' );
		$loc = $this->stringParam( $arguments, 'location' );
		$radius = \max(1,\min(50000,$this->intParam($arguments,'radius',5000)));
		$url = "https://maps.googleapis.com/maps/api/place/textsearch/json?query=".\urlencode($q)."&key={$key}&radius={$radius}";
		if ( '' !== $loc ) $url .= "&location={$loc}";
		try {
			$r = $this->h->send( 'GET', $url ); $d = \json_decode( $r->body, true );
			if ( 'OK' !== ($d['status']??'') && 'ZERO_RESULTS' !== ($d['status']??'') ) { $err = $d['error_message'] ?? $d['status'] ?? 'Places API error.'; return $this->errors->create( 'places_error', $err ); }
			return $this->collection( 'Found '.\count($d['results']??array()).' place(s).', $d['results']??array(), \count($d['results']??array()) );
		} catch ( \Throwable $e ) { return $this->errors->create( 'request_failed', $e->getMessage() ); }
	}
}
