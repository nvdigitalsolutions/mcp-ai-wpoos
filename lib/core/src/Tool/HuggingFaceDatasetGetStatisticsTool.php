<?php
/** @package Oos\Core @since 1.0.0 @license MIT */
declare(strict_types=1);
namespace Oos\Core\Tool;
class HuggingFaceDatasetGetStatisticsTool extends AbstractHuggingFaceTool
{
    public function getSlug(): string { return 'huggingface_dataset_get_statistics'; }
    public function getName(): string { return 'HuggingFace Dataset Get Statistics'; }
    public function getDescription(): string { return 'Retrieves numeric column statistics for a dataset split.'; }
    public function getParametersSchema(): array { return ['type'=>'object','properties'=>['dataset'=>['type'=>'string','description'=>'Dataset ID'],'config'=>['type'=>'string','description'=>'Config name','default'=>'default'],'split'=>['type'=>'string','description'=>'Split name','default'=>'train']],'required'=>['dataset'],'additionalProperties'=>false]; }
    public function execute(array $arguments = [], array $context = []): mixed {
        $dataset = $this->requireDataset($arguments); if ($this->errors->isError($dataset)) return $dataset;
        try {
            $data = $this->apiGet("/statistics",['dataset'=>$dataset,'config'=>$this->stringParam($arguments,'config','default'),'split'=>$this->stringParam($arguments,'split','train')]);
            if ($this->errors->isError($data)) return $data;
            return $this->success('Statistics retrieved.', $data['statistics'] ?? $data);
        } catch (\Exception $e) { return $this->errors->create('hf_statistics_failed',$e->getMessage()); }
    }
}
