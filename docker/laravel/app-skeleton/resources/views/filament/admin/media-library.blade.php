{{--
    Media library — Proposal 149 §109.

    Uploads, alt editing and delete live here because this panel is the runtime's
    replacement for the WordPress media library: on this profile the console's
    Media Studio is a WordPress product whose endpoint is blank. Every mutating
    action audits itself (`AdminAuditLogger::recordResource()`) — Livewire
    requests never pass through the middleware that audits the API (plan §25).

    Uploads have one writer: the upload service sniffs the type, applies the
    allow-list, enforces the ceiling and names the stored file, so this page
    cannot become a weaker gate than the API.

    Plain HTML with inline styles, as on the other ported pages — the panel's
    compiled CSS has no arbitrary utilities.

    The table goes through `x-admin.data-table` (§55.5) rather than a hand-rolled
    `<thead>`: the shared component owns the caption, the scoped column headers
    and the sticky header, and keeps the empty state inside the body so the
    column headings survive a filter that matches nothing.

    One honesty device: WordPress reports 100% alt coverage when there are no
    images to measure. That is a true number saying something false, so it is
    labelled rather than printed as a success.
--}}
@php
    $summary = $this->summary();
    $library = $this->library();
    $measured = $this->altIsMeasured();
@endphp

<x-filament-panels::page>
    @unless ($measured)
        <x-filament::callout
            color="info"
            icon="heroicon-o-information-circle"
            heading="No images to measure"
            description="Alt coverage and next-gen coverage are shown as 100% only because there is nothing to divide by — the same reading WordPress gives an empty library. They are not a clean bill of health."
        />
    @endunless

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr)); gap: 0.75rem; margin-bottom: 1.25rem;">
        @foreach ([
            ['Files', number_format($summary['total']), 'in this estate'],
            ['Images', number_format($summary['image_count']), 'the alt figures are about these'],
            ['Alt coverage', $measured ? $summary['alt_pct'].'%' : '—', $measured ? number_format($summary['with_alt']).' with alt text' : 'nothing to measure'],
            ['Storage', $this->humanBytes($summary['storage']), 'across every file'],
            ['Next-gen', $measured ? $summary['nextgen_pct'].'%' : '—', 'WebP or AVIF'],
        ] as [$label, $value, $note])
            <div style="padding: 0.9rem 1rem; border: 1px solid rgba(127, 127, 127, 0.25); border-radius: 0.5rem;">
                <div style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.04em; opacity: 0.75;">{{ $label }}</div>
                <div style="font-size: 1.5rem; font-weight: 600; line-height: 1.2; font-variant-numeric: tabular-nums; margin: 0.15rem 0;">{{ $value }}</div>
                <div style="font-size: 0.75rem; opacity: 0.7;">{{ $note }}</div>
            </div>
        @endforeach
    </div>

    {{-- Upload. One writer: the service owns the sniffed type, the allow-list,
         the size ceiling and the stored filename, so this panel cannot become a
         weaker second gate. --}}
    <div style="padding: 1rem; border: 1px solid rgba(127, 127, 127, 0.25); border-radius: 0.5rem; margin-bottom: 1.25rem;">
        <h2 style="font-size: 1rem; font-weight: 600; margin: 0 0 0.35rem;">Add files</h2>
        <p style="font-size: 0.8rem; opacity: 0.8; margin: 0 0 0.75rem;">
            Images, audio, video, PDFs, plain text, CSV, JSON and Word documents, up to
            {{ $this->humanBytes($this->maxBytes()) }} each in one request — the smaller
            of the app's allowance and PHP's own limits. The type is sniffed
            from the content, not trusted from the filename.
        </p>

        <div style="display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: center;">
            <label for="media-upload" style="font-weight: 600;">Choose files</label>
            <input
                id="media-upload"
                type="file"
                multiple
                wire:model="uploads"
                style="font-size: 0.85rem;"
            />

            <button
                type="button"
                wire:click="upload"
                wire:loading.attr="disabled"
                wire:target="uploads,upload"
                style="padding: 0.4rem 0.9rem; border-radius: 0.375rem; font-weight: 600; border: 1px solid rgba(127, 127, 127, 0.4);"
            >Add to library</button>

            <span wire:loading wire:target="uploads" style="font-size: 0.8rem; opacity: 0.8;">Uploading…</span>
        </div>

        @error('uploads')
            <p style="font-size: 0.8rem; color: #b91c1c; margin: 0.5rem 0 0;">{{ $message }}</p>
        @enderror

        @if ([] !== $uploadErrors)
            <ul style="font-size: 0.8rem; color: #b91c1c; margin: 0.5rem 0 0; padding-left: 1.1rem;">
                @foreach ($uploadErrors as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        @endif
    </div>

    {{-- Resumable — §164.30. The input above is one request, and PHP bounds a
         request (2 MB for the file in the shipped image). This one sends chunks,
         so the ceiling is the app's per-file allowance rather than the
         interpreter's per-request limit; the script resumes from whatever offset
         the server reports after a dropped connection. --}}
    <div style="padding: 1rem; border: 1px solid rgba(127, 127, 127, 0.25); border-radius: 0.5rem; margin-bottom: 1.25rem;">
        <h2 style="font-size: 1rem; font-weight: 600; margin: 0 0 0.35rem;">Add a large file</h2>
        <p style="font-size: 0.8rem; opacity: 0.8; margin: 0 0 0.75rem;">
            Up to {{ $this->humanBytes($this->chunkedMaxBytes()) }}, sent in
            {{ $this->humanBytes($this->chunkBytes()) }} chunks. A dropped connection
            costs one chunk, not the file. The same sniffed type list applies.
        </p>

        <div style="display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: center;">
            <label for="media-chunked-file" style="font-weight: 600;">Choose a file</label>
            <input
                id="media-chunked-file"
                type="file"
                style="font-size: 0.85rem;"
            />

            <button
                type="button"
                id="media-chunked-go"
                data-csrf="{{ csrf_token() }}"
                data-chunk="{{ $this->chunkBytes() }}"
                style="padding: 0.4rem 0.9rem; border-radius: 0.375rem; font-weight: 600; border: 1px solid rgba(127, 127, 127, 0.4);"
            >Upload in chunks</button>

            <span id="media-chunked-status" style="font-size: 0.8rem; opacity: 0.85;" role="status" aria-live="polite"></span>
        </div>
    </div>

    <div style="display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: center; margin-bottom: 1rem; padding-bottom: 1rem; border-bottom: 1px solid rgba(127, 127, 127, 0.25);">
        <label for="media-search" style="font-weight: 600;">Search</label>
        <input
            id="media-search"
            type="search"
            wire:model.live.debounce.400ms="search"
            placeholder="filename"
            style="padding: 0.35rem 0.5rem; border: 1px solid rgba(127, 127, 127, 0.4); border-radius: 0.375rem; min-width: 14rem;"
        />

        <label for="media-type" style="font-weight: 600; margin-left: 0.5rem;">Type</label>
        <select
            id="media-type"
            wire:model.live="type"
            style="padding: 0.35rem 0.5rem; border: 1px solid rgba(127, 127, 127, 0.4); border-radius: 0.375rem;"
        >
            @foreach ($this->types() as $value => $label)
                <option value="{{ $value }}" @selected($type === $value)>{{ $label }}</option>
            @endforeach
        </select>

        <span style="font-size: 0.8rem; opacity: 0.85; margin-left: auto;">
            {{ number_format($library['total']) }} matching {{ 1 === $library['total'] ? 'file' : 'files' }}
        </span>
    </div>

    <x-admin.data-table
        caption="Files in this estate's media library"
        :columns="[
            ['label' => 'File'],
            ['label' => 'Type'],
            ['label' => 'Size'],
            ['label' => 'Alt text'],
            ['label' => 'Added'],
            ['label' => 'Actions'],
        ]"
        :rows="count($library['rows'])"
        :empty="'No file in this library matches that filter.'"
    >
        @foreach ($library['rows'] as $row)
            @php($url = $this->urlFor($row))
            <tr>
                <td style="overflow-wrap: anywhere;">
                    @if ('' !== $url)
                        <a href="{{ $url }}" target="_blank" rel="noopener" style="text-decoration: underline;">{{ $row['filename'] }}</a>
                    @else
                        {{ $row['filename'] }}
                    @endif
                </td>
                <td style="opacity: 0.85;">{{ '' !== $row['mime_type'] ? $row['mime_type'] : '—' }}</td>
                <td style="font-variant-numeric: tabular-nums; white-space: nowrap;">{{ $this->humanBytes($row['size_bytes']) }}</td>
                <td>
                    @if ('' === $row['alt_text'])
                        <span style="display: inline-block; padding: 0.1rem 0.5rem; border-radius: 999px; font-size: 0.7rem; color: #92400e; background: rgba(245, 158, 11, 0.15);">missing</span>
                    @else
                        <span style="opacity: 0.9;">{{ $row['alt_text'] }}</span>
                    @endif
                </td>
                <td style="font-size: 0.8rem; opacity: 0.7; white-space: nowrap;">
                    {{ '' !== $row['created_at'] ? substr($row['created_at'], 0, 10) : '—' }}
                </td>
                <td style="white-space: nowrap;">
                    <button
                        type="button"
                        wire:click="startEdit({{ $row['id'] }})"
                        style="padding: 0.25rem 0.6rem; font-size: 0.8rem; border: 1px solid rgba(127, 127, 127, 0.4); border-radius: 0.375rem;"
                    >Edit alt</button>
                    <button
                        type="button"
                        wire:click="confirmDelete({{ $row['id'] }})"
                        style="padding: 0.25rem 0.6rem; font-size: 0.8rem; border: 1px solid rgba(220, 38, 38, 0.45); color: #b91c1c; border-radius: 0.375rem;"
                    >Delete</button>
                </td>
            </tr>

            @if ($editingId === (string) $row['id'])
                <tr>
                    <td colspan="6" style="background: rgba(127, 127, 127, 0.06);">
                        <div style="display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: flex-start;">
                            <div style="flex: 1 1 22rem;">
                                <label for="alt-{{ $row['id'] }}" style="font-weight: 600; font-size: 0.85rem;">
                                    Alt text for {{ $row['filename'] }}
                                </label>
                                <input
                                    id="alt-{{ $row['id'] }}"
                                    type="text"
                                    maxlength="500"
                                    wire:model.live.debounce.400ms="editingAlt"
                                    style="width: 100%; margin-top: 0.25rem; padding: 0.35rem 0.5rem; border: 1px solid rgba(127, 127, 127, 0.4); border-radius: 0.375rem;"
                                />
                                <p style="font-size: 0.75rem; opacity: 0.75; margin: 0.3rem 0 0;">
                                    Describe what the image conveys, not that it is an image. Leave it
                                    empty only when the image is decorative — an empty description is a
                                    statement, not a gap.
                                    <span style="white-space: nowrap;">{{ mb_strlen($editingAlt) }}/500</span>
                                </p>
                            </div>
                            <div style="display: flex; gap: 0.5rem;">
                                <button
                                    type="button"
                                    wire:click="saveAlt"
                                    style="padding: 0.35rem 0.8rem; font-size: 0.85rem; font-weight: 600; border: 1px solid rgba(127, 127, 127, 0.4); border-radius: 0.375rem;"
                                >Save</button>
                                <button
                                    type="button"
                                    wire:click="cancelEdit"
                                    style="padding: 0.35rem 0.8rem; font-size: 0.85rem; border: 1px solid rgba(127, 127, 127, 0.4); border-radius: 0.375rem;"
                                >Cancel</button>
                            </div>
                        </div>
                    </td>
                </tr>
            @endif

            @if ($deletingId === (string) $row['id'])
                <tr>
                    <td colspan="6" style="background: rgba(220, 38, 38, 0.07);">
                        <div style="display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: center;">
                            <span style="font-size: 0.85rem;">
                                Delete <strong>{{ $row['filename'] }}</strong>? The stored file, its
                                derivatives and its record are removed. This cannot be undone.
                            </span>
                            <button
                                type="button"
                                wire:click="delete"
                                style="padding: 0.35rem 0.8rem; font-size: 0.85rem; font-weight: 600; border: 1px solid rgba(220, 38, 38, 0.6); color: #b91c1c; border-radius: 0.375rem;"
                            >Delete permanently</button>
                            <button
                                type="button"
                                wire:click="cancelDelete"
                                style="padding: 0.35rem 0.8rem; font-size: 0.85rem; border: 1px solid rgba(127, 127, 127, 0.4); border-radius: 0.375rem;"
                            >Cancel</button>
                        </div>
                    </td>
                </tr>
            @endif
        @endforeach
    </x-admin.data-table>

    @if ($library['pages'] > 1)
        <div style="display: flex; gap: 0.5rem; align-items: center; margin-top: 1rem; font-size: 0.85rem;">
            <button
                type="button"
                wire:click="goToPage({{ $library['page'] - 1 }})"
                @disabled($library['page'] <= 1)
                style="padding: 0.3rem 0.7rem; border: 1px solid rgba(127, 127, 127, 0.4); border-radius: 0.375rem;"
            >Previous</button>

            <span style="opacity: 0.8;">
                Page {{ $library['page'] }} of {{ $library['pages'] }}
            </span>

            <button
                type="button"
                wire:click="goToPage({{ $library['page'] + 1 }})"
                @disabled($library['page'] >= $library['pages'])
                style="padding: 0.3rem 0.7rem; border: 1px solid rgba(127, 127, 127, 0.4); border-radius: 0.375rem;"
            >Next</button>
        </div>
    @endif

    <p style="font-size: 0.8rem; opacity: 0.75; margin-top: 1.25rem; padding-top: 0.75rem; border-top: 1px solid rgba(127, 127, 127, 0.25);">
        This estate's files only. Every upload, alt edit and delete is recorded in the
        admin audit log with its before and after values, because Livewire requests
        bypass the middleware that audits the API. Files written before the library
        carried an estate are attributed to <code>default</code>, so a library that
        predates it may show a different count here than the console's global one.
    </p>
</x-filament-panels::page>

@php( $nonce = $cspNonce ?? null )
<script @if ( filled( $nonce ) ) nonce="{{ $nonce }}" @endif>
    (() => {
        const input = document.getElementById('media-chunked-file');
        const button = document.getElementById('media-chunked-go');
        const status = document.getElementById('media-chunked-status');

        if (!input || !button || !status) return;

        const CHUNK = Number(button.dataset.chunk) || 4194304;
        const CSRF = button.dataset.csrf || '';
        const say = (text) => { status.textContent = text; };

        // tus carries the filename base64-encoded; TextEncoder keeps non-ASCII
        // names intact where `btoa(name)` would throw.
        const b64 = (value) => btoa(Array.from(new TextEncoder().encode(value), (b) => String.fromCharCode(b)).join(''));

        const create = async (file) => fetch('/api/v1/media/uploads', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Tus-Resumable': '1.0.0',
                'Upload-Length': String(file.size),
                'Upload-Metadata': 'filename ' + b64(file.name) + ',filetype ' + b64(file.type || ''),
                'X-CSRF-TOKEN': CSRF,
                'Accept': 'application/json',
            },
        });

        const append = async (location, offset, chunk) => fetch(location, {
            method: 'PATCH',
            credentials: 'same-origin',
            headers: {
                'Tus-Resumable': '1.0.0',
                'Upload-Offset': String(offset),
                'Content-Type': 'application/offset+octet-stream',
                'X-CSRF-TOKEN': CSRF,
            },
            body: chunk,
        });

        button.addEventListener('click', async () => {
            const file = input.files && input.files[0];

            if (!file) {
                say('Choose a file first.');
                return;
            }

            button.disabled = true;
            say('Starting…');

            let location;

            try {
                const created = await create(file);

                if (!created.ok) {
                    say('Refused (' + created.status + '). ' + (await created.text()).slice(0, 200));
                    button.disabled = false;
                    return;
                }

                location = created.headers.get('Location');
            } catch (error) {
                say('Network error: ' + error.message);
                button.disabled = false;
                return;
            }

            let offset = 0;

            while (offset < file.size) {
                const chunk = file.slice(offset, offset + CHUNK);
                let response;

                try {
                    response = await append(location, offset, chunk);
                } catch (error) {
                    say('Connection lost at ' + offset + ' bytes. Press again to resume.');
                    button.disabled = false;
                    return;
                }

                // 409 means the server holds a different offset — take its answer
                // rather than guessing, which is the whole point of the protocol.
                if (response.status === 409) {
                    const held = Number(response.headers.get('Upload-Offset'));

                    if (!Number.isFinite(held) || held === offset) {
                        say('The upload is out of step. Reload and try again.');
                        button.disabled = false;
                        return;
                    }

                    offset = held;
                    continue;
                }

                if (!response.ok) {
                    say('Refused (' + response.status + '). ' + (await response.text()).slice(0, 300));
                    button.disabled = false;
                    return;
                }

                const next = Number(response.headers.get('Upload-Offset'));
                offset = Number.isFinite(next) ? next : offset + chunk.size;
                say(Math.min(100, Math.round((offset / file.size) * 100)) + '% — ' + offset + ' of ' + file.size + ' bytes');
            }

            say('Stored. Reloading…');
            window.location.reload();
        });
    })();
</script>
