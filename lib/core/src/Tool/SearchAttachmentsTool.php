<?php
/** @package Oos\Core @since 1.0.0 @license MIT */
declare(strict_types=1);
namespace Oos\Core\Tool;
use Oos\Core\Domain\Contract\ErrorFactoryInterface;
use Oos\Core\Domain\Contract\FileStoreInterface;
class SearchAttachmentsTool extends AbstractTool {
    public function __construct(ErrorFactoryInterface $e,private readonly FileStoreInterface $f){parent::__construct($e);}
    public function getSlug(): string { return 'search_attachments'; }
    public function getName(): string { return 'Search Attachments'; }
    public function getDescription(): string { return 'Searches media library files by filename or metadata.'; }
    public function getParametersSchema(): array { return ['type'=>'object','properties'=>['query'=>['type'=>'string','description'=>'Search by filename'],'mime_type'=>['type'=>'string','description'=>'Filter by MIME type (e.g., image/png)']],'required'=>['query'],'additionalProperties'=>false]; }
    public function getRequiredCapability(): string { return 'read'; }
    public function execute(array $arguments=[],array $context=[]): mixed {
        $query = $this->stringParam($arguments,'query'); if (''===$query) return $this->errors->validationFailed('query is required.',['query'=>['Search query is required.']]);
        $criteria = ['_wp_attached_file'=>$query]; $mime = $this->stringParam($arguments,'mime_type'); if (''!==$mime) $criteria['_wp_mime_type']=$mime;
        try {
            $files = $this->f->findByMetadata($criteria,20);
            if ([]===$files) return $this->emptyResult('No attachments found.');
            $items = array_map(fn($f)=>['id'=>$f->id,'filename'=>$f->filename,'mime_type'=>$f->mimeType,'size_bytes'=>$f->sizeBytes,'url'=>$f->publicUrl],$files);
            return $this->collection("Found ".count($items)." attachments.",$items,count($items));
        } catch (\Exception $e) { return $this->errors->create('search_attachments_failed',$e->getMessage()); }
    }
}
