<?php
/**
 * Abstract HuggingFace tool base — shared logic for all 11 dataset tools.
 *
 * All HuggingFace dataset tools call the same API (datasets-server.huggingface.co)
 * and share the same auth pattern (optional HF token). This base eliminates
 * duplication across the 11 tools.
 *
 * @package Oos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Oos\Core\Tool;

use Oos\Core\Domain\Contract\SettingsStoreInterface;
use Psr\Http\Client\ClientInterface as HttpClientInterface;

abstract class AbstractHuggingFaceTool extends AbstractTool
{
    protected const API_BASE = 'https://datasets-server.huggingface.co';

    public function __construct(
        ErrorFactoryInterface $errors,
        protected readonly SettingsStoreInterface $settings,
        protected readonly HttpClientInterface $http,
    ) {
        parent::__construct($errors);
    }

    public function getRequiredCapability(): string { return 'read'; }

    /**
     * Build auth headers — HuggingFace optionally uses a bearer token.
     */
    protected function buildHeaders(): array
    {
        $headers = ['Accept' => 'application/json'];
        $token   = $this->settings->getApiKey('huggingface');

        if (null !== $token && '' !== $token) {
            $headers['Authorization'] = "Bearer {$token}";
        }

        return $headers;
    }

    /**
     * Make a GET request to the HuggingFace Datasets API.
     */
    protected function apiGet(string $path, array $query = []): mixed
    {
        $url = self::API_BASE . $path;
        if ([] !== $query) {
            $url .= '?' . \http_build_query($query);
        }

        $request  = new \Nyholm\Psr7\Request('GET', $url, $this->buildHeaders());
        $response = $this->http->sendRequest($request);

        if ($response->getStatusCode() >= 400) {
            return $this->errors->create(
                'hf_api_error',
                "HuggingFace API returned HTTP {$response->getStatusCode()}.",
                ['status' => $response->getStatusCode()],
            );
        }

        return \json_decode((string) $response->getBody(), true);
    }

    /**
     * Validate that a dataset name parameter is present.
     */
    protected function requireDataset(array $arguments): string
    {
        $dataset = $this->stringParam($arguments, 'dataset');
        if ('' === $dataset) {
            // Return type is mixed from requireParam but we know this path.
            $result = $this->requireParam($arguments, 'dataset');
            if ($this->errors->isError($result)) {
                return ''; // Error already handled — caller should propagate.
            }
            return (string) $result;
        }
        return $dataset;
    }
}
