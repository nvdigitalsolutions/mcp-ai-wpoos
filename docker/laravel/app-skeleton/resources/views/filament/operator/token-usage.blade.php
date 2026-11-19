{{--
    Token usage by user — Proposal 149 §82.

    The `per_user` view of the WordPress token manager, with the columns this
    runtime can evidence. Plain HTML with inline styles, as on the other
    operator pages.
--}}
@php
    $rows = $this->rows();
    $totals = $this->totals();
@endphp

<x-filament-panels::page>
    <div style="display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: center; margin-bottom: 1rem; padding-bottom: 1rem; border-bottom: 1px solid rgba(127, 127, 127, 0.25);">
        <label for="token-usage-period" style="font-weight: 600;">Period</label>

        <select
            id="token-usage-period"
            wire:model.live="period"
            style="padding: 0.35rem 0.5rem; border: 1px solid rgba(127, 127, 127, 0.4); border-radius: 0.375rem;"
        >
            @foreach ($this->periods() as $days)
                <option value="{{ $days }}" @selected($period === $days)>Last {{ $days }} days</option>
            @endforeach
        </select>

        <span style="opacity: 0.7;">{{ $this->windowLabel() }}</span>
    </div>

    @if ([] === $rows)
        <p style="opacity: 0.8;">
            No token usage recorded in this window. Usage is written when a chat turn completes
            (Proposal 149 §81); a deployment with no chats yet legitimately shows nothing.
        </p>
    @else
        <table style="width: 100%; border-collapse: collapse; font-size: 0.9rem;">
            <thead>
                <tr style="text-align: left; border-bottom: 1px solid rgba(127, 127, 127, 0.35);">
                    <th style="padding: 0.4rem 0.5rem;">User</th>
                    <th style="padding: 0.4rem 0.5rem; text-align: right;">Requests</th>
                    <th style="padding: 0.4rem 0.5rem; text-align: right;">Prompt tokens</th>
                    <th style="padding: 0.4rem 0.5rem; text-align: right;">Completion tokens</th>
                    <th style="padding: 0.4rem 0.5rem; text-align: right;">Total tokens</th>
                    <th style="padding: 0.4rem 0.5rem; text-align: right;">Est. cost (USD)</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr style="border-bottom: 1px solid rgba(127, 127, 127, 0.15);">
                        <td style="padding: 0.4rem 0.5rem;">{{ $row['name'] }}</td>
                        <td style="padding: 0.4rem 0.5rem; text-align: right;">{{ number_format($row['events']) }}</td>
                        <td style="padding: 0.4rem 0.5rem; text-align: right;">{{ number_format($row['prompt_tokens']) }}</td>
                        <td style="padding: 0.4rem 0.5rem; text-align: right;">{{ number_format($row['completion_tokens']) }}</td>
                        <td style="padding: 0.4rem 0.5rem; text-align: right;">{{ number_format($row['total_tokens']) }}</td>
                        <td style="padding: 0.4rem 0.5rem; text-align: right;">{{ number_format($row['cost'], 4) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr style="border-top: 1px solid rgba(127, 127, 127, 0.35); font-weight: 600;">
                    <td style="padding: 0.4rem 0.5rem;">Total</td>
                    <td style="padding: 0.4rem 0.5rem; text-align: right;">{{ number_format($totals['events']) }}</td>
                    <td style="padding: 0.4rem 0.5rem; text-align: right;">{{ number_format($totals['prompt_tokens']) }}</td>
                    <td style="padding: 0.4rem 0.5rem; text-align: right;">{{ number_format($totals['completion_tokens']) }}</td>
                    <td style="padding: 0.4rem 0.5rem; text-align: right;">{{ number_format($totals['total_tokens']) }}</td>
                    <td style="padding: 0.4rem 0.5rem; text-align: right;">{{ number_format($totals['cost'], 4) }}</td>
                </tr>
            </tfoot>
        </table>

        <p style="margin-top: 1rem; opacity: 0.7; font-size: 0.85rem;">
            There is no per-site breakdown: the token-usage table has no estate scope
            (Proposal 149 §64). Turns without an authenticated user are counted as
            <strong>Anonymous</strong>.
        </p>
    @endif
</x-filament-panels::page>
