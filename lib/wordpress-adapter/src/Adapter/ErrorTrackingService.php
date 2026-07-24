<?php
/**
 * WordPress Adapter — Error Tracking Service.
 *
 * Bridges {@see ErrorTrackingServiceInterface} to the legacy
 * {@see WP_MCP_AI_Error_Tracking_Service} singleton.
 *
 * @package  Nvoos\WordPress
 * @since    2.0.0
 * @license  GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Nvoos\WordPress\Adapter;

use Nvoos\Core\Domain\Contract\ErrorTrackingServiceInterface;

/**
 * WordPress adapter for error tracking.
 *
 * Delegates to the legacy singleton. When a pure domain implementation
 * is ready, this adapter will swap its delegate.
 *
 * @since 2.0.0
 */
final class ErrorTrackingService implements ErrorTrackingServiceInterface
{
    /**
     * Legacy service instance.
     *
     * @var \WP_MCP_AI_Error_Tracking_Service|null
     */
    private $legacy;

    /**
     * Constructor.
     */
    public function __construct()
    {
        if (\class_exists('WP_MCP_AI_Error_Tracking_Service')) {
            $this->legacy = \WP_MCP_AI_Error_Tracking_Service::get_instance();
        }
    }

    public function track(string $component, string $message, array $context = []): string
    {
        if ($this->legacy === null) {
            return 'err_fallback_' . \uniqid('', true);
        }

        return (string) $this->legacy->track_error($component, $message, $context);
    }

    public function getRecent(int $limit = 50): array
    {
        if ($this->legacy === null || !\method_exists($this->legacy, 'get_recent_errors')) {
            return [];
        }

        /** @var array<int, array> $errors */
        $errors = $this->legacy->get_recent_errors($limit);

        return \is_array($errors) ? $errors : [];
    }

    public function getRate(string $component = '', int $windowSeconds = 3600): float
    {
        if ($this->legacy === null || !\method_exists($this->legacy, 'get_error_rate')) {
            return 0.0;
        }

        return (float) $this->legacy->get_error_rate($component, $windowSeconds);
    }

    public function clear(): void
    {
        if ($this->legacy !== null && \method_exists($this->legacy, 'clear_errors')) {
            $this->legacy->clear_errors();
        }
    }

    public function isEnabled(): bool
    {
        if ($this->legacy !== null && \method_exists($this->legacy, 'is_enabled')) {
            return (bool) $this->legacy->is_enabled();
        }

        return $this->legacy !== null;
    }
}
