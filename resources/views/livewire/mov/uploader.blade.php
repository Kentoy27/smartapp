{{-- UPLOAD MOV: the staff member's Means of Verification checklist.

     The rail down the left is the page's navigation: it carries the progress
     and lists every Part → Category as links — the same sections the
     sidebar's tree offers, so a section is reached the same way from either
     place and the URL always says which one is on screen. It has no "All"
     entry: the whole checklist is where the page opens, the "Show all"
     control above the list is how you come back to it, and the Parts
     themselves live in the sidebar's Upload MOV dropdown. The
     checklist itself is Part → Category → MOV → pictures, with parts and
     categories collapsing and a MOV left as a flat card, so opening a
     category never produces another level to think about. Each card is
     addressable by its requirement id (`/movs?mov=101`), which is how the
     sidebar's tree jumps straight to one MOV: the page opens that MOV's
     parents, scrolls to it and marks it. A MOV takes as many pictures as the
     evidence needs — each one listed with its own review state.

     Everything on the page comes from the checklist tables (config/mov.php via
     `php artisan mov:sync`), never from this template. --}}
<div class="mov-page" wire:poll.30s>
    {{-- SUCCESS TOAST: the component sets the message and clears it once the
         sentinel below has popped the SweetAlert, so a poll never re-fires it. --}}
    @if ($successMessage)
        <div
            wire:key="mov-alert-{{ md5($successMessage) }}"
            x-data
            x-init="
                if (window.smartAlert) {
                    smartAlert.success({{ \Illuminate\Support\Js::from($successMessage) }});
                }
                $wire.clearSuccess();
            "
            class="success-alert-sentinel"
            aria-hidden="true"
        ></div>
    @endif


    {{-- ONE MOV AT A TIME. The page carries no rail, no header and no list:
         a MOV is chosen from the sidebar (or by arriving on ?mov=N) and this
         renders that MOV alone — its table of pictures and the row that adds
         to them. Without a MOV chosen there is nothing to show, so nothing
         is rendered. --}}
    @if ($focused !== null)
    <div class="mov-layout mov-layout--single">
        <div class="mov-main">
            {{-- THE MOV, AND NOTHING ELSE. There is no search box and no
                 category scope line here any more: one MOV is on screen, so
                 a filter over the whole checklist would describe something
                 that is not what is in front of the reader. --}}
            @if ($mov = $this->focusedMov())
                {{-- ONE MOV, STANDALONE. No Part or category disclosure — the
                     reader chose this MOV from the sidebar, so they get this one
                     card and its own table and upload row, separated from every
                     other MOV on the checklist. --}}
                <div
                    class="mov-card"
                    id="mov-{{ $focused }}"
                    data-mov-focus
                    wire:key="mov-card-{{ $focused }}"
                >
                    <div class="mov-card-head">
                        <span class="mov-label">{{ $mov['label'] }}</span>

                        @if ($mov['required'])
                            <span class="mov-required" title="Required for a complete submission">Required</span>
                        @else
                            <span class="mov-optional" title="Optional — not counted in your progress">Optional</span>
                        @endif
                    </div>

                    <p class="mov-title">{{ $mov['title'] }}</p>

                    @if ($mov['description'] !== '')
                        <p class="mov-description">{{ $mov['description'] }}</p>
                    @endif

{{-- THIS MOV'S OWN TABLE: its pictures, one row each, and the
                                                         upload row that adds to them. It is separated from every
                                                         other MOV on the checklist — what is on file for one is
                                                         never mixed with another's, and uploading never needs a
                                                         click first. --}}
                    <table class="mov-picture-table" aria-label="Pictures for {{ $mov['trail'] }}">
                        <caption class="mov-picture-caption">
                            Pictures attached to {{ $mov['trail'] }}
                        </caption>

                        <thead>
                            <tr>
                                <th scope="col">Picture</th>
                                <th scope="col">Uploaded</th>
                                <th scope="col">Status</th>
                                <th scope="col">Actions</th>
                            </tr>
                        </thead>

                        <tbody>
                            @if ($mov['count'] > 0)
                                <tr class="mov-summary-row">
                                    <td colspan="4">{{ $mov['summary'] }}</td>
                                </tr>
                            @endif

                            @forelse ($mov['pictures'] as $picture)
                                <tr
                                    class="mov-picture {{ $picture['status'] === \App\Models\UserMov::STATUS_RETURNED ? 'is-returned' : '' }}"
                                    wire:key="mov-picture-{{ $picture['id'] }}"
                                >
                                    <td class="mov-picture__what">
                                        <span class="mov-picture__thumb">
                                            @if ($picture['has_file'])
                                                <img
                                                    class="mov-thumb"
                                                    src="{{ route('mov.view', $picture['id']) }}"
                                                    alt="Preview of {{ $picture['name'] }}"
                                                    loading="lazy"
                                                >
                                            @else
                                                <span class="mov-file-icon" aria-hidden="true">
                                                    <x-icon name="image" :size="18" />
                                                </span>
                                            @endif
                                        </span>

                                        <span class="mov-picture__meta">
                                            <span class="mov-file-name" title="{{ $picture['name'] }}">{{ $picture['name'] }}</span>

                                            @if ($picture['remarks'] !== null && trim($picture['remarks']) !== '')
                                                <span class="mov-file-remarks"><strong>Remarks:</strong> {{ $picture['remarks'] }}</span>
                                            @endif

                                            @if (! $picture['has_file'])
                                                <span class="mov-file-missing">The stored picture is missing from disk — upload it again.</span>
                                            @endif
                                        </span>
                                    </td>

                                    <td class="mov-picture__when">
                                        {{ $picture['uploaded_at'] }}
                                        <span class="mov-file-size">{{ $picture['size'] }}</span>
                                    </td>

                                    <td class="mov-picture__status">
                                        <span class="badge {{ $picture['status_class'] }}">{{ $picture['status_label'] }}</span>

                                        @if ($picture['reviewer'] !== null && $picture['status'] !== \App\Models\UserMov::STATUS_UPLOADED)
                                            <span class="mov-file-reviewer">Reviewed by: {{ $picture['reviewer'] }}</span>
                                        @endif
                                    </td>

                                    <td class="mov-picture__actions">
                                        @if ($removingId === $picture['id'])
                                            <span class="mov-remove-confirm">
                                                <span>Remove this picture?</span>
                                                <button type="button" class="btn-danger btn-danger--small" wire:click="confirmRemove({{ $picture['id'] }})">Remove</button>
                                                <button type="button" class="btn-ghost btn-ghost--small" wire:click="cancelRemove">Keep</button>
                                            </span>
                                        @else
                                            @if ($picture['has_file'])
                                                <a class="btn-ghost btn-ghost--small" href="{{ route('mov.view', $picture['id']) }}" target="_blank" rel="noopener">
                                                    <x-icon name="eye" :size="14" />
                                                    <span>View</span>
                                                </a>

                                                <a class="btn-ghost btn-ghost--small" href="{{ route('mov.download', $picture['id']) }}">
                                                    <x-icon name="download" :size="14" />
                                                    <span>Download</span>
                                                </a>
                                            @endif

                                            <button
                                                type="button"
                                                class="btn-ghost btn-ghost--small mov-remove-btn"
                                                wire:click="$set('removingId', {{ $picture['id'] }})"
                                                title="Remove {{ $picture['name'] }} from {{ $mov['trail'] }}"
                                            >
                                                <x-icon name="trash" :size="14" />
                                                <span>Remove</span>
                                            </button>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr class="mov-picture-empty">
                                    <td colspan="4">Nothing attached to {{ $mov['trail'] }} yet.</td>
                                </tr>
                            @endforelse

                            {{-- THE UPLOAD ROW: this MOV's own way of adding to its
                                 table. It names where the picture lands, and a file
                                 chosen here is attached to this MOV and no other. --}}
                            <tr class="mov-upload-row">
                                <td colspan="4">
                                    <div class="mov-upload-row__head">
                                        <span class="mov-upload-row__title">Upload your photos here</span>
                                        <span class="mov-upload-row__trail">{{ $mov['trail'] }}</span>
                                    </div>

                                    <label class="mov-file-input">
                                        <span class="mov-file-input__label">Picture file</span>
                                        <input
                                            type="file"
                                            wire:model="document"
                                            accept=".jpg,.jpeg,.png,.webp"
                                        >
                                        <span class="mov-file-input__spec">
                                            Accepted formats {{ $this->acceptedExtensions() }}
                                            · Maximum {{ number_format($this->uploadLimitMegabytes(), 1) }} MB per picture
                                        </span>
                                    </label>

                                    @if ($uploadFailedFor === $mov['id'] && $errors->has('document'))
                                        <div class="error-text">{{ $errors->first('document') }}</div>
                                    @endif

                                    <p class="mov-upload-row__note">
                                        A picture may be attached more than once. Uploading adds this
                                        file to {{ $mov['trail'] }} and replaces nothing already attached.
                                    </p>

                                    <div class="mov-upload-actions">
                                        <button
                                            type="button"
                                            class="btn-primary btn-primary--small"
                                            wire:click="upload({{ $mov['id'] }})"
                                            wire:loading.attr="disabled"
                                            wire:target="upload({{ $mov['id'] }})"
                                        >
                                            <span wire:loading.remove wire:target="upload({{ $mov['id'] }})">Upload Picture</span>
                                            <span wire:loading wire:target="upload({{ $mov['id'] }})">Uploading…</span>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            @else
                <div class="card mov-no-results">
                    <p class="card-title">No MOV requirements yet</p>
                    <p class="card-text">The checklist is built from <code>config/mov.php</code> — run <code>php artisan mov:sync</code> to load it.</p>
                </div>
            @endif

            {{-- ARRIVING ON ONE MOV (`/movs?mov=101`): scroll to it and mark
                 it, then let the mark fade. This is a one-off on arrival —
                 the class is added and removed by the browser, so a later
                 re-render (a poll, an upload) does not re-scroll the page or
                 re-flash the card. Everything on the card is the ordinary
                 one: upload, view, download and remove are untouched. --}}
            @if ($focused !== null)
                <div
                    class="mov-focus-script"
                    aria-hidden="true"
                    wire:key="mov-focus-script"
                    x-data
                    x-init="
                        setTimeout(function () {
                            var card = document.getElementById('mov-{{ $focused }}');

                            if (! card) {
                                return;
                            }

                            card.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            card.classList.add('is-mov-focused');

                            setTimeout(function () {
                                card.classList.remove('is-mov-focused');
                            }, 5000);
                        }, 150);
                    "
                ></div>
            @endif
        </div>
    </div>
    @endif
</div>
