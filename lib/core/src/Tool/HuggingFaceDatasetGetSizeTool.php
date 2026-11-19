<?php

/** @package Nvoos\Core @since 1.0.0 @license MIT
 *
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

class HuggingFaceDatasetGetSizeTool extends AbstractHuggingFaceTool
{
    /**
     * The datasets-server endpoint this tool calls.
     *
     * Public because the dataset browser derives its catalogue from it:
     * one declaration, two readers, and an endpoint change cannot leave
     * the page describing a route that no longer exists.
     */
    public const ENDPOINT = '/size';

    public function getSlug(): string
    {
        return 'huggingface_dataset_get_size';
    }
    public function getName(): string
    {
        return 'HuggingFace Dataset Get Size';
    }
    public function getDescription(): string
    {
        return 'Retrieves the row count and size information for a dataset.';
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
            return $this->success('Dataset size retrieved.', $data['size'] ?? $data);
        } catch (\Exception $e) {
            return $this->errors->create('hf_size_failed', $e->getMessage());
        }
    }
}
