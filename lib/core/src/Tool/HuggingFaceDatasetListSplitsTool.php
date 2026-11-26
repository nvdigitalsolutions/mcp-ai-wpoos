<?php

/** @package Nvoos\Core @since 1.0.0 @license MIT
 *
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

class HuggingFaceDatasetListSplitsTool extends AbstractHuggingFaceTool
{
    /**
     * The datasets-server endpoint this tool calls.
     *
     * Public because the dataset browser derives its catalogue from it:
     * one declaration, two readers, and an endpoint change cannot leave
     * the page describing a route that no longer exists.
     */
    public const ENDPOINT = '/splits';

    public function getSlug(): string
    {
        return 'huggingface_dataset_list_splits';
    }
    public function getName(): string
    {
        return 'HuggingFace Dataset List Splits';
    }
    public function getDescription(): string
    {
        return 'Lists available splits for a HuggingFace dataset.';
    }
    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'dataset' => array(
                    'type'        => 'string',
                    'description' => 'Dataset ID',
                ),
                'config'  => array(
                    'type'        => 'string',
                    'description' => 'Config name',
                    'default'     => 'default',
                ),
            ),
            'required'             => array( 'dataset' ),
            'additionalProperties' => false,
        );
    }
    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $dataset = $this->requireDataset($arguments);
        if ($this->errors->isError($dataset)) {
            return $dataset;
        }
        try {
            $data = $this->apiGet(
                self::ENDPOINT,
                array(
                    'dataset' => $dataset,
                    'config'  => $this->stringParam($arguments, 'config', 'default'),
                )
            );
            if ($this->errors->isError($data)) {
                return $data;
            }
            $splits = $data['splits'] ?? array();
            return $this->collection(
                'Found ' . count($splits) . ' splits.',
                array_map(
                    fn($s)=>array(
                        'split'  => $s['split'] ?? '',
                        'config' => $s['config'] ?? '',
                    ),
                    $splits
                ),
                count($splits)
            );
        } catch (\Exception $e) {
            return $this->errors->create('hf_splits_failed', $e->getMessage());
        }
    }
}
