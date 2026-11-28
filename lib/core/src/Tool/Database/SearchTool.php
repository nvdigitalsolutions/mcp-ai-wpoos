<?php

/**
 * `db_search` — term search over declared columns.
 *
 * Proposal 167 phase P4, task 4.4. WordPress parity: same slug, same schema,
 * same payload including the echoed `term`.
 *
 * The wildcard characters `%` and `_` are escaped by the grammar, so a term of
 * `100%` cannot become "every row" — the bug the Laravel `ProductCatalog`
 * already documents.
 *
 * @package Nvoos\Core
 * @since   2.6.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Database;

final class SearchTool extends DatabaseTool
{
    public function getSlug(): string
    {
        return 'db_search';
    }

    public function getName(): string
    {
        return 'Search Database Rows';
    }

    public function getDescription(): string
    {
        return 'Search for a term across the relation\'s searchable text columns. Wildcards in the term are treated literally. Use db_select for structured filters.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array_merge(
                $this->connectionProperties(),
                array(
                    'relation' => $this->relationSchema(),
                    'term'     => array(
                        'type'        => 'string',
                        'minLength'   => 1,
                        'maxLength'   => 200,
                        'description' => 'Text to match. Treated as a literal substring, not a pattern.',
                    ),
                    'columns'  => array(
                        'type'        => 'array',
                        'description' => 'Columns to search. Defaults to the scope\'s declared searchable columns.',
                        'maxItems'    => 8,
                        'items'       => array('type' => 'string'),
                    ),
                    'limit'    => array(
                        'type'    => 'integer',
                        'minimum' => 1,
                    ),
                ),
            ),
            'required'             => array('relation', 'term'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        return $this->guard(function () use ($arguments, $context): array {
            $connection = $this->connection($arguments, $context);
            $scope      = $this->scope($arguments);
            $query      = $this->searchQuery($arguments);
            $result     = $this->databases->search($connection, $scope, $query);

            return $this->envelope(
                $result,
                array(
                    'connection' => $connection,
                    'relation'   => $query->relation,
                    'term'       => $query->term,
                ),
            );
        });
    }
}
