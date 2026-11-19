{{--
    Token usage — Proposal 149 §82 (per user) and §87 (per estate).

    The WordPress token manager's `per_user` and `per_site` views on one page:
    the estate select is the `per_site` scope, and the user table below it is the
    `per_user` view for that scope. Plain HTML with inline styles, as on the
    other operator pages.
--}}
@php
    $rows = $this->rows();
    $totals = $this->totals();
    $estates = $this->estates();
    $models = $this->models();
    $scoped = $this->isScoped();
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

        <label for="token-usage-estate" style="font-weight: 600;">Estate</label>

        <select
            id="token-usage-estate"
            wire:model.live="estate"
            style="padding: 0.35rem 0.5rem; border: 1px solid rgba(127, 127, 127, 0.4); border-radius: 0.375rem;"
        >
            @foreach ($this->estateOptions() as $value => $label)
                <option value="{{ $value }}" @selected($this->estateScope() === ('' === (string) $value ? null : (string) $value))>{{ $label }}</option>
            @endforeach
        </select>

        <span style="opacity: 0.7;">{{ $this->windowLabel() }}</span>
    </div>

    @unless ($scoped)
        <h3 style="font-weight: 600; margin-bottom: 0.25rem;">By estate</h3>
        <p style="opacity: 0.7; font-size: 0.85rem; margin-bottom: 0.75rem;">
            Every estate with usage in the window, largest token volume first. Select one
            above to narrow the table below to its users.
        </p>

        <table style="width: 100%; border-collapse: collapse; font-size: 0.9rem; margin-bottom: 2rem;">
            <thead>
                <tr style="text-align: left; border-bottom: 1px solid rgba(127, 127, 127, 0.35);">
                    <th style="padding: 0.4rem 0.5rem;">Estate</th>
                    <th style="padding: 0.4rem 0.5rem; text-align: right;">Users</th>
                    <th style="padding: 0.4rem 0.5rem; text-align: right;">Requests</th>
                    <th style="padding: 0.4rem 0.5rem; text-align: right;">Prompt tokens</th>
                    <th style="padding: 0.4rem 0.5rem; text-align: right;">Completion tokens</th>
                    <th style="padding: 0.4rem 0.5rem; text-align: right;">Total tokens</th>
                    <th style="padding: 0.4rem 0.5rem; text-align: right;">Share</th>
                    <th style="padding: 0.4rem 0.5rem; text-align: right;">Est. cost (USD)</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($estates as $estate)
                    <tr style="border-bottom: 1px solid rgba(127, 127, 127, 0.15);">
                        <td style="padding: 0.4rem 0.5rem;">{{ $estate['name'] }}</td>
                        <td style="padding: 0.4rem 0.5rem; text-align: right;">{{ number_format($estate['users']) }}</td>
                        <td style="padding: 0.4rem 0.5rem; text-align: right;">{{ number_format($estate['events']) }}</td>
                        <td style="padding: 0.4rem 0.5rem; text-align: right;">{{ number_format($estate['prompt_tokens']) }}</td>
                        <td style="padding: 0.4rem 0.5rem; text-align: right;">{{ number_format($estate['completion_tokens']) }}</td>
                        <td style="padding: 0.4rem 0.5rem; text-align: right;">{{ number_format($estate['total_tokens']) }}</td>
                        <td style="padding: 0.4rem 0.5rem; text-align: right;">{{ number_format($estate['share'], 1) }}%</td>
                        <td style="padding: 0.4rem 0.5rem; text-align: right;">{{ number_format($estate['cost'], 4) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endunless

    @if ($scoped && [] !== $models)
        <h3 style="font-weight: 600; margin-bottom: 0.25rem;">By model — {{ $this->scopeLabel() }}</h3>
        <p style="opacity: 0.7; font-size: 0.85rem; margin-bottom: 0.75rem;">
            Every model this estate used in the window, largest token volume first. Provider is
            not a column: it is recorded inside the metadata blob, so a per-provider breakdown is
            a row scan rather than an aggregate, and <code>/api/v1/cost/by-provider</code> already
            answers it for one estate.
        </p>

        <table style="width: 100%; border-collapse: collapse; font-size: 0.9rem; margin-bottom: 2rem;">
            <thead>
                <tr style="text-align: left; border-bottom: 1px solid rgba(127, 127, 127, 0.35);">
                    <th style="padding: 0.4rem 0.5rem;">Model</th>
                    <th style="padding: 0.4rem 0.5rem; text-align: right;">Requests</th>
                    <th style="padding: 0.4rem 0.5rem; text-align: right;">Prompt tokens</th>
                    <th style="padding: 0.4rem 0.5rem; text-align: right;">Completion tokens</th>
                    <th style="padding: 0.4rem 0.5rem; text-align: right;">Total tokens</th>
                    <th style="padding: 0.4rem 0.5rem; text-align: right;">Est. cost (USD)</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($models as $model)
                    <tr style="border-bottom: 1px solid rgba(127, 127, 127, 0.15);">
                        <td style="padding: 0.4rem 0.5rem;">{{ $model['model_id'] }}</td>
                        <td style="padding: 0.4rem 0.5rem; text-align: right;">{{ number_format($model['events']) }}</td>
                        <td style="padding: 0.4rem 0.5rem; text-align: right;">{{ number_format($model['prompt_tokens']) }}</td>
                        <td style="padding: 0.4rem 0.5rem; text-align: right;">{{ number_format($model['completion_tokens']) }}</td>
                        <td style="padding: 0.4rem 0.5rem; text-align: right;">{{ number_format($model['total_tokens']) }}</td>
                        <td style="padding: 0.4rem 0.5rem; text-align: right;">{{ number_format($model['cost'], 4) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h3 style="font-weight: 600; margin-bottom: 0.25rem;">By user — {{ $this->scopeLabel() }}</h3>

    @if ([] === $rows)
        @if ($scoped)
            <p style="opacity: 0.8;">
                {{ $this->scopeLabel() }} recorded no token usage in this window. Usage is
                written when a chat turn completes (Proposal 149 §81), so an estate with no
                chats yet legitimately shows nothing.
            </p>
        @else
            <p style="opacity: 0.8;">
                No token usage recorded in this window. Usage is written when a chat turn
                completes (Proposal 149 §81); a deployment with no chats yet legitimately
                shows nothing.
            </p>
        @endif
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
    @endif

    <p style="margin-top: 1rem; opacity: 0.7; font-size: 0.85rem;">
        Turns without an authenticated user are counted as <strong>Anonymous</strong>; they are
        spend without a person, so the estate table's <em>Users</em> column does not count them.
        Share is the estate's share of <em>tokens</em>, not of cost, because a window with no
        priced model records zeros for every cost — a column of 0.0% would read as a broken
        query rather than as a pricing gap.
        There is no <em>Tools used</em> column: the tool is recorded inside the metadata blob,
        so counting it means reading every row of the window instead of aggregating in SQL, and
        the WordPress <code>per_tool</code> view is a limits configuration table rather than a
        usage one (Proposal 149 §82.3).
    </p>
</x-filament-panels::page>
