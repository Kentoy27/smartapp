{{-- Numbered MOV evidence shown over the OPCRF review modal. --}}
@if ($reviewMovNumber !== null && $this->reviewSubmission() !== null)
    <div
        class="modal-backdrop is-open opcrf-mov-evidence-backdrop"
        role="presentation"
        wire:key="review-mov-evidence-{{ $this->reviewSubmission()->id }}-{{ $reviewMovNumber }}"
        @click.self="$wire.closeMovEvidence()"
    >
        <div class="modal modal--wide opcrf-mov-evidence-modal" role="dialog" aria-modal="true" aria-labelledby="reviewMovEvidenceTitle" @click.stop>
            <div class="modal-head">
                <div>
                    <h2 id="reviewMovEvidenceTitle">MOV {{ $reviewMovNumber }} Evidence</h2>
                    <p class="opcrf-mov-evidence-subtitle">
                        Uploaded by {{ $this->reviewSubmission()->employee_name }}
                        · {{ $this->reviewSubmission()->reference }}
                    </p>
                </div>
                <button type="button" class="modal-close" wire:click="closeMovEvidence" aria-label="Close" title="Close">×</button>
            </div>

            <div class="modal-form opcrf-mov-evidence-body">
                @if ($this->reviewNumberedMovRequirements->isEmpty())
                    <div class="opcrf-mov-evidence-empty">
                        No uploaded evidence for MOV {{ $reviewMovNumber }} was found for this staff member.
                    </div>
                @else
                    @foreach ($this->reviewNumberedMovRequirements as $requirement)
                        <section class="opcrf-mov-requirement" wire:key="review-mov-requirement-{{ $requirement->id }}">
                            <div class="opcrf-mov-requirement-head">
                                <div>
                                    <div class="opcrf-mov-requirement-trail">{{ $requirement->category?->trail() }}</div>
                                    <h3>{{ $requirement->title }}</h3>
                                    @if (trim((string) $requirement->description) !== '')
                                        <p>{{ $requirement->description }}</p>
                                    @endif
                                </div>
                                <span class="badge badge-muted">{{ $requirement->label() }}</span>
                            </div>

                            <div class="opcrf-mov-evidence-grid">
                                @foreach ($requirement->userMovs as $evidence)
                                    @php
                                        $isImage = in_array(
                                            strtolower(pathinfo($evidence->original_name, PATHINFO_EXTENSION)),
                                            ['jpg', 'jpeg', 'png', 'gif', 'webp'],
                                            true
                                        );
                                    @endphp
                                    <article class="opcrf-mov-evidence-card" wire:key="review-mov-file-{{ $evidence->id }}">
                                        @if ($isImage && $evidence->fileExists())
                                            <a
                                                class="opcrf-mov-evidence-preview"
                                                href="{{ route('mov.view', $evidence) }}"
                                                target="_blank"
                                                rel="noopener"
                                                aria-label="Open photo {{ $evidence->original_name }}"
                                            >
                                                <img src="{{ route('mov.view', $evidence) }}" alt="Uploaded MOV photo: {{ $evidence->original_name }}">
                                            </a>
                                        @else
                                            <div class="opcrf-mov-evidence-file">
                                                <x-icon name="file-text" :size="30" />
                                                <span>{{ strtoupper(pathinfo($evidence->original_name, PATHINFO_EXTENSION)) ?: 'FILE' }}</span>
                                            </div>
                                        @endif

                                        <div class="opcrf-mov-evidence-meta">
                                            <strong>{{ $evidence->original_name }}</strong>
                                            <span>Added: {{ ($evidence->uploaded_at ?? $evidence->created_at)?->format('M j, Y g:i A') ?? 'Date unavailable' }}</span>
                                            <span>{{ $evidence->human_size }}</span>
                                            @if (! $evidence->fileExists())
                                                <span class="error-text">The uploaded file is unavailable.</span>
                                            @endif
                                        </div>

                                        @if ($evidence->fileExists())
                                            <iframe
                                                class="opcrf-mov-evidence-frame"
                                                src="{{ route('mov.preview', $evidence) }}"
                                                title="Preview of {{ $evidence->original_name }}"
                                                loading="lazy"
                                            ></iframe>
                                        @endif

                                        <div class="opcrf-mov-evidence-actions">
                                            <span class="badge {{ $evidence->statusBadgeClass() }}">{{ $evidence->statusLabel() }}</span>
                                            @if ($evidence->fileExists())
                                                <a class="btn-ghost btn-ghost--small" href="{{ route('mov.download', $evidence) }}">
                                                    <x-icon name="download" :size="14" />
                                                    <span>Download file</span>
                                                </a>
                                            @endif
                                            @if ($evidence->status === \App\Models\UserMov::STATUS_ACCEPTED)
                                                <span class="opcrf-mov-evidence-verified">
                                                    Confirmed by {{ $evidence->reviewer?->username ?? 'Superadmin' }}
                                                    @if ($evidence->reviewed_at)
                                                        · {{ $evidence->reviewed_at->format('M j, Y g:i A') }}
                                                    @endif
                                                </span>
                                            @elseif ($this->reviewSubmission()->isAssignedTo(auth()->user()))
                                                <button
                                                    type="button"
                                                    class="btn-primary btn-primary--small"
                                                    wire:click="acceptMovEvidence({{ $evidence->id }})"
                                                    wire:loading.attr="disabled"
                                                    wire:target="acceptMovEvidence({{ $evidence->id }})"
                                                    @disabled(! $evidence->fileExists())
                                                >
                                                    <x-icon name="check" :size="14" />
                                                    <span>Validated</span>
                                                </button>
                                            @endif
                                        </div>
                                    </article>
                                @endforeach
                            </div>
                        </section>
                    @endforeach
                @endif
            </div>
        </div>
    </div>
@endif
