<?php
/** Search Gmail — Gmail search. @package Nvoos\Core @since 2.0.0 @license MIT */
declare(strict_types=1); namespace Nvoos\Core\Tool;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface; use Nvoos\Core\Domain\Contract\HttpClientInterface; use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
class SearchGmailTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e, private readonly SettingsStoreInterface $s, private readonly HttpClientInterface $h ) { parent::__construct( $e ); }
	public function getSlug(): string { return 'search_gmail'; }
	public function getName(): string { return 'Search Gmail'; }
	public function getDescription(): string { return 'Searches Gmail messages matching a query.'; }
	public function getParametersSchema(): array { return array('type'=>'object','properties'=>array('query'=>array('type'=>'string','description'=>'Gmail search query.'),'limit'=>array('type'=>'integer','description'=>'Max results.','minimum'=>1,'maximum'=>50,'default'=>10)),'required'=>array('query'),'additionalProperties'=>false); }
	public function getRequiredCapability(): string { return 'edit_posts'; }
	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$q = $this->stringParam( $arguments, 'query' ); if ( '' === $q ) return $this->errors->validationFailed( 'Query required.', array('query'=>array('Required.')) );
		$token = $this->s->get( 'gmail_token', '' ); if ( '' === (string)$token ) return $this->errors->create( 'missing_token', 'Gmail token not configured.' );
		$limit = \max(1,\min(50,$this->intParam($arguments,'limit',10)));
		try {
			$r = $this->h->send( 'GET', "https://gmail.googleapis.com/gmail/v1/users/me/messages?q=".\urlencode($q)."&maxResults={$limit}", array('Authorization'=>"Bearer {$token}") );
			$d = \json_decode( $r->body, true );
			if ( $r->statusCode >= 400 ) { $err = $d['error']['message'] ?? 'Gmail API error.'; return $this->errors->create( 'gmail_error', $err ); }
			return $this->collection( 'Found '.\count($d['messages']??array()).' message(s).', $d['messages']??array(), $d['resultSizeEstimate']??0 );
		} catch ( \Throwable $e ) { return $this->errors->create( 'request_failed', $e->getMessage() ); }
	}
}
