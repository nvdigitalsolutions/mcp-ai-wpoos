<?php
/** List OpenAI Files. @package Nvoos\Core @since 2.0.0 @license MIT */
declare(strict_types=1); namespace Nvoos\Core\Tool;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface; use Nvoos\Core\Domain\Contract\HttpClientInterface; use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
class ListOpenAIFilesTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e, private readonly SettingsStoreInterface $s, private readonly HttpClientInterface $h ) { parent::__construct( $e ); }
	public function getSlug(): string { return 'list_openai_files'; } public function getName(): string { return 'List OpenAI Files'; } public function getDescription(): string { return 'Lists files uploaded to OpenAI for batch processing, fine-tuning, or vector stores.'; }
	public function getParametersSchema(): array { return array('type'=>'object','properties'=>array('purpose'=>array('type'=>'string','description'=>'Filter by purpose (e.g. batch, fine-tune, assistants).')),'additionalProperties'=>false); }
	public function getRequiredCapability(): string { return 'manage_options'; }
	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$key = $this->s->getApiKey('openai'); if (null===$key||''===$key) return $this->errors->create('missing_key','No OpenAI API key configured.');
		$purpose = $this->stringParam($arguments,'purpose'); $url = 'https://api.openai.com/v1/files'; if (''!==$purpose) $url .= '?purpose='.\urlencode($purpose);
		try { $r = $this->h->send('GET',$url,array('Authorization'=>"Bearer {$key}")); $d = \json_decode($r->body,true);
			if ($r->statusCode>=400) return $this->errors->create('api_error',$d['error']['message']??'OpenAI API error.');
			return $this->collection('Found '.\count($d['data']??array()).' file(s).',$d['data']??array(),\count($d['data']??array())); }
		catch (\Throwable $e) { return $this->errors->create('request_failed',$e->getMessage()); }
	}
}
