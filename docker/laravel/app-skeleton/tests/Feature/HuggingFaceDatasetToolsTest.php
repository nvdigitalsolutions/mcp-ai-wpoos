<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Domain\Entity\HttpResponse;
use Nvoos\Core\Tool\AbstractHuggingFaceTool;
use Nvoos\Core\Tool\HuggingFaceDatasetFilterTool;
use Nvoos\Core\Tool\HuggingFaceDatasetGetInfoTool;
use Nvoos\Core\Tool\HuggingFaceDatasetGetParquetTool;
use Nvoos\Core\Tool\HuggingFaceDatasetGetRowsTool;
use Nvoos\Core\Tool\HuggingFaceDatasetGetSizeTool;
use Nvoos\Core\Tool\HuggingFaceDatasetGetStatisticsTool;
use Nvoos\Core\Tool\HuggingFaceDatasetIsValidTool;
use Nvoos\Core\Tool\HuggingFaceDatasetListSplitsTool;
use Nvoos\Core\Tool\HuggingFaceDatasetPreviewRowsTool;
use Nvoos\Core\Tool\HuggingFaceDatasetSearchTool;
use Nvoos\Core\Tool\HuggingFaceRecommendedDatasetsTool;
use Tests\TestCase;

/**
 * Evidence for the HuggingFace dataset tools — Proposal 149 §85.1 / §91.
 *
 * §85.1 marked all eleven `unavailable` because none had recorded evidence: they
 * call an external service and a gated dataset needs a token. The blocker was
 * evidence, not a renderer, so this file is the evidence — and it lives in the
 * app suite because that is the only suite CI runs.
 *
 * Per tool, pinned:
 *
 *  - the endpoint, because the manifest's claim is that these are thin wrappers
 *    over `datasets-server.huggingface.co`;
 *  - the dataset argument, because a tool that drops it would query the wrong
 *    thing and still "succeed";
 *  - the bearer header, because the token path is why they were withheld;
 *  - that a 200 is not an error envelope.
 *
 * Plus the two behaviours an evidence pass must not skip: no token means no
 * header, and an upstream failure becomes a reported error rather than an empty
 * list that reads as "no rows".
 */
class HuggingFaceDatasetToolsTest extends TestCase
{
    use RefreshDatabase;

    private const API_BASE = 'https://datasets-server.huggingface.co';

    /** @var list<array{method: string, url: string, headers: array<string, string>}> */
    private array $requests = array();

    private string $responseBody = '{}';

    private int $responseStatus = 200;

    private ?string $token = 'hf_test_token';

    protected function setUp(): void
    {
        parent::setUp();

        $this->requests = array();
        $this->responseBody = '{}';
        $this->responseStatus = 200;
        $this->token = 'hf_test_token';
    }

    /**
     * Ten tools, ten endpoints. `recommended_datasets` is not here because it
     * makes no request at all — see the last test.
     */
    public function test_every_dataset_tool_calls_its_documented_endpoint(): void
    {
        $cases = array(
            HuggingFaceDatasetFilterTool::class => '/filter',
            HuggingFaceDatasetGetInfoTool::class => '/info',
            HuggingFaceDatasetGetParquetTool::class => '/parquet',
            HuggingFaceDatasetGetRowsTool::class => '/rows',
            HuggingFaceDatasetGetSizeTool::class => '/size',
            HuggingFaceDatasetGetStatisticsTool::class => '/statistics',
            HuggingFaceDatasetIsValidTool::class => '/is-valid',
            HuggingFaceDatasetListSplitsTool::class => '/splits',
            HuggingFaceDatasetPreviewRowsTool::class => '/first-rows',
            HuggingFaceDatasetSearchTool::class => '/search',
        );

        foreach ($cases as $class => $path) {
            $this->requests = array();
            $this->responseBody = '{"rows":[],"num_rows_total":0}';

            $result = $this->tool($class)->execute($this->arguments());

            $this->assertCount(1, $this->requests, $class.' must make exactly one request.');
            $this->assertSame('GET', $this->requests[0]['method'], $class.' must read, not write.');

            $this->assertStringStartsWith(
                self::API_BASE.$path,
                $this->requests[0]['url'],
                $class.' calls the wrong endpoint.'
            );
            $this->assertStringContainsString(
                'dataset=owner%2Fname',
                $this->requests[0]['url'],
                $class.' must ask about the dataset it was given.'
            );
            $this->assertSame(
                'Bearer hf_test_token',
                $this->requests[0]['headers']['Authorization'] ?? null,
                $class.' must authenticate with the configured token.'
            );
            $this->assertFalse(
                app(ErrorFactoryInterface::class)->isError($result),
                $class.' must not report an error for a 200 response.'
            );
        }
    }

    /**
     * The token path is why these were withheld: a gated dataset needs one, and
     * an open dataset must not carry an empty header.
     */
    public function test_no_token_means_no_authorization_header(): void
    {
        $this->token = null;

        $this->tool(HuggingFaceDatasetIsValidTool::class)->execute(array('dataset' => 'open/bot'));

        $this->assertArrayNotHasKey(
            'Authorization',
            $this->requests[0]['headers'],
            'An unset token must omit the header, not send an empty bearer.'
        );
    }

    /**
     * An upstream failure must be a reported error, not an empty collection that
     * looks like "this dataset has no rows".
     */
    public function test_an_upstream_failure_is_reported_as_an_error(): void
    {
        $this->responseStatus = 500;
        $this->responseBody = 'upstream exploded';

        $result = $this->tool(HuggingFaceDatasetGetRowsTool::class)
            ->execute(array('dataset' => 'owner/name'));

        $this->assertTrue(app(ErrorFactoryInterface::class)->isError($result));
        $this->assertStringContainsString('HTTP 500', (string) $result->getMessage());
    }

    /**
     * `recommended_datasets` never touches the network — it is a static list.
     * Grouping it with the ten HTTP tools in §85.1 was a classification error,
     * and this pins the correction.
     */
    public function test_recommended_datasets_makes_no_request(): void
    {
        $result = $this->tool(HuggingFaceRecommendedDatasetsTool::class)->execute(array());

        $this->assertSame(array(), $this->requests, 'A static list must not call the API.');
        $this->assertFalse(app(ErrorFactoryInterface::class)->isError($result));
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('items', $result['data']);
        $this->assertGreaterThan(0, $result['data']['total']);
    }

    /**
     * @return array<string, mixed>
     */
    private function arguments(): array
    {
        return array(
            'dataset' => 'owner/name',
            'config' => 'default',
            'split' => 'train',
            'query' => 'hello',
            'where' => 'score > 1',
        );
    }

    /**
     * @param class-string<AbstractHuggingFaceTool> $class
     */
    private function tool(string $class): AbstractHuggingFaceTool
    {
        $settings = $this->createMock(SettingsStoreInterface::class);
        $settings->method('getApiKey')->willReturn($this->token);

        return new $class(app(ErrorFactoryInterface::class), $settings, $this->http());
    }

    private function http(): HttpClientInterface
    {
        $http = $this->createMock(HttpClientInterface::class);

        $http->method('send')->willReturnCallback(
            function (string $method, string $url, array $headers = array()): HttpResponse {
                $this->requests[] = array(
                    'method' => $method,
                    'url' => $url,
                    'headers' => $headers,
                );

                return new HttpResponse($this->responseStatus, $this->responseBody);
            }
        );

        return $http;
    }
}
