<?php
/** Search Drive — Google Drive search. @package Nvoos\Core @since 2.0.0 @license MIT */
declare(strict_types=1); namespace Nvoos\Core\Tool;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface; use Nvoos\Core\Domain\Contract\HttpClientInterface; use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
class SearchDriveTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e, private readonly SettingsStoreInterface $s, private readonly HttpClientInterface $h ) { parent::__construct( $e ); }
	public function getSlug(): string { return 'search_drive'; }
	public function getName(): string { return 'Search Google Drive'; }
	public function getDescription(): string { return 'Searches Google Drive for files matching a query.'; }
	public function getParametersSchema(): array { return array('type'=>'object','properties'=>array('query'=>array('type'=>'string','description'=>'Search query.'),'limit'=>array('type'=>'integer','description'=>'Max results (1-100).','minimum'=>1,'maximum'=>100,'default'=>10)),'required'=>array('query'),'additionalProperties'=>false); }
	public function getRequiredCapability(): string { return 'edit_posts'; }
	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$q = $this->stringParam( $arguments, 'query' ); if ( '' === $q ) return $this->errors->validationFailed( 'Query required.', array('query'=>array('Required.')) );
		$token = $this->s->get( 'google_drive_token', '' ); if ( '' === (string)$token ) return $this->errors->create( 'missing_token', 'Google Drive token not configured.' );
		$limit = \max(1,\min(100,$this->intParam($arguments,'limit',10)));
		try {
			$r = $this->h->send( 'GET', "https://www.googleapis.com/drive/v3/files?q=".\urlencode("name contains '{$q}'")."&pageSize={$limit}", array('Authorization'=>"Bearer {$token}") );
			$d = \json_decode( $r->body, true );
			if ( $r->statusCode >= 400 ) { $err = $d['error']['message'] ?? 'Drive API error.'; return $this->errors->create( 'drive_error', $err ); }
			return $this->collection( 'Found '.\count($d['files']??array()).' file(s).', $d['files']??array(), \count($d['files']??array()) );
		} catch ( \Throwable $e ) { return $this->errors->create( 'request_failed', $e->getMessage() ); }
	}
}
