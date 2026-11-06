<div class="aip-upload">
    <button type="button" class="btn-primary aip-upload__trigger" wire:click="openModal">
        <x-icon name="upload" :size="16" />
        <span>Upload AIP</span>
    </button>

    @if ($showModal)
        <div
            class="modal-backdrop is-open"
            x-data
            @keydown.escape.window="$wire.closeModal()"
            @click.self="$wire.closeModal()"
            role="presentation"
        >
            <section class="modal modal--wide aip-upload-modal" role="dialog" aria-modal="true" aria-labelledby="aipUploadTitle" @click.stop>
                <div class="modal-head">
                    <div>
                        <h2 id="aipUploadTitle">Upload AIP</h2>
                        <p>Submit your AIP for review when ready, even if some fields are still incomplete.</p>
                    </div>
                    <button type="button" class="modal-close" wire:click="closeModal" aria-label="Close" title="Close">×</button>
                </div>

                <div class="modal-form aip-upload-modal__body">
                    <div class="aip-upload-modal__file">
                        <label for="aip-upload-file">AIP Word document <span class="aip-field__optional">DOCX · up to {{ number_format((int) config('aip.max_kb', 10240) / 1024, 1) }} MB</span></label>
                        <input
                            id="aip-upload-file"
                            type="file"
                            accept=".docx"
                            wire:model="aipFile"
                            aria-describedby="aip-upload-hint"
                        >
                        <span class="field-hint" id="aip-upload-hint">The system checks the AIP table and extracts its year and rows for your preview.</span>
                        @error('aipFile') <div class="error-text" role="alert">{{ $message }}</div> @enderror
                        <div class="aip-upload-modal__loading" wire:loading wire:target="aipFile">Uploading and analyzing Word document…</div>
                    </div>

                    @if ($analysisMessage)
                        @php
                            $visiblePreviewRows = collect($previewRows)->filter(function (array $row): bool {
                                $objective = trim((string) ($row['strategic_objective'] ?? ''));
                                $hasObjective = $objective !== ''
                                    && preg_match('/^SO\s+\d+(?:\.\d+)*$/i', $objective) !== 1;

                                foreach (['strategy', 'ppa', 'resources', 'budget', 'persons_responsible', 'timeline'] as $field) {
                                    if (trim((string) ($row[$field] ?? '')) !== '') {
                                        return true;
                                    }
                                }

                                return $hasObjective;
                            })->values();
                            $pillarGroups = $visiblePreviewRows->groupBy(fn (array $row): string => trim((string) ($row['pillar'] ?? '')) ?: 'Other AIP items');
                        @endphp

                        <div class="aip-upload-analysis" role="status">
                            <span class="badge badge--info">Ready to submit</span>
                            <span>FY {{ $detectedYear }} · {{ $visiblePreviewRows->count() }} completed {{ \Illuminate\Support\Str::plural('item', $visiblePreviewRows->count()) }}</span>
                            <span class="aip-upload-analysis__file">{{ $originalName }}</span>
                        </div>

                        <div class="aip-upload-preview">
                            <div class="aip-upload-preview__heading">
                                <div>
                                    <h3>AIP content preview</h3>
                                    <p>Review the information found in your document. Blank template rows are hidden.</p>
                                </div>
                            </div>
                            @if ($visiblePreviewRows->isEmpty())
                                <div class="aip-upload-preview__empty">
                                    <span class="aip-upload-preview__empty-icon" aria-hidden="true">✓</span>
                                    <strong>AIP template detected</strong>
                                    <p>No completed activity details were found yet. You can still submit this plan for review.</p>
                                </div>
                            @else
                                <div class="aip-upload-preview__content">
                                    @foreach ($pillarGroups as $pillar => $rows)
                                        <section class="aip-upload-preview__pillar" aria-label="{{ $pillar }}">
                                            <div class="aip-upload-preview__pillar-heading">
                                                <span>{{ $pillar }}</span>
                                                <span>{{ $rows->count() }} {{ \Illuminate\Support\Str::plural('item', $rows->count()) }}</span>
                                            </div>

                                            <div class="aip-upload-preview__items">
                                                @foreach ($rows as $index => $row)
                                                    <article class="aip-upload-preview__item" wire:key="aip-upload-preview-{{ $pillar }}-{{ $index }}">
                                                        <div class="aip-upload-preview__item-heading">
                                                            <span class="aip-upload-preview__item-number">{{ $loop->iteration }}</span>
                                                            <div>
                                                                <span class="aip-upload-preview__item-label">{{ $row['ppa'] ? 'Program / project / activity' : 'Strategic objective' }}</span>
                                                                <h4>{{ $row['ppa'] ?: $row['strategic_objective'] ?: 'AIP item' }}</h4>
                                                            </div>
                                                        </div>

                                                        <dl class="aip-upload-preview__details">
                                                            @foreach ([
                                                                'Strategic objective' => 'strategic_objective',
                                                                'Strategy' => 'strategy',
                                                                'Updated programs / PPAs' => 'ppa',
                                                                'Resources' => 'resources',
                                                                'Budget' => 'budget',
                                                                'Persons responsible' => 'persons_responsible',
                                                                'Timeline' => 'timeline',
                                                            ] as $label => $field)
                                                                @if (trim((string) ($row[$field] ?? '')) !== '' && ! ($field === 'ppa' && $row['ppa']))
                                                                    <div>
                                                                        <dt>{{ $label }}</dt>
                                                                        <dd>{{ $row[$field] }}</dd>
                                                                    </div>
                                                                @endif
                                                            @endforeach
                                                        </dl>
                                                    </article>
                                                @endforeach
                                            </div>
                                        </section>
                                    @endforeach
                                </div>
                            @endif
                        </div>

                        <p class="aip-upload-modal__notice">
                            @if ($isReplacingReturned)
                                This upload will replace the returned plan and send the revision to <strong>Pending Review</strong>.
                            @else
                                Submitting sends this AIP to <strong>Pending Review</strong>. An existing draft can be updated; plans already under review or approved cannot be replaced.
                            @endif
                        </p>
                    @endif

                    <div class="modal-actions aip-upload-modal__actions">
                        <button type="button" class="btn-ghost" wire:click="closeModal">Cancel</button>
                        <button
                            type="button"
                            class="btn-primary"
                            wire:click="submit"
                            wire:loading.attr="disabled"
                            wire:target="submit"
                            @disabled(! $analysisMessage || $errors->has('aipFile'))
                        >
                            <span wire:loading.remove wire:target="submit">{{ $isReplacingReturned ? 'Replace and resubmit AIP' : 'Submit AIP for review' }}</span>
                            <span wire:loading wire:target="submit">Submitting…</span>
                        </button>
                    </div>
                </div>
            </section>
        </div>
    @endif
</div>
