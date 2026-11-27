<?php

/**
 * What a catalog migration did, item by item.
 *
 * Proposal 164 section 164.111. A migration that reports only "1,204 products
 * migrated" is unusable: the operator needs to know which three failed and why,
 * and whether a second run would duplicate work. So every processed product
 * lands in `entries` with an action — `created`, `updated`, `skipped`,
 * `warning`, `failed` — and the counters are derived from the same pass.
 *
 * `dryRun` is carried on the report so a caller cannot mistake a rehearsal for a
 * write. A dry run computes exactly the same entries; it simply performs no save.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Entity;

final readonly class CommerceMigrationReport implements \JsonSerializable
{
    /**
     * @param int $created Products inserted in the target.
     * @param int $updated Products that already existed and were rewritten.
     * @param int $skipped Products that existed and were left alone (`overwrite` off).
     * @param int $failed  Products that could not be migrated.
     * @param int $warnings Coherence problems recorded without stopping the item.
     * @param bool $dryRun Whether this was a rehearsal.
     * @param list<array{ref: string, action: string, message: string}> $entries Per-item detail.
     */
    public function __construct(
        public int $created,
        public int $updated,
        public int $skipped,
        public int $failed,
        public int $warnings,
        public bool $dryRun,
        public array $entries,
    ) {
    }

    /**
     * An empty report, for a run that had nothing to do.
     */
    public static function empty(bool $dryRun = false): self
    {
        return new self(0, 0, 0, 0, 0, $dryRun, array());
    }

    /**
     * Products the run touched in the target.
     */
    public function changed(): int
    {
        return $this->created + $this->updated;
    }

    /**
     * Every product the source yielded.
     */
    public function processed(): int
    {
        return $this->created + $this->updated + $this->skipped + $this->failed;
    }

    public function isClean(): bool
    {
        return 0 === $this->failed;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return array(
            'created'  => $this->created,
            'updated'  => $this->updated,
            'skipped'  => $this->skipped,
            'failed'   => $this->failed,
            'warnings' => $this->warnings,
            'changed'  => $this->changed(),
            'processed' => $this->processed(),
            'dry_run'  => $this->dryRun,
            'entries'  => $this->entries,
        );
    }
}
