<?php
/**
 * Wikidata remote source driver.
 *
 * Reconciliation-only driver that matches local knowledge-graph nodes
 * to Wikidata entities via the wbsearchentities API.
 *
 * @since   1.0.0
 * @package NvoosGraphify
 */

declare(strict_types=1);

namespace NvoosGraphify\Remote\Drivers;

use NvoosGraphify\Contracts\RemoteSourceDriver;
use NvoosGraphify\Remote\HttpClient;

/**
 * Wikidata entity reconciliation driver.
 *
 * @since 1.0.0
 */
final class Wikidata implements RemoteSourceDriver
{
    /**
     * Wikidata API base URL.
     *
     * @var string
     */
    public const API_URL = 'https://www.wikidata.org/w/api.php';

    /**
     * Driver configuration.
     *
     * @var array<string,mixed>
     */
    private array $config = array();

    /**
     * HTTP client instance.
     *
     * @var HttpClient
     */
    private HttpClient $http;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->http = new HttpClient('wikidata');
    }

    /** {@inheritdoc} */
    public function getDriverId(): string
    {
        return 'wikidata';
    }

    /** {@inheritdoc} */
    public function getDriverLabel(): string
    {
        return __('Wikidata (Entity Reconciliation)', 'nvoos-graphify');
    }

    /** {@inheritdoc} */
    public function setConfig(array $config): void
    {
        $this->config = $config;
        $slug         = $config['_slug'] ?? 'wikidata';
        $this->http   = new HttpClient($slug);
    }

    /** {@inheritdoc} */
    public function getConfig(): array
    {
        return $this->config;
    }

    /** {@inheritdoc} */
    public function getCapabilities(): array
    {
        return array('reconcile');
    }

    /** {@inheritdoc} */
    public function getConfigSchema(): array
    {
        return array(
            'language'       => array(
                'type'        => 'text',
                'label'       => __('Language', 'nvoos-graphify'),
                'description' => __('BCP 47 language code (e.g. en, de, fr).', 'nvoos-graphify'),
                'default'     => 'en',
            ),
            'min_confidence' => array(
                'type'        => 'number',
                'label'       => __('Min Confidence', 'nvoos-graphify'),
                'description' => __('Minimum match confidence threshold (0.0–1.0).', 'nvoos-graphify'),
                'default'     => 0.6,
            ),
        );
    }

    /** {@inheritdoc} */
    public function testConnection(): array
    {
        $url    = add_query_arg(
            array(
                'action'   => 'wbsearchentities',
                'search'   => 'WordPress',
                'language' => 'en',
                'limit'    => 1,
                'format'   => 'json',
            ),
            self::API_URL
        );
        $result = $this->http->get($url);

        if (is_wp_error($result)) {
            return array(
                'success' => false,
                'message' => $result->get_error_message(),
            );
        }

        $data = json_decode($result['body'], true);
        if (empty($data['search'])) {
            return array(
                'success' => false,
                'message' => __('Empty results from Wikidata.', 'nvoos-graphify'),
            );
        }

        return array(
            'success' => true,
            'message' => __('Connected to Wikidata.', 'nvoos-graphify'),
        );
    }

    /** {@inheritdoc} */
    public function discover(): array
    {
        return array(
            'driver'       => $this->getDriverId(),
            'label'        => $this->getDriverLabel(),
            'capabilities' => $this->getCapabilities(),
            'endpoint'     => self::API_URL,
        );
    }

    /** {@inheritdoc} */
    public function fetchNodes(array $args = array()): ?array
    {
        return array();
    }

    /** {@inheritdoc} */
    public function fetchEdges(array $args = array()): array
    {
        return array();
    }

    /** {@inheritdoc} */
    public function reconcile(object $localNode): array
    {
        $label = sanitize_text_field($localNode->label ?? '');
        if (empty($label)) {
            return array(
                'external_id' => '',
                'confidence'  => 0.0,
                'matched'     => false,
            );
        }

        $url    = add_query_arg(
            array(
                'action'   => 'wbsearchentities',
                'search'   => rawurlencode($label),
                'language' => 'en',
                'limit'    => 5,
                'format'   => 'json',
                'type'     => 'item',
            ),
            self::API_URL
        );
        $result = $this->http->get($url);

        if (is_wp_error($result)) {
            return array(
                'external_id' => '',
                'confidence'  => 0.0,
                'matched'     => false,
            );
        }

        $data = json_decode($result['body'], true);
        if (empty($data['search']) || ! is_array($data['search'])) {
            return array(
                'external_id' => '',
                'confidence'  => 0.0,
                'matched'     => false,
            );
        }

        $nodeType = $localNode->type ?? '';

        $bestMatch      = null;
        $bestConfidence = 0.0;

        foreach ($data['search'] as $item) {
            $wdLabel       = $item['label'] ?? '';
            $wdDescription = $item['description'] ?? '';
            $qid           = $item['id'] ?? '';

            if (empty($qid)) {
                continue;
            }

            $confidence = $this->calculateConfidence($label, $wdLabel, $wdDescription, $nodeType);

            if ($confidence > $bestConfidence) {
                $bestConfidence = $confidence;
                $bestMatch      = $item;
            }
        }

        if (null === $bestMatch || $bestConfidence < 0.6) {
            return array(
                'external_id' => '',
                'confidence'  => $bestConfidence,
                'matched'     => false,
            );
        }

        $qid = $bestMatch['id'];
        return array(
            'external_id'  => $qid,
            'confidence'   => $bestConfidence,
            'matched'      => true,
            'wikidata_url' => 'https://www.wikidata.org/wiki/' . rawurlencode($qid),
            'label'        => $bestMatch['label'] ?? '',
            'description'  => $bestMatch['description'] ?? '',
        );
    }

    /**
     * Calculate confidence score for a Wikidata match.
     *
     * @since 1.0.0
     * @param string $localLabel   Local node label.
     * @param string $remoteLabel  Wikidata entity label.
     * @param string $description  Wikidata entity description.
     * @param string $nodeType     Local node type.
     * @return float Confidence score 0.0–1.0.
     */
    private function calculateConfidence(string $localLabel, string $remoteLabel, string $description, string $nodeType): float
    {
        $localLower  = strtolower(trim($localLabel));
        $remoteLower = strtolower(trim($remoteLabel));

        // Base confidence from label similarity.
        if ($localLower === $remoteLower) {
            $confidence = 1.0;
        } elseif (0 === strpos($remoteLower, $localLower) || 0 === strpos($localLower, $remoteLower)) {
            $confidence = 0.85;
        } elseif (false !== strpos($remoteLower, $localLower) || false !== strpos($localLower, $remoteLower)) {
            $confidence = 0.7;
        } else {
            // Try Levenshtein distance for close matches.
            $distance = levenshtein($localLower, $remoteLower);
            $maxLen   = max(strlen($localLower), strlen($remoteLower));
            if ($maxLen > 0) {
                $similarity = 1.0 - ($distance / $maxLen);
                $confidence = max(0.0, $similarity * 0.7);
            } else {
                $confidence = 0.0;
            }
        }

        // Reduce if type doesn't match based on description.
        if ($confidence > 0.0 && ! empty($nodeType) && ! empty($description)) {
            $typeKeywords = $this->getTypeKeywords($nodeType);
            $descLower    = strtolower($description);
            $typeMatched  = false;
            foreach ($typeKeywords as $kw) {
                if (false !== strpos($descLower, $kw)) {
                    $typeMatched = true;
                    break;
                }
            }
            if (! empty($typeKeywords) && ! $typeMatched) {
                $confidence = max(0.0, $confidence - 0.1);
            }
        }

        return round($confidence, 4);
    }

    /**
     * Return keywords associated with a node type for description matching.
     *
     * @since 1.0.0
     * @param string $type Node type.
     * @return string[]
     */
    private function getTypeKeywords(string $type): array
    {
        $map = array(
            'person'       => array('human', 'person', 'born', 'researcher', 'author', 'politician', 'actor', 'musician'),
            'organization' => array('organization', 'company', 'corporation', 'institution', 'university', 'association'),
            'place'        => array('city', 'country', 'town', 'region', 'location', 'place', 'river', 'mountain'),
            'concept'      => array('concept', 'theory', 'idea', 'method', 'approach', 'field', 'discipline'),
            'entity'       => array(),
            'topic'        => array(),
        );
        return $map[$type] ?? array();
    }
}
