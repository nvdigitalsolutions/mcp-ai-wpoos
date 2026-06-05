<?php
/**
 * Abstract base class for remote-source drivers.
 *
 * Provides config storage, default implementations for optional methods,
 * capability flag support, and convenience helpers.
 *
 * @since   1.0.0
 * @package NvoosGraphify
 */

declare(strict_types=1);

namespace NvoosGraphify\Remote;

use NvoosGraphify\Contracts\RemoteSourceDriver;

/**
 * Abstract base class for remote-source drivers.
 *
 * @since 1.0.0
 */
abstract class BaseDriver implements RemoteSourceDriver
{
    /**
     * Driver configuration.
     *
     * @var array<string,mixed>
     */
    protected array $config = array();

    /**
     * {@inheritdoc}
     */
    public function setConfig(array $config): void
    {
        $this->config = $config;
    }

    /**
     * {@inheritdoc}
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Default discover() implementation — returns driver metadata.
     *
     * @since 1.0.0
     * @return array<string,mixed>
     */
    public function discover(): array
    {
        return array(
            'driver'           => $this->getDriverId(),
            'label'            => $this->getDriverLabel(),
            'capabilities'     => $this->getCapabilities(),
            'capability_flags' => $this->getCapabilityFlags(),
        );
    }

    /**
     * Default reconcile() — drivers that don't support reconciliation return unmatched.
     *
     * @since 1.0.0
     * @param object $localNode Unused.
     * @return array{external_id: string, confidence: float, matched: bool}
     */
    public function reconcile(object $localNode): array
    {
        return array(
            'external_id' => '',
            'confidence'  => 0.0,
            'matched'     => false,
        );
    }

    /**
     * Default fetchEdges() — most drivers don't expose edges directly.
     *
     * @since 1.0.0
     * @param array<string,mixed> $args Unused.
     * @return array<int,array<string,mixed>>
     */
    public function fetchEdges(array $args = array()): array
    {
        return array();
    }

    /**
     * Return the set of capability flags advertised by this driver.
     *
     * Recognised flags:
     *   - supports_incremental   : driver can resume from a watermark
     *   - supports_webhooks      : driver can receive push notifications
     *   - supports_oauth         : driver authenticates via OAuth2
     *   - supports_pagination    : driver paginates large result sets
     *   - supports_relationships : driver emits edges, not just nodes
     *
     * @since 1.0.0
     * @return array<string,bool>
     */
    public function getCapabilityFlags(): array
    {
        return array(
            'supports_incremental'   => false,
            'supports_webhooks'      => false,
            'supports_oauth'         => false,
            'supports_pagination'    => false,
            'supports_relationships' => false,
        );
    }

    /**
     * Helper: return the source slug from config (or a fallback).
     *
     * @since 1.0.0
     * @return string
     */
    protected function getSlug(): string
    {
        return isset($this->config['_slug']) ? sanitize_key($this->config['_slug']) : sanitize_key($this->getDriverId());
    }
}
