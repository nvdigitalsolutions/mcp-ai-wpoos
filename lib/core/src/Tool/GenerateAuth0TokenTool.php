<?php
/** Generate Auth0 Token. @package Nvoos\Core @since 2.0.0 @license MIT */
declare(strict_types=1); namespace Nvoos\Core\Tool;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface; use Nvoos\Core\Domain\Contract\HttpClientInterface; use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
class GenerateAuth0TokenTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e, private readonly SettingsStoreInterface $s, private readonly HttpClientInterface $h ) { parent::__construct( $e ); }
	public function getSlug(): string { return 'generate_auth0_token'; } public function getName(): string { return 'Generate Auth0 Token'; } public function getDescription(): string { return 'Generates an Auth0 authentication token.'; }
	public function getParametersSchema(): array { return array('type'=>'object','properties'=>array('client_id'=>array('type'=>'string','description'=>'Auth0 client ID.'), 'client_secret'=>array('type'=>'string','description'=>'Auth0 client secret.'), 'audience'=>array('type'=>'string','description'=>'Token audience.')),'required'=>array('client_id','client_secret'),'additionalProperties'=>false); }
	public function getRequiredCapability(): string { return 'manage_options'; }
	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$domain = $this->s->get('auth0_domain',''); $clientId = $this->stringParam($arguments,'client_id'); $secret = $this->stringParam($arguments,'client_secret');
		if (''===$domain||''===$clientId||''===$secret) return $this->errors->validationFailed('Auth0 domain, client_id, and client_secret required.',array('client_id'=>array('Required.')));
		try { $r = $this->h->send('POST',"https://{$domain}/oauth/token",array('Content-Type'=>'application/json'),\json_encode(array('client_id'=>$clientId,'client_secret'=>$secret,'audience'=>$this->stringParam($arguments,'audience',''),'grant_type'=>'client_credentials')));
			$d = \json_decode($r->body,true); if ($r->statusCode>=400) return $this->errors->create('auth0_error',$d['error_description']??$d['error']??'Auth0 error.');
			return $this->success('Token generated.',array('token_type'=>$d['token_type']??'Bearer','expires_in'=>$d['expires_in']??null)); }
		catch (\Throwable $e) { return $this->errors->create('request_failed',$e->getMessage()); }
	}
}
