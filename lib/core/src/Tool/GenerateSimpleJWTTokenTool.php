<?php
/** Generate Simple JWT Token. @package Nvoos\Core @since 2.0.0 @license MIT */
declare(strict_types=1); namespace Nvoos\Core\Tool;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
class GenerateSimpleJWTTokenTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e ) { parent::__construct( $e ); }
	public function getSlug(): string { return 'generate_simple_jwt_token'; } public function getName(): string { return 'Generate Simple JWT Token'; } public function getDescription(): string { return 'Generates a simple JWT token for testing or development.'; }
	public function getParametersSchema(): array { return array('type'=>'object','properties'=>array('payload'=>array('type'=>'object','description'=>'JWT payload claims.'), 'secret'=>array('type'=>'string','description'=>'Signing secret.'), 'expires_in'=>array('type'=>'integer','description'=>'Expiry in seconds.','minimum'=>60,'maximum'=>86400,'default'=>3600)),'required'=>array('payload','secret'),'additionalProperties'=>false); }
	public function getRequiredCapability(): string { return 'manage_options'; }
	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$payload = $this->arrayParam($arguments,'payload'); $secret = $this->stringParam($arguments,'secret');
		if (array()===$payload||''===$secret) return $this->errors->validationFailed('payload and secret required.',array('payload'=>array('Required.'),'secret'=>array('Required.')));
		$payload['iat'] = \time(); $payload['exp'] = \time() + \max(60,\min(86400,$this->intParam($arguments,'expires_in',3600)));
		$header = \base64_encode(\json_encode(array('alg'=>'HS256','typ'=>'JWT'))); $payloadB64 = \base64_encode(\json_encode($payload));
		$sig = \base64_encode(\hash_hmac('sha256',"{$header}.{$payloadB64}",$secret,true));
		return $this->success( 'JWT token generated.', array('token'=>"{$header}.{$payloadB64}.{$sig}",'expires_at'=>\gmdate('c',$payload['exp'])) );
	}
}
