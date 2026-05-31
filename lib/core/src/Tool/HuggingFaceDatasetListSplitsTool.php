<?php
/** @package Oos\Core @since 1.0.0 @license MIT */
declare(strict_types=1);
namespace Oos\Core\Tool;
class HuggingFaceDatasetListSplitsTool extends AbstractHuggingFaceTool
{
    public function getSlug(): string { return 'huggingface_dataset_list_splits'; }
    public function getName(): string { return 'HuggingFace Dataset List Splits'; }
    public function getDescription(): string { return 'Lists available splits for a HuggingFace dataset.'; }
    public function getParametersSchema(): array { return ['type'=>'object','properties'=>['dataset'=>['type'=>'string','description'=>'Dataset ID'],'config'=>['type'=>'string','description'=>'Config name','default'=>'default']],'required'=>['dataset'],'additionalProperties'=>false]; }
    public function execute(array $arguments = [], array $context = []): mixed {
        $dataset = $this->requireDataset($arguments); if ($this->errors->isError($dataset)) return $dataset;
        try {
            $data = $this->apiGet("/splits",['dataset'=>$dataset,'config'=>$this->stringParam($arguments,'config','default')]);
            if ($this->errors->isError($data)) return $data;
            $splits = $data['splits'] ?? [];
            return $this->collection("Found ".count($splits)." splits.", array_map(fn($s)=>['split'=>$s['split']??'','config'=>$s['config']??''],$splits),count($splits));
        } catch (\Exception $e) { return $this->errors->create('hf_splits_failed',$e->getMessage()); }
    }
}
