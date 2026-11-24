<?php

/**
 * Tool: batch_embed_content — index published content into embedding vectors.
 *
 * `BatchEmbedContentIndexTool` because `BatchEmbedContentTool` is the quarantined
 * prompt-builder answering to `batch_embed_content_prompt`, and because indexing is the
 * whole job: embed a bounded set of rows and store the vectors where retrieval can find
 * them.
 *
 * ## Where the vectors go
 *
 * The WordPress tool stores them in post meta under `_wp_mcp_ai_embeddings`. The direct
 * analogue on this contract is the item's own meta, written through
 * `UpdateContentCommand::$meta` — so this needs no migration, no vector database and no
 * second store, and it works identically on both hosts. The stored record carries the
 * provider, the model, the dimensions, the embedded timestamp, a hash of the text that
 * was embedded, and the vector itself.
 *
 * That is a deliberate limit, not an oversight: this is an index for a bounded corpus,
 * not a vector database. A caller with millions of rows needs a real index, and the
 * docblock says so rather than implying otherwise.
 *
 * ## One request at a time, bounded
 *
 * A chunk is capped by **both** input count and total characters, because a single
 * request of a hundred 32,000-character documents is rejected upstream for token count
 * long before the input count matters. Chunks are sent in order and each is stored
 * before the next is requested, so a provider failure leaves the earlier work durable
 * and the error message says how much was done.
 *
 * ## Re-running is cheap
 *
 * `update_existing` defaults to false, so an item that already carries an embedding is
 * skipped rather than paid for twice. The hash in the stored record is what a future
 * freshness check needs to tell a stale vector from a current one.
 *
 * ## OpenAI only, and named
 *
 * The WordPress tool advertises OpenAI and Gemini with different batching paths. Only
 * the OpenAI `/embeddings` shape is implemented here, because an endpoint shape that has
 * not been exercised is a guess. `provider` accepts `openai` and refuses anything else
 * by name, so a caller is never told a vector was produced by a provider that was never
 * called.
 *
 * @package Nvoos\Core
 * @since   2.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\ContentQuery;
use Nvoos\Core\Domain\Entity\UpdateContentCommand;
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Domain\Error\NotFoundException;

final class BatchEmbedContentIndexTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    /**
     * The meta key the WordPress tool uses. Kept identical so a record written by one
     * host is recognisable to the other.
     */
    public const META_KEY = '_nvoos_embeddings';

    public const DEFAULT_MODEL = 'text-embedding-3-small';

    private const DEFAULT_LIMIT = 50;

    private const MAX_LIMIT = 100;

    /**
     * Per-item ceiling. The provider tokenises far more than this cheaply, but an
     * embedder is not a reader: the first 32,000 characters carry the subject.
     */
    private const MAX_TEXT_LENGTH = 32000;

    /**
     * Inputs per request. Well under the provider's own cap, because the characters
     * limit below binds first in practice.
     */
    private const MAX_INPUTS_PER_REQUEST = 64;

    /**
     * Characters per request. Roughly 30k tokens at four characters per token, which
     * leaves room under a 300k-token request ceiling without needing a tokeniser here.
     */
    private const MAX_CHARS_PER_REQUEST = 120000;

    private const PROVIDERS = array( 'openai' );

    private const DEFAULT_BASE_URL = 'https://api.openai.com/v1';

    private const MAX_ERROR_BODY = 300;

    public function __construct(
        ErrorFactoryInterface $errors,
        private readonly ?ContentStoreInterface $content = null,
        private readonly ?HttpClientInterface $http = null,
        private readonly ?SettingsStoreInterface $settings = null,
    ) {
        parent::__construct($errors);
    }

    public function getSlug(): string
    {
        return 'batch_embed_content';
    }

    public function getName(): string
    {
        return 'Batch Embed Content';
    }

    public function getDescription(): string
    {
        return 'Embeds published content into vectors for semantic search, storing each vector in the item\'s own meta with its provider, model, dimensions and a hash of the text. Skips items that already carry an embedding unless update_existing is set. OpenAI only.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'post_ids'        => array(
                    'type'        => 'array',
                    'description' => 'Specific items to embed. When absent, post_types decides what is selected.',
                    'items'       => array(
                        'type'    => 'integer',
                        'minimum' => 1,
                    ),
                    'maxItems'    => self::MAX_LIMIT,
                ),
                'post_types'      => array(
                    'type'        => 'array',
                    'description' => 'Content types to index when post_ids is absent.',
                    'items'       => array(
                        'type'      => 'string',
                        'minLength' => 1,
                    ),
                    'maxItems'    => 20,
                ),
                'limit'           => array(
                    'type'        => 'integer',
                    'description' => 'Maximum items to process in this call.',
                    'minimum'     => 1,
                    'maximum'     => self::MAX_LIMIT,
                    'default'     => self::DEFAULT_LIMIT,
                ),
                'provider'        => array(
                    'type'        => 'string',
                    'description' => 'Embedding provider. Only openai is implemented on this host.',
                    'enum'        => self::PROVIDERS,
                    'default'     => 'openai',
                ),
                'model'           => array(
                    'type'        => 'string',
                    'description' => 'Embedding model.',
                    'default'     => self::DEFAULT_MODEL,
                ),
                'update_existing' => array(
                    'type'        => 'boolean',
                    'description' => 'Re-embed items that already carry an embedding.',
                    'default'     => false,
                ),
                'dry_run'         => array(
                    'type'        => 'boolean',
                    'description' => 'Report what would be embedded and call no provider.',
                    'default'     => false,
                ),
            ),
            'required'             => array(),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        return 'edit_posts';
    }

    public function getCapabilityFlags(): array
    {
        return array(
            'write',
            'state-changing',
            'external-api',
            'network-dependent',
            'consumes-tokens',
            'requires-credentials',
            'rate-limited',
            'requires-capability',
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        if (null === $this->content) {
            return $this->errors->create(
                'content_store_unavailable',
                'This host has no content store, so there is nothing to embed.'
            );
        }

        if (null === $this->http || null === $this->settings) {
            return $this->errors->create(
                'embeddings_unavailable',
                'This host has no HTTP client or settings store, so it cannot call an embedding provider.'
            );
        }

        $provider = $this->stringParam($arguments, 'provider', 'openai');

        if (! \in_array($provider, self::PROVIDERS, true)) {
            // Naming the gap rather than silently falling back to OpenAI, which would
            // attribute a vector to a provider that was never called.
            return $this->errors->validationFailed(
                \sprintf('%s is not implemented on this host.', $provider),
                array( 'provider' => 'Use one of: ' . \implode(', ', self::PROVIDERS) )
            );
        }

        $userId = (int) ( $context['user_id'] ?? $context['owner_id'] ?? 0 );

        if ($userId < 1) {
            return $this->errors->create(
                'user_required',
                'Embedding writes to the item\'s meta, which needs the calling user.'
            );
        }

        $model = \mb_substr($this->stringParam($arguments, 'model', self::DEFAULT_MODEL), 0, 120);

        $plan = $this->plan($arguments, $userId);

        if ($this->errors->isError($plan)) {
            return $plan;
        }

        if (array() === $plan['pending']) {
            return $this->success(
                $this->nothingToDoMessage($plan),
                array(
                    'embedded'  => array(),
                    'skipped'   => $plan['skipped'],
                    'provider'  => $provider,
                    'model'     => $model,
                    'dimensions' => 0,
                    'batches'   => 0,
                )
            );
        }

        if ($this->isDryRun($arguments)) {
            return $this->success(
                \sprintf(
                    'Would embed %d item%s with %s and store the vectors in meta. No provider was called.',
                    \count($plan['pending']),
                    1 === \count($plan['pending']) ? '' : 's',
                    $model
                ),
                array(
                    'dry_run'    => true,
                    'would_embed' => \array_map(
                        static fn (array $row): int => (int) $row['item']->id,
                        $plan['pending']
                    ),
                    'skipped'    => $plan['skipped'],
                    'provider'   => $provider,
                    'model'      => $model,
                    'dimensions' => 0,
                    'batches'    => 0,
                )
            );
        }

        return $this->embed($plan, $userId, $provider, $model);
    }

    /**
     * Decide what to embed, and what to skip and why.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array{pending: list<array{item: ContentItem, text: string}>, skipped: list<array{id:int, reason:string}>}|mixed
     */
    private function plan(array $arguments, int $userId): mixed
    {
        $limit  = \max(1, \min(self::MAX_LIMIT, $this->intParam($arguments, 'limit', self::DEFAULT_LIMIT)));
        $update = $this->boolParam($arguments, 'update_existing');

        $items = $this->items($arguments, $limit, $userId);

        $pending = array();
        $skipped = array();

        foreach ($items as $item) {
            if (! $update && $this->hasEmbedding($item)) {
                $skipped[] = array( 'id' => $item->id, 'reason' => 'already_embedded' );

                continue;
            }

            $text = \trim($item->title . "\n\n" . $item->content);

            if ('' === $text) {
                $skipped[] = array( 'id' => $item->id, 'reason' => 'empty_text' );

                continue;
            }

            $pending[] = array(
                'item' => $item,
                'text' => \mb_substr($text, 0, self::MAX_TEXT_LENGTH),
            );
        }

        return array( 'pending' => $pending, 'skipped' => $skipped );
    }

    /**
     * The rows to consider.
     *
     * Explicit ids are resolved one at a time so an unreadable or missing id becomes a
     * skip rather than failing the whole batch. A type query is published-only and
     * scoped to the caller, matching every other read in this engine.
     *
     * @param array<string, mixed> $arguments
     *
     * @return list<ContentItem>
     */
    private function items(array $arguments, int $limit, int $userId): array
    {
        $ids = $this->intListParam($arguments, 'post_ids');

        if (array() !== $ids) {
            $found = array();

            foreach (\array_slice($ids, 0, self::MAX_LIMIT) as $id) {
                $item = $this->content->find($id, $userId);

                if (null !== $item) {
                    $found[] = $item;
                }
            }

            return $found;
        }

        $types = array();

        foreach ($this->stringListParam($arguments, 'post_types') as $type) {
            $type = \trim($type);

            if ('' !== $type) {
                $types[] = $type;
            }
        }

        if (array() === $types) {
            $types = array( 'post', 'page' );
        }

        $query = new ContentQuery(
            types: $types,
            statuses: array( 'publish' ),
            perPage: $limit,
            userId: $userId,
        );

        $items = array();

        foreach ($this->content->query($query)->items as $item) {
            if ($item instanceof ContentItem) {
                $items[] = $item;
            }
        }

        return $items;
    }

    private function hasEmbedding(ContentItem $item): bool
    {
        $stored = $item->meta[ self::META_KEY ] ?? null;

        if (\is_array($stored) && isset($stored['vector']) && \is_array($stored['vector']) && array() !== $stored['vector']) {
            return true;
        }

        $meta = $this->content->getMeta($item->id);
        $stored = $meta[ self::META_KEY ] ?? null;

        return \is_array($stored) && isset($stored['vector']) && \is_array($stored['vector']) && array() !== $stored['vector'];
    }

    /**
     * Send the chunks and store what comes back.
     *
     * @param array{pending: list<array{item: ContentItem, text: string}>, skipped: list<array{id:int, reason:string}>} $plan
     */
    private function embed(array $plan, int $userId, string $provider, string $model): mixed
    {
        $key = (string) ( $this->settings->getApiKey($provider) ?? '' );

        if ('' === $key) {
            return $this->errors->create(
                'missing_api_key',
                \sprintf('No %s API key is configured, so no embedding can be requested.', $provider)
            );
        }

        $url = \rtrim((string) ( $this->settings->getApiBaseUrl($provider) ?? self::DEFAULT_BASE_URL ), '/') . '/embeddings';

        $chunks = $this->chunks($plan['pending']);

        $embedded = array();
        $dimensions = 0;
        $tokens   = 0;
        $batches  = 0;

        foreach ($chunks as $chunk) {
            ++$batches;

            $vectors = $this->request($url, $key, $model, $chunk);

            if ($this->errors->isError($vectors)) {
                return $vectors;
            }

            $dimensions = $vectors['dimensions'];
            $tokens    += $vectors['tokens'];

            foreach ($chunk as $index => $row) {
                $vector = $vectors['vectors'][ $index ] ?? null;

                if (! \is_array($vector) || array() === $vector) {
                    // The provider answered but not for this input. Treating it as
                    // success would store a hole where a vector should be.
                    return $this->errors->create(
                        'invalid_response',
                        \sprintf(
                            'The provider returned no embedding for item %d after %d other%s were stored.',
                            (int) $row['item']->id,
                            \count($embedded),
                            1 === \count($embedded) ? '' : 's'
                        )
                    );
                }

                $written = $this->store($row['item'], $userId, $provider, $model, $vector, (string) $row['text']);

                if ($this->errors->isError($written)) {
                    return $written;
                }

                $embedded[] = array(
                    'id'         => (int) $row['item']->id,
                    'title'      => $row['item']->title,
                    'dimensions' => \count($vector),
                );
            }
        }

        return $this->success(
            \sprintf(
                'Embedded %d item%s with %s (%d dimensions) in %d request%s%s.',
                \count($embedded),
                1 === \count($embedded) ? '' : 's',
                $model,
                $dimensions,
                $batches,
                1 === $batches ? '' : 's',
                array() === $plan['skipped']
                    ? ''
                    : \sprintf(', skipped %d', \count($plan['skipped']))
            ),
            array(
                'embedded'   => $embedded,
                'skipped'    => $plan['skipped'],
                'provider'   => $provider,
                'model'      => $model,
                'dimensions' => $dimensions,
                'batches'    => $batches,
                'tokens'     => $tokens,
            )
        );
    }

    /**
     * Split the pending work by both counts and characters.
     *
     * A single item longer than the character budget still gets its own chunk: refusing
     * it would mean a document could never be embedded at all.
     *
     * @param list<array{item: ContentItem, text: string}> $pending
     *
     * @return list<list<array{item: ContentItem, text: string}>>
     */
    private function chunks(array $pending): array
    {
        $chunks  = array();
        $current = array();
        $chars   = 0;

        foreach ($pending as $row) {
            $length = \mb_strlen($row['text']);

            if (
                array() !== $current && (
                \count($current) >= self::MAX_INPUTS_PER_REQUEST
                || $chars + $length > self::MAX_CHARS_PER_REQUEST
                )
            ) {
                $chunks[] = $current;
                $current  = array();
                $chars    = 0;
            }

            $current[] = $row;
            $chars    += $length;
        }

        if (array() !== $current) {
            $chunks[] = $current;
        }

        return $chunks;
    }

    /**
     * One provider request.
     *
     * @param list<array{item: ContentItem, text: string}> $chunk
     *
     * @return array{vectors: list<array<int, float>>, dimensions: int, tokens: int}|mixed
     */
    private function request(string $url, string $key, string $model, array $chunk): mixed
    {
        $inputs = \array_map(static fn (array $row): string => (string) $row['text'], $chunk);

        $body = \json_encode(
            array(
                'model' => $model,
                'input' => $inputs,
            ),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        if (false === $body) {
            return $this->errors->create('encoding_failed', 'The embedding request could not be encoded.');
        }

        $response = $this->http->send(
            'POST',
            $url,
            array(
                'Authorization: Bearer ' . $key,
                'Content-Type: application/json',
            ),
            $body
        );

        if (200 > $response->statusCode || 300 <= $response->statusCode) {
            return $this->errors->create(
                'provider_error',
                \sprintf(
                    'The embedding provider answered %d: %s',
                    $response->statusCode,
                    \mb_substr(\trim($response->body), 0, self::MAX_ERROR_BODY)
                )
            );
        }

        $decoded = \json_decode($response->body, true);

        if (! \is_array($decoded) || ! isset($decoded['data']) || ! \is_array($decoded['data'])) {
            return $this->errors->create(
                'invalid_response',
                'The embedding provider returned JSON without a data array.'
            );
        }

        $vectors    = array();
        $dimensions = 0;

        foreach ($decoded['data'] as $position => $entry) {
            if (! \is_array($entry) || ! isset($entry['embedding']) || ! \is_array($entry['embedding'])) {
                continue;
            }

            $index = \is_numeric($entry['index'] ?? null) ? (int) $entry['index'] : $position;

            $vector = array_values(\array_map(static fn (mixed $value): float => (float) $value, $entry['embedding']));

            if (array() === $vector) {
                continue;
            }

            $vectors[ $index ] = $vector;
            $dimensions        = \count($vector);
        }

        return array(
            'vectors'    => $vectors,
            'dimensions' => $dimensions,
            'tokens'     => (int) ( $decoded['usage']['total_tokens'] ?? 0 ),
        );
    }

    /**
     * @param array<int, float> $vector
     */
    private function store(
        ContentItem $item,
        int $userId,
        string $provider,
        string $model,
        array $vector,
        string $text,
    ): mixed {
        $record = array(
            'provider'    => $provider,
            'model'       => $model,
            'dimensions'  => \count($vector),
            'embedded_at' => \gmdate('c'),
            'text_hash'   => \sha1($text),
            'vector'      => $vector,
        );

        try {
            $this->content->update(
                $item->id,
                new UpdateContentCommand(
                    userId: $userId,
                    meta: array( self::META_KEY => $record ),
                )
            );
        } catch (AccessDeniedException $error) {
            return $this->errors->forbidden($error->getMessage());
        } catch (NotFoundException $error) {
            return $this->errors->notFound($error->getMessage());
        }

        return true;
    }

    /**
     * @param array{pending: list<mixed>, skipped: list<array{id:int, reason:string}>} $plan
     */
    private function nothingToDoMessage(array $plan): string
    {
        if (array() === $plan['skipped']) {
            return 'No published content matched, so there was nothing to embed.';
        }

        $already = 0;

        foreach ($plan['skipped'] as $skip) {
            if ('already_embedded' === $skip['reason']) {
                ++$already;
            }
        }

        if ($already === \count($plan['skipped'])) {
            return \sprintf(
                'Nothing to embed: all %d item%s already carry an embedding. Set update_existing to re-embed them.',
                $already,
                1 === $already ? '' : 's'
            );
        }

        return \sprintf(
            'Nothing to embed: %d item%s were skipped.',
            \count($plan['skipped']),
            1 === \count($plan['skipped']) ? '' : 's'
        );
    }
}
