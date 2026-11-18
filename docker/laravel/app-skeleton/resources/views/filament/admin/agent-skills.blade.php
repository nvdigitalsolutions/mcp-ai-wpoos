{{--
    Skills, shortcuts and slash commands — Proposal 149 §49.

    Plain HTML with inline styles, as on the profession and tool catalogues: the
    panel's compiled CSS has no arbitrary utilities, and a missing class here
    would break filtering.

    Read-only by design. There is no install, execute or delete control because
    all three are file/command writes and the console owns them
    (`writer: console` on the contract row).
--}}
@php
    $allSkills = $this->allSkills();
    $skills = $this->skills();
    $path = $this->skillsPath();

    $assistants = $this->assistants();
    $shortcutBody = $this->shortcuts();
    $shortcuts = $shortcutBody['shortcuts'];

    $commands = $this->commands();
@endphp

<x-filament-panels::page>
    {{-- ── Skills ──────────────────────────────────────────────────────── --}}
    <h3 style="margin: 0 0 0.5rem;">Skills</h3>

    <div style="display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: flex-end; margin-bottom: 1rem;">
        <div style="flex: 1 1 20rem;">
            <label for="skill-search" style="display: block; font-size: 0.8rem; margin-bottom: 0.25rem;">Search</label>
            <input
                id="skill-search"
                type="search"
                wire:model.live.debounce.400ms="skillSearch"
                placeholder="Name, description, licence or compatibility"
                style="width: 100%; padding: 0.5rem 0.75rem; border-radius: 0.5rem; border: 1px solid rgba(127, 127, 127, 0.35); background: transparent;"
            >
        </div>

        <div style="padding-bottom: 0.15rem;">
            <x-filament::button type="button" color="gray" size="sm" wire:click="clearSkillSearch">
                Clear
            </x-filament::button>
        </div>
    </div>

    <p style="font-size: 0.85rem; margin-bottom: 0.75rem;">
        Showing <strong>{{ count( $skills ) }}</strong> of <strong>{{ count( $allSkills ) }}</strong> installed skill(s),
        read from <code style="font-size: 0.8rem;">{{ $path }}</code>.
        Read-only: the installer is WordPress-side and is not served by this runtime.
    </p>

    <x-admin.data-table
        caption="Skills installed in the skills directory"
        :columns="[
            ['label' => 'Skill'],
            ['label' => 'Licence'],
            ['label' => 'Compatibility'],
        ]"
        :rows="count( $skills )"
        :empty="array() === $allSkills ? 'No skill folders are present in that directory.' : 'No skill matches that filter.'"
    >
        @foreach ( $skills as $skill )
            <tr>
                <td>
                    <div>{{ $skill['name'] }}</div>
                    <div style="font-size: 0.8rem; opacity: 0.8;">{{ $skill['description'] }}</div>
                </td>
                <td>{{ '' === $skill['license'] ? '—' : $skill['license'] }}</td>
                <td>{{ '' === $skill['compatibility'] ? '—' : $skill['compatibility'] }}</td>
            </tr>
        @endforeach
    </x-admin.data-table>

    {{-- ── Shortcuts ───────────────────────────────────────────────────── --}}
    <h3 style="margin: 1.5rem 0 0.5rem;">Tool shortcuts</h3>

    @if ( array() === $assistants )
        <p style="font-size: 0.85rem; opacity: 0.8; margin-bottom: 1.5rem;">
            This estate has no assistants, so it has no shortcut configurations.
        </p>
    @else
        <div style="display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: flex-end; margin-bottom: 1rem;">
            <div style="flex: 0 1 20rem;">
                <label for="shortcut-assistant" style="display: block; font-size: 0.8rem; margin-bottom: 0.25rem;">Assistant</label>
                <select
                    id="shortcut-assistant"
                    wire:model.live="assistantId"
                    style="width: 100%; padding: 0.5rem 0.75rem; border-radius: 0.5rem; border: 1px solid rgba(127, 127, 127, 0.35); background: transparent;"
                >
                    @foreach ( $assistants as $id => $title )
                        <option value="{{ $id }}">{{ $title }}</option>
                    @endforeach
                </select>
            </div>

            <div style="flex: 1 1 16rem;">
                <label for="shortcut-search" style="display: block; font-size: 0.8rem; margin-bottom: 0.25rem;">Search</label>
                <input
                    id="shortcut-search"
                    type="search"
                    wire:model.live.debounce.400ms="shortcutSearch"
                    placeholder="Label, tool or description"
                    style="width: 100%; padding: 0.5rem 0.75rem; border-radius: 0.5rem; border: 1px solid rgba(127, 127, 127, 0.35); background: transparent;"
                >
            </div>

            <div style="padding-bottom: 0.15rem;">
                <x-filament::button type="button" color="gray" size="sm" wire:click="clearShortcutSearch">
                    Clear
                </x-filament::button>
            </div>
        </div>

        <p style="font-size: 0.85rem; margin-bottom: 0.75rem;">
            <strong>{{ count( $shortcuts ) }}</strong> shortcut(s)
            @if ( array() !== $shortcutBody['categories'] )
                across {{ count( $shortcutBody['categories'] ) }} categor(ies)
            @endif
            . Shortcuts are edited on the assistant in the console.
        </p>

        <x-admin.data-table
            caption="Tool shortcuts for the selected assistant"
            :columns="[
                ['label' => 'Shortcut'],
                ['label' => 'Tool'],
                ['label' => 'Category'],
                ['label' => 'Payload'],
            ]"
            :rows="count( $shortcuts )"
            empty="This assistant exposes no shortcuts — either none are configured, or the surface is switched off."
        >
            @foreach ( $shortcuts as $shortcut )
                <tr>
                    <td>
                        <div>{{ $shortcut['icon'] }} {{ $shortcut['label'] }}</div>
                        @if ( '' !== $shortcut['description'] )
                            <div style="font-size: 0.8rem; opacity: 0.8;">{{ $shortcut['description'] }}</div>
                        @endif
                    </td>
                    <td><code style="font-size: 0.8rem;">{{ '' === $shortcut['tool'] ? '—' : $shortcut['tool'] }}</code></td>
                    <td>{{ $shortcut['category'] }}</td>
                    <td><code style="font-size: 0.8rem;">{{ $shortcut['payload'] }}</code></td>
                </tr>
            @endforeach
        </x-admin.data-table>
    @endif

    {{-- ── Slash commands ──────────────────────────────────────────────── --}}
    <h3 style="margin: 1.5rem 0 0.5rem;">Slash commands</h3>

    <p style="font-size: 0.85rem; margin-bottom: 0.75rem;">
        <strong>{{ count( $commands ) }}</strong> command(s) this account may run.
        Filtered by the executor's own capability check, so a command that would refuse at
        execution time is not listed as available.
    </p>

    <x-admin.data-table
        caption="Slash commands available to this account"
        :columns="[
            ['label' => 'Command'],
            ['label' => 'Category'],
            ['label' => 'Usage'],
            ['label' => 'Parameters'],
        ]"
        :rows="count( $commands )"
        empty="No command is available to this account."
    >
        @foreach ( $commands as $command )
            <tr>
                <td>
                    <code style="font-size: 0.8rem;">/{{ $command['name'] }}</code>
                    <div style="font-size: 0.8rem; opacity: 0.8;">{{ $command['description'] }}</div>
                </td>
                <td>{{ $command['category'] }}</td>
                <td><code style="font-size: 0.8rem;">{{ $command['usage'] }}</code></td>
                <td>{{ '' === $command['parameters'] ? '—' : $command['parameters'] }}</td>
            </tr>
        @endforeach
    </x-admin.data-table>
</x-filament-panels::page>
