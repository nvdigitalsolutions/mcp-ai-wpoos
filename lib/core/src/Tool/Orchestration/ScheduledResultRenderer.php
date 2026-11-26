<?php

/**
 * Server-side renderer for schedule result envelopes.
 *
 * Port of `WP_MCP_AI_Scheduled_Result_Renderer` (Proposal 164 section 164.80).
 * Six canonical modes — `summary-card`, `list`, `table`, `metric`, `timeline`,
 * `raw` — over the envelope shape
 * `{summary, data, render, response, status, error, generated_at}`.
 *
 * ## Escaping
 *
 * Every interpolated value is HTML-escaped with `htmlspecialchars(ENT_QUOTES,
 * UTF-8)`, matching the WordPress renderer's `esc_html()`. One mode is
 * deliberately narrower: WordPress's `raw` + `html-safe` passed authenticated
 * output through `wp_kses_post()`, and the framework-free core has no kses.
 * Rather than return unsanitised markup, `html-safe` renders escaped text. A
 * host that wants HTML in a tile can render it itself; a tool that returns
 * unfiltered HTML is the wrong default.
 *
 * ## Timezone
 *
 * The "last run" label is formatted in **UTC**, the core's timestamp
 * convention, where WordPress used the site's date and time formats. The
 * timestamps themselves are the same Unix values.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Orchestration;

use Nvoos\Core\Domain\Contract\ScheduleStoreInterface;

final class ScheduledResultRenderer
{
    public const MODES = ScheduleDefinition::RENDER_MODES;

    public function __construct(private readonly ScheduleStoreInterface $schedules)
    {
    }

    /**
     * Render a schedule's latest result.
     *
     * @param array<string, mixed>      $schedule Schedule record.
     * @param array<string, mixed>|null $envelope Latest envelope, or null.
     * @param array<string, mixed>      $opts     render_mode, title, show_last_run, truncate_chars, refresh_interval.
     */
    public function render(array $schedule, ?array $envelope, array $opts = array()): string
    {
        $defaults = array(
            'render_mode'      => (string) ( $schedule['display']['widget_defaults']['render_mode'] ?? 'summary-card' ),
            'title'            => (string) ( $schedule['name'] ?? '' ),
            'show_last_run'    => true,
            'truncate_chars'   => 0,
            'refresh_interval' => (int) ( $schedule['display']['widget_defaults']['refresh_interval'] ?? 0 ),
        );

        $opts = array_merge($defaults, $opts);

        $mode = in_array($opts['render_mode'], self::MODES, true)
            ? (string) $opts['render_mode']
            : 'summary-card';

        if (null === $envelope) {
            return $this->notice(
                'No runs have been recorded yet for this schedule.',
                (string) $opts['title']
            );
        }

        $body = match ($mode) {
            'list'     => $this->renderList($envelope),
            'table'    => $this->renderTable($envelope),
            'metric'   => $this->renderMetric($envelope),
            'timeline' => $this->renderTimeline((string) ( $schedule['id'] ?? '' )),
            'raw'      => $this->renderRaw($envelope, (int) $opts['truncate_chars']),
            default    => $this->renderSummaryCard($envelope),
        };

        $header = '';
        $title  = (string) $opts['title'];

        if ('' !== $title) {
            $header .= '<h3 class="mcp-ai-scheduled-result__title">' . $this->escape($title) . '</h3>';
        }

        $generated = (int) ( $envelope['generated_at'] ?? 0 );

        if ($opts['show_last_run'] && $generated > 0) {
            $header .= '<p class="mcp-ai-scheduled-result__meta">'
                . $this->escape('Last run: ' . gmdate('Y-m-d H:i', $generated))
                . '</p>';
        }

        $refresh = '';

        if ((int) $opts['refresh_interval'] > 0) {
            $refresh = sprintf(
                ' data-mcp-ai-refresh-interval="%d" data-mcp-ai-refresh-schedule="%s"',
                (int) $opts['refresh_interval'],
                $this->escape((string) ( $schedule['id'] ?? '' ))
            );
        }

        return sprintf(
            '<div class="mcp-ai-scheduled-result mcp-ai-scheduled-result--%1$s"%2$s>%3$s<div class="mcp-ai-scheduled-result__body">%4$s</div></div>',
            $this->escape($mode),
            $refresh,
            $header,
            $body
        );
    }

    /**
     * A "no data" notice, escaped.
     */
    public function notice(string $message, string $title = ''): string
    {
        $header = '';

        if ('' !== $title) {
            $header = '<h3 class="mcp-ai-scheduled-result__title">' . $this->escape($title) . '</h3>';
        }

        return sprintf(
            '<div class="mcp-ai-scheduled-result mcp-ai-scheduled-result--notice">%1$s<p class="mcp-ai-scheduled-result__notice">%2$s</p></div>',
            $header,
            $this->escape($message)
        );
    }

    /**
     * @param array<string, mixed> $envelope Envelope.
     */
    private function renderSummaryCard(array $envelope): string
    {
        $summary = (string) ( $envelope['summary'] ?? '' );
        $status  = (string) ( $envelope['status'] ?? '' );
        $badge   = '';

        if ('' !== $status) {
            $badge = sprintf(
                '<span class="mcp-ai-scheduled-result__badge mcp-ai-scheduled-result__badge--%1$s">%2$s</span>',
                $this->escape($status),
                $this->escape($status)
            );
        }

        return sprintf(
            '<p class="mcp-ai-scheduled-result__summary">%1$s %2$s</p>',
            $this->escape($summary),
            $badge
        );
    }

    /**
     * @param array<string, mixed> $envelope Envelope.
     */
    private function renderList(array $envelope): string
    {
        $items = array();

        if (isset($envelope['data']['items']) && is_array($envelope['data']['items'])) {
            $items = $envelope['data']['items'];
        } elseif (isset($envelope['data']['steps']) && is_array($envelope['data']['steps'])) {
            foreach ($envelope['data']['steps'] as $step) {
                if (is_array($step) && isset($step['label'])) {
                    $items[] = (string) $step['label'];
                } elseif (is_string($step)) {
                    $items[] = $step;
                }
            }
        }

        if (array() === $items) {
            return $this->renderSummaryCard($envelope);
        }

        $html = '<ol class="mcp-ai-scheduled-result__list">';

        foreach ($items as $item) {
            if (is_array($item)) {
                $label = $item['title'] ?? ( $item['label'] ?? $this->json($item) );
            } else {
                $label = $item;
            }

            $html .= '<li>' . $this->escape(is_scalar($label) ? (string) $label : $this->json($label)) . '</li>';
        }

        return $html . '</ol>';
    }

    /**
     * @param array<string, mixed> $envelope Envelope.
     */
    private function renderTable(array $envelope): string
    {
        $rows    = isset($envelope['data']['rows']) && is_array($envelope['data']['rows']) ? $envelope['data']['rows'] : array();
        $columns = isset($envelope['data']['columns']) && is_array($envelope['data']['columns']) ? $envelope['data']['columns'] : array();

        if (array() === $rows) {
            return $this->renderSummaryCard($envelope);
        }

        if (array() === $columns) {
            $first   = is_array($rows[0] ?? null) ? $rows[0] : array();
            $columns = array_keys($first);
        }

        $html = '<table class="mcp-ai-scheduled-result__table"><thead><tr>';

        foreach ($columns as $column) {
            $html .= '<th>' . $this->escape((string) $column) . '</th>';
        }

        $html .= '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $html .= '<tr>';

            foreach ($columns as $column) {
                $cell  = is_array($row) && isset($row[ $column ]) ? $row[ $column ] : '';
                $html .= '<td>' . $this->escape(is_scalar($cell) ? (string) $cell : $this->json($cell)) . '</td>';
            }

            $html .= '</tr>';
        }

        return $html . '</tbody></table>';
    }

    /**
     * @param array<string, mixed> $envelope Envelope.
     */
    private function renderMetric(array $envelope): string
    {
        $value = $envelope['data']['value'] ?? null;

        if (null === $value) {
            return $this->renderSummaryCard($envelope);
        }

        $label     = (string) ( $envelope['data']['label'] ?? '' );
        $delta     = $envelope['data']['delta'] ?? null;
        $deltaHtml = '';

        if (null !== $delta && is_scalar($delta)) {
            $deltaHtml = sprintf(
                '<span class="mcp-ai-scheduled-result__delta mcp-ai-scheduled-result__delta--%1$s">%2$s</span>',
                $this->escape(( (float) $delta >= 0 ) ? 'up' : 'down'),
                $this->escape((string) $delta)
            );
        }

        return sprintf(
            '<div class="mcp-ai-scheduled-result__metric"><span class="mcp-ai-scheduled-result__value">%1$s</span>%2$s<span class="mcp-ai-scheduled-result__label">%3$s</span></div>',
            $this->escape(is_scalar($value) ? (string) $value : $this->json($value)),
            $deltaHtml,
            $this->escape($label)
        );
    }

    private function renderTimeline(string $scheduleId): string
    {
        $results = '' === $scheduleId ? array() : $this->schedules->results($scheduleId, 20);

        if (array() === $results) {
            return $this->notice('No history yet.');
        }

        $html = '<ul class="mcp-ai-scheduled-result__timeline" aria-label="Recent runs">';

        foreach (array_reverse($results) as $result) {
            $status = (string) ( $result['status'] ?? 'unknown' );
            $at     = (int) ( $result['generated_at'] ?? 0 );

            $html .= sprintf(
                '<li class="mcp-ai-scheduled-result__pip mcp-ai-scheduled-result__pip--%1$s" title="%2$s"></li>',
                $this->escape($status),
                $this->escape($at > 0 ? gmdate('Y-m-d H:i', $at) : '')
            );
        }

        return $html . '</ul>';
    }

    /**
     * @param array<string, mixed> $envelope Envelope.
     */
    private function renderRaw(array $envelope, int $truncate): string
    {
        $text = isset($envelope['data']['response'])
            ? (string) $envelope['data']['response']
            : (string) ( $envelope['summary'] ?? '' );

        if ($truncate > 0 && mb_strlen($text) > $truncate) {
            $text = mb_substr($text, 0, $truncate) . '…';
        }

        return '<pre class="mcp-ai-scheduled-result__raw mcp-ai-scheduled-result__raw--text">'
            . $this->escape($text) . '</pre>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private function json(mixed $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return false === $encoded ? '' : $encoded;
    }
}
