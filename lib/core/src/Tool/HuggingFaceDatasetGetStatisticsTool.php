<?php

/** @package Nvoos\Core @since 1.0.0 @license MIT
 *
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

class HuggingFaceDatasetGetStatisticsTool extends AbstractHuggingFaceTool
{
    /**
     * The datasets-server endpoint this tool calls.
     *
     * Public because the dataset browser derives its catalogue from it:
     * one declaration, two readers, and an endpoint change cannot leave
     * the page describing a route that no longer exists.
     */
    public const ENDPOINT = '/statistics';

    public function getSlug(): string
    {
        return 'huggingface_dataset_get_statistics';
    }
    public function getName(): string
    {
        return 'HuggingFace Dataset Get Statistics';
    }
    public function getDescription(): string
    {
        return 'Retrieves numeric column statistics for a dataset split.';
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
                'split'   => array(
                    'type'        => 'string',
                    'description' => 'Split name',
                    'default'     => 'train',
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
                    'split'   => $this->stringParam($arguments, 'split', 'train'),
                )
            );
            if ($this->errors->isError($data)) {
                return $data;
            }
            return $this->success('Statistics retrieved.', $data['statistics'] ?? $data);
        } catch (\Exception $e) {
            return $this->errors->create('hf_statistics_failed', $e->getMessage());
        }
    }
}
