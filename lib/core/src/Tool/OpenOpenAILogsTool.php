<?php
/** Open OpenAI Logs. @package Nvoos\Core @since 2.0.0 @license MIT */
declare(strict_types=1); namespace Nvoos\Core\Tool;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface; use Nvoos\Core\Domain\Contract\HttpClientInterface; use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
class OpenOpenAILogsTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e, private readonly SettingsStoreInterface $s, private readonly HttpClientInterface $h ) { parent::__construct( $e ); }
	public function getSlug(): string { return 'open_openai_logs'; } public function getName(): string { return 'Open OpenAI Logs'; } public function getDescription(): string { return 'Retrieves OpenAI API usage logs and activity.'; }
	public function getParametersSchema(): array { return array('type'=>'object','properties'=>array('limit'=>array('type'=>'integer','description'=>'Max entries.','minimum'=>1,'maximum'=>100,'default'=>20)),'additionalProperties'=>false); }
	public function getRequiredCapability(): string { return 'manage_options'; }
	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$key = $this->s->getApiKey('openai'); if (null===$key||''===$key) return $this->errors->create('missing_key','No OpenAI API key configured.');
		$limit = \max(1,\min(100,$this->intParam($arguments,'limit',20)));
		try { $r = $this->h->send('GET',"https://api.openai.com/v1/organization/usage?limit={$limit}",array('Authorization'=>"Bearer {$key}")); $d = \json_decode($r->body,true);
			if ($r->statusCode>=400) return $this->errors->create('api_error',$d['error']['message']??'OpenAI API error.');
			return $this->success('Usage logs retrieved.',array('data'=>$d['data']??array(),'count'=>\count($d['data']??array()))); }
		catch (\Throwable $e) { return $this->errors->create('request_failed',$e->getMessage()); }
	}
}
