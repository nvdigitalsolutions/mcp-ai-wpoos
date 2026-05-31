<?php
/**
 * ReliefWeb Reports — retrieves humanitarian reports from ReliefWeb.
 *
 * Calls the public ReliefWeb API (no auth required). Zero WordPress dependencies.
 *
 * @package Oos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Oos\Core\Tool;

use Oos\Core\Domain\Contract\ErrorFactoryInterface;
use Psr\Http\Client\ClientInterface as HttpClientInterface;

class ReliefwebReportsTool extends AbstractTool
{
    private const API_URL = 'https://api.reliefweb.int/v1/reports';

    public function __construct(
        ErrorFactoryInterface $errors,
        private readonly HttpClientInterface $http,
    ) {
        parent::__construct($errors);
    }

    public function getSlug(): string { return 'reliefweb_reports'; }
    public function getName(): string { return 'ReliefWeb Reports'; }

    public function getDescription(): string
    {
        return 'Retrieves humanitarian reports from ReliefWeb, the UN OCHA information service.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'query' => [
                    'type'        => 'string',
                    'description' => 'Search query for reports.',
                ],
                'country' => [
                    'type'        => 'string',
                    'description' => 'Filter by country (ISO code or name).',
                ],
                'limit' => [
                    'type'        => 'integer',
                    'description' => 'Max results (1-50). Default: 10.',
                    'minimum'     => 1,
                    'maximum'     => 50,
                    'default'     => 10,
                ],
            ],
            'required'             => ['query'],
            'additionalProperties' => false,
        ];
    }

    public function getRequiredCapability(): string { return 'read'; }

    public function execute(array $arguments = [], array $context = []): mixed
    {
        $query   = $this->stringParam($arguments, 'query');
        $country = $this->stringParam($arguments, 'country');
        $limit   = $this->intParam($arguments, 'limit', 10);

        $filter = ['field' => 'title', 'value' => $query];

        if ('' !== $country) {
            $filter = [
                'operator' => 'AND',
                'conditions' => [
                    $filter,
                    ['field' => 'country.name', 'value' => $country],
                ],
            ];
        }

        $body = \json_encode([
            'appname' => 'o-os-core',
            'query'   => ['value' => ''],
            'filter'  => ['conditions' => [$filter]],
            'limit'   => $limit,
            'sort'    => ['date.created:desc'],
            'fields'  => ['include' => ['title', 'body', 'date', 'source', 'url', 'country', 'format']],
        ]);

        try {
            $request = new \Nyholm\Psr7\Request(
                'POST',
                self::API_URL,
                ['Content-Type' => 'application/json'],
                $body,
            );
            $response = $this->http->sendRequest($request);
            $data     = \json_decode((string) $response->getBody(), true);

            if ( ! is_array($data) || ! isset($data['data'])) {
                return $this->emptyResult('No ReliefWeb reports found.');
            }

            $reports = \array_map(function (array $r): array {
                $fields = $r['fields'] ?? [];
                return [
                    'title'   => $fields['title'] ?? '',
                    'date'    => $fields['date']['created'] ?? '',
                    'source'  => $fields['source'][0]['name'] ?? '',
                    'country' => \implode(', ', \array_column($fields['country'] ?? [], 'name')),
                    'url'     => $fields['url'] ?? $r['href'] ?? '',
                    'format'  => $fields['format'][0]['name'] ?? '',
                    'summary' => \mb_substr(\strip_tags((string) ($fields['body'] ?? '')), 0, 500),
                ];
            }, $data['data']);

            return $this->collection(
                "Found " . \count($reports) . " ReliefWeb reports.",
                $reports,
                (int) ($data['totalCount'] ?? \count($reports)),
            );

        } catch (\Exception $e) {
            return $this->errors->create('reliefweb_failed', "ReliefWeb request failed: {$e->getMessage()}");
        }
    }
}
