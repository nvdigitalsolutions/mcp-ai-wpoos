<?php
/** @package Oos\Core @since 1.0.0 @license MIT */
declare(strict_types=1);
namespace Oos\Core\Tool;
class HuggingFaceDatasetGetParquetTool extends AbstractHuggingFaceTool
{
    public function getSlug(): string { return 'huggingface_dataset_get_parquet'; }
    public function getName(): string { return 'HuggingFace Dataset Get Parquet'; }
    public function getDescription(): string { return 'Retrieves Parquet file URLs for a dataset split.'; }
    public function getParametersSchema(): array { return ['type'=>'object','properties'=>['dataset'=>['type'=>'string','description'=>'Dataset ID'],'config'=>['type'=>'string','description'=>'Config name','default'=>'default'],'split'=>['type'=>'string','description'=>'Split name','default'=>'train']],'required'=>['dataset'],'additionalProperties'=>false]; }
    public function execute(array $arguments = [], array $context = []): mixed {
        $dataset = $this->requireDataset($arguments); if ($this->errors->isError($dataset)) return $dataset;
        try {
            $data = $this->apiGet("/parquet",['dataset'=>$dataset,'config'=>$this->stringParam($arguments,'config','default'),'split'=>$this->stringParam($arguments,'split','train')]);
            if ($this->errors->isError($data)) return $data;
            $files = $data['parquet_files'] ?? [];
            return $this->collection("Found ".count($files)." Parquet file(s).", array_map(fn($f)=>['url'=>$f['url']??'','size'=>$f['size']??0,'split'=>$f['split']??''],$files),count($files));
        } catch (\Exception $e) { return $this->errors->create('hf_parquet_failed',$e->getMessage()); }
    }
}
