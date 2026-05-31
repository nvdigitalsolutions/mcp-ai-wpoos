<?php
/**
 * Get GDACS Events — retrieves disaster/emergency events from GDACS.
 *
 * Calls the public GDACS API (no auth required). Zero WordPress dependencies.
 *
 * @package Oos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Oos\Core\Tool;

use Oos\Core\Domain\Contract\ErrorFactoryInterface;
use Psr\Http\Client\ClientInterface as HttpClientInterface;

class GetGdacsEventsTool extends AbstractTool
{
    private const API_URL = 'https://www.gdacs.org/xml/rss.json';

    public function __construct(
        ErrorFactoryInterface $errors,
        private readonly HttpClientInterface $http,
    ) {
        parent::__construct($errors);
    }

    public function getSlug(): string { return 'get_gdacs_events'; }
    public function getName(): string { return 'Get GDACS Events'; }

    public function getDescription(): string
    {
        return 'Retrieves active disaster and emergency events from the Global Disaster Alert and Coordination System (GDACS).';
    }

    public function getParametersSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'limit' => [
                    'type'        => 'integer',
                    'description' => 'Maximum number of events to return. Default: 20.',
                    'minimum'     => 1,
                    'maximum'     => 100,
                    'default'     => 20,
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function getRequiredCapability(): string { return 'read'; }

    public function execute(array $arguments = [], array $context = []): mixed
    {
        $limit = $this->intParam($arguments, 'limit', 20);

        try {
            $request  = new \Nyholm\Psr7\Request('GET', self::API_URL);
            $response = $this->http->sendRequest($request);
            $data     = \json_decode((string) $response->getBody(), true);

            if ( ! is_array($data) || ! isset($data['items'])) {
                return $this->emptyResult('No active GDACS events found.');
            }

            $events = \array_slice($data['items'], 0, $limit);

            $formatted = \array_map(function (array $e): array {
                return [
                    'title'      => $e['title'] ?? '',
                    'type'       => $e['eventtype'] ?? '',
                    'severity'   => $e['severity'] ?? '',
                    'country'    => $e['country'] ?? '',
                    'latitude'   => (float) ($e['lat'] ?? 0),
                    'longitude'  => (float) ($e['lon'] ?? 0),
                    'date'       => $e['fromdate'] ?? '',
                    'alert_level'=> $e['alertlevel'] ?? '',
                    'population' => $e['population'] ?? '',
                    'url'        => $e['link'] ?? '',
                ];
            }, $events);

            return $this->collection(
                "Retrieved " . \count($formatted) . " active GDACS events.",
                $formatted,
                \count($formatted),
            );

        } catch (\Exception $e) {
            return $this->errors->create('gdacs_failed', "GDACS request failed: {$e->getMessage()}");
        }
    }
}
