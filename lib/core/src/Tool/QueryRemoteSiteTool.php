<?php
/** Query Remote Site — query remote WordPress/MCP sites. @package Nvoos\Core @since 2.0.0 @license MIT */
declare(strict_types=1); namespace Nvoos\Core\Tool;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface; use Nvoos\Core\Domain\Contract\HttpClientInterface; use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
class QueryRemoteSiteTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e, private readonly SettingsStoreInterface $s, private readonly HttpClientInterface $h ) { parent::__construct( $e ); }
	public function getSlug(): string { return 'query_remote_site'; }
	public function getName(): string { return 'Query Remote Site'; }
	public function getDescription(): string { return 'Queries a remote WordPress site via its REST API or MCP endpoint.'; }
	public function getParametersSchema(): array { return array('type'=>'object','properties'=>array('site_url'=>array('type'=>'string','description'=>'Base URL of the remote site.'),'endpoint'=>array('type'=>'string','description'=>'API endpoint path (e.g. /wp-json/wp/v2/posts).'),'method'=>array('type'=>'string','description'=>'HTTP method.','enum'=>array('GET','POST'),'default'=>'GET'),'body'=>array('type'=>'object','description'=>'Request body for POST.'),'auth_token'=>array('type'=>'string','description'=>'Optional bearer token.')),'required'=>array('site_url','endpoint'),'additionalProperties'=>false); }
	public function getRequiredCapability(): string { return 'manage_options'; }
	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$siteUrl = \rtrim($this->stringParam($arguments,'site_url'),'/'); $endpoint = $this->stringParam($arguments,'endpoint');
		if ( '' === $siteUrl || '' === $endpoint ) return $this->errors->validationFailed( 'site_url and endpoint required.', array('site_url'=>array('Required.'),'endpoint'=>array('Required.')) );
		$method = $this->stringParam($arguments,'method','GET');
		$token  = $this->stringParam($arguments,'auth_token');
		$body   = $arguments['body'] ?? null;
		$headers = array('Accept'=>'application/json');
		if ( '' !== $token ) $headers['Authorization'] = "Bearer {$token}";
		try {
			$r = $this->h->send( $method, "{$siteUrl}{$endpoint}", $headers, \is_array($body)?\json_encode($body):null );
			$d = \json_decode( $r->body, true );
			if ( $r->statusCode >= 400 ) return $this->errors->create( 'remote_error', "Remote site returned HTTP {$r->statusCode}.", array('status'=>$r->statusCode,'body'=>$d) );
			return $this->success( "Query successful (HTTP {$r->statusCode}).", array('status'=>$r->statusCode,'data'=>$d) );
		} catch ( \Throwable $e ) { return $this->errors->create( 'request_failed', $e->getMessage() ); }
	}
}
