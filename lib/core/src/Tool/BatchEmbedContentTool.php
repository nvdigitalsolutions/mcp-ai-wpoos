<?php
/** Batch Embed Content — prompt builder for embedding. @package Nvoos\Core @since 2.0.0 @license MIT */
declare(strict_types=1); namespace Nvoos\Core\Tool; use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
class BatchEmbedContentTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e ) { parent::__construct( $e ); }
	public function getSlug(): string { return 'batch_embed_content'; } public function getName(): string { return 'Batch Embed Content'; } public function getDescription(): string { return 'Prepares content for batch embedding generation.'; }
	public function getParametersSchema(): array { return array('type'=>'object','properties'=>array('texts'=>array('type'=>'array','description'=>'Array of text strings to embed.','items'=>array('type'=>'string'),'minItems'=>1,'maxItems'=>500)),'required'=>array('texts'),'additionalProperties'=>false); }
	public function getRequiredCapability(): string { return 'edit_posts'; }
	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$texts = $this->arrayParam($arguments,'texts'); if (array()===$texts) return $this->errors->validationFailed('Texts array required.',array('texts'=>array('Required.')));
		return $this->success('Batch embedding prepared.',array('text_count'=>\count($texts),'texts'=>$texts,'prompt'=>'Generate embeddings for the above texts using the configured embedding model.'));
	}
}
