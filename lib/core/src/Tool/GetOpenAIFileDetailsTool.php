<?php
/** Get OpenAI File Details. @package Nvoos\Core @since 2.0.0 @license MIT */
declare(strict_types=1); namespace Nvoos\Core\Tool;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface; use Nvoos\Core\Domain\Contract\HttpClientInterface; use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
class GetOpenAIFileDetailsTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e, private readonly SettingsStoreInterface $s, private readonly HttpClientInterface $h ) { parent::__construct( $e ); }
	public function getSlug(): string { return 'get_openai_file_details'; } public function getName(): string { return 'Get OpenAI File Details'; } public function getDescription(): string { return 'Retrieves details about a specific file uploaded to OpenAI.'; }
	public function getParametersSchema(): array { return array('type'=>'object','properties'=>array('file_id'=>array('type'=>'string','description'=>'OpenAI file ID.')),'required'=>array('file_id'),'additionalProperties'=>false); }
	public function getRequiredCapability(): string { return 'manage_options'; }
	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$fileId = $this->stringParam($arguments,'file_id'); if (''===$fileId) return $this->errors->validationFailed('File ID required.',array('file_id'=>array('Required.')));
		$key = $this->s->getApiKey('openai'); if (null===$key||''===$key) return $this->errors->create('missing_key','No OpenAI API key configured.');
		try { $r = $this->h->send('GET',"https://api.openai.com/v1/files/{$fileId}",array('Authorization'=>"Bearer {$key}")); $d = \json_decode($r->body,true);
			if ($r->statusCode>=400) return $this->errors->create('api_error',$d['error']['message']??'File not found.');
			return $this->success('File details retrieved.',array('id'=>$d['id']??$fileId,'filename'=>$d['filename']??'','purpose'=>$d['purpose']??'','bytes'=>$d['bytes']??0,'status'=>$d['status']??'','created_at'=>$d['created_at']??null)); }
		catch (\Throwable $e) { return $this->errors->create('request_failed',$e->getMessage()); }
	}
}
