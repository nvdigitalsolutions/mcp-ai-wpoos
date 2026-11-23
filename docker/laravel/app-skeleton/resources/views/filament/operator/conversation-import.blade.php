{{--
    Conversation import — Proposal 149 §37.

    Two-step on purpose: preview parses and reports, import writes. A
    paste-and-import button would commit up to 500 conversations on one click.

    Plain HTML with inline styles, as on the other operator pages.
--}}
@php
    $limits = $this->limits();
    $report = $this->lastReport();
    $hasReport = $this->hasReport();
    $fatal = (string) ( $report['fatal'] ?? '' );
    $errors = (array) ( $report['errors'] ?? array() );
@endphp

<x-filament-panels::page>
    <p style="font-size: 0.85rem; margin-bottom: 0.5rem;">
        Paste a conversation export. Two shapes are accepted: ShareGPT
        (<code>[{conversations: [{from, value}]}]</code>) and a generic
        (<code>[{session_id, messages: [{role, content}]}]</code>).
        Limits: <strong>{{ number_format( $limits['bytes'] ) }}</strong> bytes,
        <strong>{{ (int) $limits['conversations'] }}</strong> conversations,
        <strong>{{ (int) $limits['messages'] }}</strong> messages per conversation.
    </p>

    <p style="font-size: 0.8rem; margin-bottom: 1rem; opacity: 0.85;">
        Import is idempotent: transcripts upsert by session id, so re-pasting the same export
        corrects the existing rows instead of duplicating them. Messages are scrubbed to valid
        UTF-8 and roles are mapped from a closed set.
    </p>

    @php
        $estates = $this->estates();
    @endphp

    {{-- `/ops` has no selected estate, so the write scope is named here. The
         initial value is `default`, which is what every import did before the
         selector existed — the difference is that it is now visible. --}}
    <div style="margin-bottom: 1rem; max-width: 24rem;">
        <label for="import-estate" style="display: block; font-size: 0.8rem; margin-bottom: 0.25rem;">Target estate</label>
        <select
            id="import-estate"
            wire:model="estate"
            style="width: 100%; padding: 0.5rem 0.75rem; border-radius: 0.5rem; border: 1px solid rgba(127, 127, 127, 0.35); background: transparent;"
        >
            @if ( array() === $estates )
                <option value="">No estates are provisioned</option>
            @endif

            @foreach ( $estates as $option )
                <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
            @endforeach
        </select>
        <p style="font-size: 0.75rem; margin: 0.3rem 0 0; opacity: 0.75;">
            Transcripts are written to this estate's store. The operator panel has no
            estate of its own, so this page states the target instead of inheriting one.
        </p>
    </div>

    <div style="margin-bottom: 1rem;">
        <label for="import-file" style="display: block; font-size: 0.8rem; margin-bottom: 0.25rem;">Or load an export file</label>
        <div style="display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap;">
            <input
                id="import-file"
                type="file"
                wire:model="upload"
                accept=".json,.txt,application/json,text/plain"
                style="font-size: 0.8rem;"
            />
            <x-filament::button
                type="button"
                color="gray"
                size="sm"
                wire:click="loadUpload"
                wire:loading.attr="disabled"
            >
                Load file
            </x-filament::button>
        </div>

        @if ( '' !== $this->uploadError )
            <p style="font-size: 0.8rem; margin: 0.35rem 0 0; color: #991b1b;">{{ $this->uploadError }}</p>
        @endif

        <p style="font-size: 0.75rem; margin: 0.3rem 0 0; opacity: 0.75;">
            JSON or plain text, up to {{ number_format( $limits['bytes'] ) }} bytes. The file only fills
            the body field — nothing is written to storage, and you can still edit the text before previewing.
            @if ( '' !== $this->sourceName )
                Loaded <strong>{{ $this->sourceName }}</strong>.
            @endif
        </p>
    </div>

    <div style="margin-bottom: 1rem;">
        <label for="import-body" style="display: block; font-size: 0.8rem; margin-bottom: 0.25rem;">Export body</label>
        <textarea
            id="import-body"
            wire:model="body"
            rows="12"
            spellcheck="false"
            placeholder='[{"conversations":[{"from":"human","value":"Hello"},{"from":"gpt","value":"Hi"}]}]'
            style="width: 100%; padding: 0.6rem 0.75rem; border-radius: 0.5rem; border: 1px solid rgba(127, 127, 127, 0.35); background: transparent; font-family: monospace; font-size: 0.8rem;"
        ></textarea>
    </div>

    <div style="display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: center; margin-bottom: 1rem;">
        <x-filament::button type="button" color="gray" size="sm" wire:click="preview">
            Preview
        </x-filament::button>
        <x-filament::button type="button" size="sm" wire:click="import">
            Import
        </x-filament::button>
        <span style="font-size: 0.8rem; opacity: 0.8;">
            Preview writes nothing. Import writes into the selected estate's transcript store.
        </span>
    </div>

    @if ( $hasReport )
        <div style="padding: 0.85rem 1rem; border-radius: 0.5rem; border: 1px solid rgba(127, 127, 127, 0.35);">
            @if ( '' !== $fatal )
                <p style="font-size: 0.85rem; margin: 0; color: #991b1b;">
                    <strong>Not imported.</strong> {{ $fatal }}
                </p>
            @elseif ( 'preview' === $this->mode )
                <p style="font-size: 0.85rem; margin: 0;">
                    <strong>{{ (int) ( $report['conversations'] ?? 0 ) }}</strong> conversation(s),
                    <strong>{{ (int) ( $report['messages'] ?? 0 ) }}</strong> message(s) ready to import.
                    @if ( (int) ( $report['truncated'] ?? 0 ) > 0 )
                        <strong>{{ (int) $report['truncated'] }}</strong> message(s) would be truncated by the per-conversation limit.
                    @endif
                    Nothing has been written yet.
                </p>
            @else
                <p style="font-size: 0.85rem; margin: 0;">
                    <strong>{{ (int) ( $report['imported'] ?? 0 ) }}</strong> conversation(s) imported,
                    <strong>{{ (int) ( $report['messages'] ?? 0 ) }}</strong> message(s),
                    <strong>{{ (int) ( $report['failed'] ?? 0 ) }}</strong> failed.
                    @if ( (int) ( $report['truncated'] ?? 0 ) > 0 )
                        <strong>{{ (int) $report['truncated'] }}</strong> message(s) were truncated.
                    @endif
                </p>
            @endif

            @if ( array() !== $errors )
                <ul style="font-size: 0.8rem; margin: 0.6rem 0 0 1rem; padding: 0;">
                    @foreach ( array_slice( $errors, 0, 20 ) as $error )
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
                @if ( count( $errors ) > 20 )
                    <p style="font-size: 0.8rem; margin: 0.4rem 0 0;">
                        … and {{ count( $errors ) - 20 }} more.
                    </p>
                @endif
            @endif
        </div>
    @endif
</x-filament-panels::page>
