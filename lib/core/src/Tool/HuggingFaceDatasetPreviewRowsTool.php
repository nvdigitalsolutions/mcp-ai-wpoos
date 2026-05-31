<?php
/** @package Oos\Core @since 1.0.0 @license MIT */
declare(strict_types=1);
namespace Oos\Core\Tool;
class HuggingFaceDatasetPreviewRowsTool extends AbstractHuggingFaceTool
{
    public function getSlug(): string { return 'huggingface_dataset_preview_rows'; }
    public function getName(): string { return 'HuggingFace Dataset Preview Rows'; }
    public function getDescription(): string { return 'Retrieves the first N rows of a dataset split for preview.'; }
    public function getParametersSchema(): array { return ['type'=>'object','properties'=>['dataset'=>['type'=>'string','description'=>'Dataset ID'],'config'=>['type'=>'string','description'=>'Config name','default'=>'default'],'split'=>['type'=>'string','description'=>'Split name','default'=>'train'],'limit'=>['type'=>'integer','description'=>'Number of preview rows','default'=>10]],'required'=>['dataset'],'additionalProperties'=>false]; }
    public function execute(array $arguments = [], array $context = []): mixed {
        $dataset = $this->requireDataset($arguments); if ($this->errors->isError($dataset)) return $dataset;
        try {
            $data = $this->apiGet("/first-rows",['dataset'=>$dataset,'config'=>$this->stringParam($arguments,'config','default'),'split'=>$this->stringParam($arguments,'split','train')]);
            if ($this->errors->isError($data)) return $data;
            $rows = array_slice($data['rows'] ?? [], 0, $this->intParam($arguments,'limit',10));
            return $this->collection("Preview of ".count($rows)." rows.", $rows, $data['num_rows_total'] ?? count($rows));
        } catch (\Exception $e) { return $this->errors->create('hf_preview_failed',$e->getMessage()); }
    }
}
