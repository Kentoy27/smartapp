<div class="dashboard-summary" wire:poll.15s>

    {{-- OPCR tools: staff-facing only. The card's lock state follows the
         submission's fate live: submitting locks it, a return-for-revision
         unlocks it — no reload needed.

         The superadmin's District List is deliberately NOT here: it has its
         own page behind the sidebar's "Districts & Schools" item. --}}
    @if (! $isSuperAdmin)
        @if ($opcrfLocked)
            {{-- LOCKED CARD: an OPCRF has already been submitted, so the
                 cycle is complete — no more downloads or uploads from the
                 dashboard. --}}
            <div class="card opcr-card-locked" wire:key="opcr-card-locked">
                <div class="card-head">
                    <div class="card-title">OPCRF Template</div>
                    <a href="{{ route('opcrf.index') }}" class="card-link">Go to Opcrf →</a>
                </div>
                <div class="opcr-template-row">
                    <span class="opcr-file-badge opcr-file-badge--locked" aria-hidden="true">
                        <x-icon name="lock" :size="22" />
                    </span>
                    <div class="opcr-file-meta">
                        <span class="opcr-file-name">OPCRF submitted — locked</span>
                        @if ($opcrfSubmission)
                            {{-- The reference is the one thing the staff member
                                 quotes when they ask about this form, so it
                                 stays on the card after the lock. --}}
                            <span class="opcr-file-sub">
                                {{ $opcrfSubmission->reference }}
                                &middot; {{ $opcrfSubmission->statusLabelFor(auth()->user()) }}
                                @if ($opcrfSubmission->submitted_at)
                                    &middot; {{ $opcrfSubmission->submitted_at->format('M j, Y') }}
                                @endif
                            </span>
                        @endif
                        <span class="opcr-file-sub">Your OPCRF is in. Nothing more to download or upload here.</span>
                        <span class="opcr-file-sub">Track its status on the <a href="{{ route('opcrf.index') }}">Opcrf page</a>.</span>
                    </div>
                    <span class="opcr-locked-pill">
                        <x-icon name="lock" :size="14" />
                        <span>Locked</span>
                    </span>
                </div>
            </div>
        @else
            <div class="card" wire:key="opcr-card-open">
                <div class="card-head">
                    <div class="card-title">OPCRF Template</div>
                    <a href="{{ route('opcrf.index') }}" class="card-link">Go to Opcrf →</a>
                </div>
                <div class="opcr-template-row">
                    <span class="opcr-file-badge" aria-hidden="true">
                        <x-icon name="file-spreadsheet" :size="22" />
                    </span>
                    <div class="opcr-file-meta">
                        @if ($opcrfTemplate)
                            {{-- GENERATED: the personalized file this account
                                 actually downloaded, by name and date — not a
                                 generic "the template" line. --}}
                            <span class="opcr-file-name">{{ $opcrfTemplate->filename }}</span>
                            <span class="opcr-file-sub">
                                Generated {{ $opcrfTemplate->created_at->format('M j, Y g:i A') }}
                                &middot; Template v{{ $opcrfTemplate->version }}
                                &middot; Filled in with your name, position and school
                            </span>
                        @else
                            <span class="opcr-file-name">Your personalized OPCRF</span>
                            <span class="opcr-file-sub">Download the template and it arrives already filled in with your name, position, school and division.</span>
                            @if ($opcrfPartOne['full_open'])
                                <span class="opcr-file-sub">The complete form — all four parts — is available now.</span>
                            @else
                                <span class="opcr-file-sub">Part I only, available now. Parts II–IV become available {{ $opcrfPartOne['full_open_date'] ? 'on '.$opcrfPartOne['full_open_date'].', ' : 'at ' }}{{ $opcrfPartOne['label'] }}</span>
                            @endif
                        @endif
                    </div>
                    <a href="{{ route('opcrf.template') }}" class="opcr-download-btn" download>
                        <x-icon name="download" :size="16" />
                        <span>{{ $opcrfTemplate ? 'Download Again' : 'Download OPCRF Template' }}</span>
                    </a>

                    {{-- UPLOAD: the OpcrfUpload component lives on the page
                         beside this one, NOT inside it. It is a component with
                         modal state and a temporary file upload, and this
                         container polls every 15s — a child of a polling
                         component is re-mounted on every tick, which threw
                         the review modal away seconds after it appeared and
                         sent the user back to the upload window. --}}
                </div>

                {{-- STATUS: where this account's OPCRF actually stands —
                     template generated, submitted, or back for revision with
                     its version history. Nothing here is client-side state:
                     it all comes from the recorded template and submission. --}}
                @if ($opcrfSubmission)
                    <div class="opcr-status" wire:key="opcr-status">
                        <div class="opcr-status-head">
                            <span class="opcr-status-title">OPCRF Submission</span>
                            <span class="badge {{ $opcrfSubmission->statusBadgeClass() }}">
                                {{ $opcrfSubmission->statusLabelFor(auth()->user()) }}
                            </span>
                        </div>

                        <dl class="opcr-status-grid">
                            <div class="opcr-status-row">
                                <dt>Reference</dt>
                                <dd>{{ $opcrfSubmission->reference ?? '—' }}</dd>
                            </div>
                            @if ($opcrfSubmission->file_original_name)
                                <div class="opcr-status-row">
                                    <dt>File</dt>
                                    <dd>{{ $opcrfSubmission->file_original_name }}</dd>
                                </div>
                            @endif
                            <div class="opcr-status-row">
                                <dt>Submitted</dt>
                                <dd>{{ optional($opcrfSubmission->submitted_at)->format('M j, Y g:i A') ?? '—' }}</dd>
                            </div>
                            <div class="opcr-status-row">
                                <dt>Template version</dt>
                                <dd>{{ $opcrfSubmission->template?->version ?? '—' }}</dd>
                            </div>
                            @if ($opcrfSubmission->latestReview?->remarks)
                                <div class="opcr-status-row">
                                    <dt>Reviewer remarks</dt>
                                    <dd>{{ $opcrfSubmission->latestReview->remarks }}</dd>
                                </div>
                            @endif
                        </dl>

                        {{-- Every submitted copy is kept: the current one
                             plus each version it replaced. --}}
                        @if ($opcrfSubmission->versions->isNotEmpty())
                            <div class="opcr-status-versions">
                                <span class="opcr-status-versions-label">Submitted versions</span>
                                <ul>
                                    @foreach ($opcrfSubmission->versions as $version)
                                        <li>
                                            <strong>{{ $version->label() }}</strong>
                                            <span>{{ optional($version->submitted_at)->format('M j, Y') ?? '—' }}</span>
                                            @if ($version->status)
                                                <span class="badge {{ $version->status === \App\Models\OpcrfSubmission::STATUS_RETURNED ? 'badge--warn' : 'badge-muted' }}">
                                                    {{ \App\Models\OpcrfSubmission::statusLabel($version->status) }}
                                                </span>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        <a href="{{ route('opcrf.index') }}" class="opcr-status-link">
                            Track this submission on the Opcrf page →
                        </a>
                    </div>
                @elseif ($opcrfTemplate)
                    {{-- Generated but not yet submitted: spell out the
                         next step rather than leaving the card looking
                         finished. --}}
                    <div class="opcr-status" wire:key="opcr-status-empty">
                        <div class="opcr-status-head">
                            <span class="opcr-status-title">OPCRF Submission</span>
                            <span class="badge badge-muted">Not submitted</span>
                        </div>
                        <p class="opcr-status-hint">
                            Complete the template you downloaded, then upload it here.
                            Any name will do — your reviewer is told if the file
                            does not match your account, and you can submit either way.
                        </p>
                    </div>
                @endif
            </div>
        @endif
    @endif

</div>
