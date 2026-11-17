{{--
    Automation definitions — Proposal 149 §30.

    Two read-only tables: workflow definitions and the triggers that run them.
    Plain HTML with inline styles, as on the other catalogue pages.

    No create, edit, delete or fire control. The console owns this CRUD
    (`writer: console`); a control here would either be a second writer or a
    button that opens another surface and pretends to be this one.
--}}
@php
    $all = $this->catalogue();
    $rows = $this->rows();
    $totals = $this->totals( $rows );
    $limit = $this->workflowLimit();

    $labelStyle = 'display: block; font-size: 0.8rem; margin-bottom: 0.25rem;';
    $fieldStyle = 'width: 100%; padding: 0.5rem 0.75rem; border-radius: 0.5rem; border: 1px solid rgba(127, 127, 127, 0.35); background: transparent;';
@endphp

<x-filament-panels::page>
    <div style="display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: flex-end; margin-bottom: 1rem;">
        <div style="flex: 1 1 18rem;">
            <label for="automation-search" style="{{ $labelStyle }}">Search</label>
            <input
                id="automation-search"
                type="search"
                wire:model.live.debounce.400ms="search"
                placeholder="Workflow title or tag, trigger name, type or schedule"
                style="{{ $fieldStyle }}"
            >
        </div>

        <div style="padding-bottom: 0.15rem;">
            <x-filament::button type="button" color="gray" size="sm" wire:click="clearFilters">
                Clear
            </x-filament::button>
        </div>
    </div>

    <p style="font-size: 0.85rem; margin-bottom: 1rem;">
        <strong>{{ $totals['workflows'] }}</strong> of <strong>{{ count( $all['workflows'] ) }}</strong> workflow(s)
        (newest {{ $limit }} are read);
        <strong>{{ $totals['triggers'] }}</strong> of <strong>{{ count( $all['triggers'] ) }}</strong> trigger(s),
        <strong>{{ $totals['enabled'] }}</strong> enabled and <strong>{{ $totals['disabled'] }}</strong> disabled.
        Read-only: definitions are edited in the console, and nothing here writes.
    </p>

    <h3 style="font-size: 0.95rem; margin: 1.25rem 0 0.5rem;">Workflows</h3>

    @if ( array() === $rows['workflows'] )
        <p style="font-size: 0.85rem;">No workflow matches that filter.</p>
    @else
        <table style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
            <thead>
                <tr style="text-align: left; border-bottom: 1px solid rgba(127, 127, 127, 0.35);">
                    <th style="padding: 0.5rem 0.5rem 0.5rem 0;">Workflow</th>
                    <th style="padding: 0.5rem;">Version</th>
                    <th style="padding: 0.5rem;">Tags</th>
                    <th style="padding: 0.5rem;">Graph</th>
                    <th style="padding: 0.5rem;">Modified</th>
                </tr>
            </thead>
            <tbody>
                @foreach ( $rows['workflows'] as $row )
                    <tr style="border-bottom: 1px solid rgba(127, 127, 127, 0.15); vertical-align: top;">
                        <td style="padding: 0.5rem 0.5rem 0.5rem 0;">
                            <div>{{ $row['title'] }}</div>
                            <code style="font-size: 0.75rem; opacity: 0.75;">#{{ (int) $row['id'] }}</code>
                        </td>
                        <td style="padding: 0.5rem;"><code style="font-size: 0.75rem;">{{ $row['version'] }}</code></td>
                        <td style="padding: 0.5rem;">{{ array() === (array) $row['tags'] ? '—' : implode( ', ', (array) $row['tags'] ) }}</td>
                        <td style="padding: 0.5rem;">{{ (int) $row['nodes'] }} node(s), {{ (int) $row['edges'] }} edge(s)</td>
                        <td style="padding: 0.5rem;">{{ '' !== $row['modified'] ? $row['modified'] : '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h3 style="font-size: 0.95rem; margin: 1.5rem 0 0.5rem;">Triggers</h3>

    @if ( array() === $rows['triggers'] )
        <p style="font-size: 0.85rem;">No trigger matches that filter.</p>
    @else
        <table style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
            <thead>
                <tr style="text-align: left; border-bottom: 1px solid rgba(127, 127, 127, 0.35);">
                    <th style="padding: 0.5rem 0.5rem 0.5rem 0;">Trigger</th>
                    <th style="padding: 0.5rem;">Type</th>
                    <th style="padding: 0.5rem;">State</th>
                    <th style="padding: 0.5rem;">Workflow</th>
                    <th style="padding: 0.5rem;">Schedule / endpoint</th>
                    <th style="padding: 0.5rem;">Last fired</th>
                </tr>
            </thead>
            <tbody>
                @foreach ( $rows['triggers'] as $row )
                    <tr style="border-bottom: 1px solid rgba(127, 127, 127, 0.15); vertical-align: top;">
                        <td style="padding: 0.5rem 0.5rem 0.5rem 0;">
                            <div>{{ $row['name'] }}</div>
                            <code style="font-size: 0.75rem; opacity: 0.75;">#{{ (int) $row['id'] }}</code>
                        </td>
                        <td style="padding: 0.5rem;"><code style="font-size: 0.75rem;">{{ $row['type'] }}</code></td>
                        <td style="padding: 0.5rem;">
                            @if ( $row['enabled'] )
                                <span style="display: inline-block; padding: 0.1rem 0.5rem; border-radius: 999px; font-size: 0.75rem; color: #065f46; background: rgba(16, 185, 129, 0.15);">enabled</span>
                            @else
                                <span style="display: inline-block; padding: 0.1rem 0.5rem; border-radius: 999px; font-size: 0.75rem; color: #374151; background: rgba(107, 114, 128, 0.15);">disabled</span>
                            @endif
                        </td>
                        <td style="padding: 0.5rem;">
                            @if ( (int) $row['workflow_id'] > 0 )
                                <code style="font-size: 0.75rem;">#{{ (int) $row['workflow_id'] }}</code>
                            @else
                                <span style="font-size: 0.75rem; opacity: 0.7;">none</span>
                            @endif
                        </td>
                        <td style="padding: 0.5rem;">
                            @if ( '' !== $row['schedule'] )
                                <code style="font-size: 0.75rem;">{{ $row['schedule'] }}</code>
                            @elseif ( $row['has_webhook'] )
                                {{-- Host only: webhook providers put the secret in the path. --}}
                                <code style="font-size: 0.75rem;">{{ '' !== $row['webhook_host'] ? $row['webhook_host'] : 'webhook' }}</code>
                            @else
                                <span style="font-size: 0.75rem; opacity: 0.7;">—</span>
                            @endif
                        </td>
                        <td style="padding: 0.5rem;">{{ '' !== $this->lastFired( (int) $row['last_fired_at'] ) ? $this->lastFired( (int) $row['last_fired_at'] ) : 'never' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</x-filament-panels::page>
