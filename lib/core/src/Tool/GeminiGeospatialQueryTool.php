<?php
/** Gemini Geospatial Query — Gemini geospatial analysis. @package Nvoos\Core @since 2.0.0 @license MIT */
declare(strict_types=1); namespace Nvoos\Core\Tool;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface; use Nvoos\Core\Domain\Contract\HttpClientInterface; use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
class GeminiGeospatialQueryTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e, private readonly SettingsStoreInterface $s, private readonly HttpClientInterface $h ) { parent::__construct( $e ); }
	public function getSlug(): string { return 'gemini_geospatial_query'; }
	public function getName(): string { return 'Gemini Geospatial Query'; }
	public function getDescription(): string { return 'Queries Google Gemini for geospatial analysis using location data and maps.'; }
	public function getParametersSchema(): array { return array('type'=>'object','properties'=>array('query'=>array('type'=>'string','description'=>'Geospatial query or analysis request.'),'latitude'=>array('type'=>'number','description'=>'Latitude.'),'longitude'=>array('type'=>'number','description'=>'Longitude.'),'radius_km'=>array('type'=>'number','description'=>'Search radius in km.','minimum'=>0.1,'maximum'=>1000,'default'=>10)),'required'=>array('query'),'additionalProperties'=>false); }
	public function getRequiredCapability(): string { return 'edit_posts'; }
	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$q = $this->stringParam( $arguments, 'query' ); if ( '' === $q ) return $this->errors->validationFailed( 'Query required.', array('query'=>array('Required.')) );
		$key = $this->s->getApiKey( 'gemini' ); if ( null === $key || '' === $key ) return $this->errors->create( 'missing_key', 'No Gemini API key configured.' );
		$lat = (float)($arguments['latitude']??0); $lng = (float)($arguments['longitude']??0); $radius = (float)($arguments['radius_km']??10);
		$locStr = (0!==$lat||0!==$lng) ? " Location: {$lat},{$lng} (radius {$radius}km)." : '';
		try {
			$r = $this->h->send( 'POST', "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key={$key}", array('Content-Type'=>'application/json'), \json_encode(array('contents'=>array(array('parts'=>array(array('text'=>"Geospatial analysis: {$q}.{$locStr}")))))));
			$d = \json_decode( $r->body, true );
			if ( $r->statusCode >= 400 ) { $err = $d['error']['message'] ?? 'Gemini error.'; return $this->errors->create( 'gemini_error', $err ); }
			$text = $d['candidates'][0]['content']['parts'][0]['text'] ?? '';
			return $this->success( 'Geospatial query completed.', array( 'query'=>$q, 'latitude'=>$lat, 'longitude'=>$lng, 'result'=>$text ) );
		} catch ( \Throwable $e ) { return $this->errors->create( 'request_failed', $e->getMessage() ); }
	}
}
