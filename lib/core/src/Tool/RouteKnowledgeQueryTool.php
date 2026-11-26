<?php

/**
 * Route a knowledge question to the store most likely to answer it.
 *
 * Proposal 164 §164.50. The runtime keeps knowledge in three places and an agent
 * with all three available tends to query whichever it used last. This tool makes
 * the choice explicit: it returns an ordered plan, and when curated knowledge is
 * the primary route it also runs the OKF lookup, so the caller gets both the
 * decision and its first result set in one call.
 *
 * ## A missing bundle is a note, not a failure
 *
 * If the plan says OKF and the bundle does not exist, that is worth saying — but it
 * does not invalidate the routing decision, which is what the caller asked for. So
 * the plan comes back intact with a note explaining the empty `results`, rather
 * than an error that discards the useful half.
 *
 * ## The plan is auditable
 *
 * `signals` names the patterns that fired, so a surprising route can be traced to
 * the words that caused it instead of being taken on faith.
 *
 * @package Nvoos\Core
 * @since   2.6.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use InvalidArgumentException;
use Nvoos\Core\Application\Okf\HybridKnowledgeRouter;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\OkfBundleLocatorInterface;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;
use Nvoos\Core\Domain\Okf\OkfReader;
use Nvoos\Core\Domain\Service\Safety\CapabilityFlagVocabulary;

class RouteKnowledgeQueryTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    private const DEFAULT_BUNDLE = 'site-knowledge';

    private const MAX_TOP = 10;

    public function __construct(
        ErrorFactoryInterface $e,
        private readonly HybridKnowledgeRouter $router,
        private readonly ?OkfBundleLocatorInterface $bundles = null,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'route_knowledge_query';
    }

    public function getName(): string
    {
        return 'Knowledge — Route Query';
    }

    public function getDescription(): string
    {
        return 'Classifies a knowledge query and returns an ordered routing plan across OKF bundles (curated concepts), the vector store (semantic search) and Paper Store (structured records). When OKF is the primary route it also returns the top matching concepts. Use it to decide which store to query first.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type' => 'object',
            'properties' => array(
                'query' => array(
                    'type' => 'string',
                    'description' => 'The knowledge question to classify.',
                ),
                'bundle' => array(
                    'type' => 'string',
                    'description' => 'OKF bundle to search when OKF is part of the plan. Default: site-knowledge.',
                    'default' => self::DEFAULT_BUNDLE,
                ),
                'top' => array(
                    'type' => 'integer',
                    'description' => 'Maximum OKF results. Default: 5.',
                    'minimum' => 1,
                    'maximum' => self::MAX_TOP,
                    'default' => 5,
                ),
            ),
            'required' => array('query'),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        return 'read';
    }

    /**
     * @return string[]
     */
    public function getCapabilityFlags(): array
    {
        return CapabilityFlagVocabulary::LOCAL_READ;
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $query = trim($this->stringParam($arguments, 'query'));

        if ('' === $query) {
            return $this->errors->validationFailed(
                'A query is required.',
                array('query' => array('Give the knowledge question to classify.')),
            );
        }

        $bundle = $this->stringParam($arguments, 'bundle', self::DEFAULT_BUNDLE);
        $top = max(1, min(self::MAX_TOP, $this->intParam($arguments, 'top', 5)));

        $plan = $this->router->classify($query);

        $results = array();
        $note = '';

        if (HybridKnowledgeRouter::SOURCE_OKF === $plan['primary']) {
            $results = $this->searchOkf($bundle, $query, $top, $note);
        }

        return $this->success(
            sprintf('Routed to "%s" first (%d OKF match(es)).', $plan['primary'], count($results)),
            array(
                'query' => $query,
                'primary' => $plan['primary'],
                'plan' => $plan['sources'],
                'signals' => $plan['signals'],
                'results' => $results,
                'note' => $note,
            ),
        );
    }

    /**
     * Run the OKF half of the plan, explaining an empty result rather than hiding it.
     *
     * @return list<array<string, mixed>>
     */
    private function searchOkf(string $bundle, string $query, int $top, string &$note): array
    {
        if (null === $this->bundles) {
            $note = 'No OKF bundle store is bound on this host.';

            return array();
        }

        $root = null;

        try {
            $root = $this->bundles->rootFor($bundle);
        } catch (InvalidArgumentException $e) {
            $note = $e->getMessage();
        }

        if (null === $root) {
            if ('' === $note) {
                $note = sprintf('Bundle "%s" does not exist; the routing plan is unaffected.', $bundle);
            }

            return array();
        }

        try {
            return $this->router->search(new OkfReader($root), $query, $top);
        } catch (\Throwable $e) {
            $note = 'OKF lookup failed: ' . $e->getMessage();

            return array();
        }
    }
}
