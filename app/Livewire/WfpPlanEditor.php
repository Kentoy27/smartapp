<?php

namespace App\Livewire;

use App\Models\WfpItem;
use App\Models\WfpPlan;
use App\Support\WfpGenerator;
use App\Support\WfpTemplate;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The generated-WFP editor: a grouped preview of the plan's lines, with the
 * WFP-only fields editable — filling one in clears its review flag.
 *
 * The plan can be regenerated from the MEM at any time (the SH's WFP-only
 * entries survive), approved, and downloaded as a patched copy of the official
 * template. An out-of-date banner shows, per source matrix, when a MEM has
 * moved on since the plan was generated.
 */
#[Title('Work and Financial Plan — SmartApp')]
class WfpPlanEditor extends Component
{
    #[Locked]
    public int $planId = 0;

    /** Editable WFP-only fields, keyed by WFP item id. */
    public array $items = [];

    public ?string $successMessage = null;

    public ?string $errorMessage = null;

    public function mount(?WfpPlan $plan = null): void
    {
        abort_unless($plan !== null && $plan->managedBy(Auth::user()), 404);

        $this->planId = $plan->id;
        $this->loadItems($plan);
    }

    private function guard(): WfpPlan
    {
        $plan = WfpPlan::findOrFail($this->planId);
        abort_unless($plan->managedBy(Auth::user()), 404);

        return $plan;
    }

    private function loadItems(WfpPlan $plan): void
    {
        $this->items = $plan->items()
            ->get()
            ->mapWithKeys(fn (WfpItem $item): array => [$item->id => [
                'major_output' => (string) ($item->major_output ?? ''),
                'performance_indicator' => (string) ($item->performance_indicator ?? ''),
                'funding_source' => (string) ($item->funding_source ?? ''),
                'organizational_classification' => (string) ($item->organizational_classification ?? ''),
                'activity_movs' => (string) ($item->activity_movs ?? ''),
            ]])
            ->all();
    }

    /* --------------------------------------------------------------- rules */

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        return [
            'items' => ['array'],
            'items.*.major_output' => ['nullable', 'string', 'max:2000'],
            'items.*.performance_indicator' => ['nullable', 'string', 'max:2000'],
            'items.*.funding_source' => ['nullable', 'string', 'max:255'],
            'items.*.organizational_classification' => ['nullable', 'string', 'max:255'],
            'items.*.activity_movs' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /* -------------------------------------------------------------- actions */

    public function save(): void
    {
        $plan = $this->guard();

        $validated = $this->validate()['items'] ?? [];

        DB::transaction(function () use ($plan, $validated): void {
            foreach ($plan->items()->get() as $item) {
                $row = $validated[$item->id] ?? null;

                if ($row === null) {
                    continue;
                }

                $item->major_output = self::nullIfBlank($row['major_output'] ?? null);
                $item->performance_indicator = self::nullIfBlank($row['performance_indicator'] ?? null);
                $item->funding_source = self::nullIfBlank($row['funding_source'] ?? null);
                $item->organizational_classification = self::nullIfBlank($row['organizational_classification'] ?? null);
                $item->activity_movs = self::nullIfBlank($row['activity_movs'] ?? null);
                $item->refreshFlags();
                $item->save();
            }
        });

        $this->loadItems($plan->fresh());

        $this->successMessage = 'Plan updated.';
    }

    public function regenerate(): void
    {
        $plan = $this->guard();

        $result = WfpGenerator::generate($plan);

        $this->loadItems($plan->fresh());

        $this->successMessage = sprintf(
            'Regenerated — %d added, %d updated, %d removed.',
            $result['created'],
            $result['updated'],
            $result['removed'],
        );
    }

    public function approve(): void
    {
        $plan = $this->guard();

        $plan->status = WfpPlan::STATUS_FINALIZED;
        $plan->approved_by = Auth::id();
        $plan->approved_at = now();
        $plan->save();

        $this->successMessage = 'Plan approved and finalized.';
    }

    public function clearSuccess(): void
    {
        $this->successMessage = null;
    }

    public function clearError(): void
    {
        $this->errorMessage = null;
    }

    /* ----------------------------------------------------------- computed */

    #[Computed]
    public function plan(): WfpPlan
    {
        return WfpPlan::with('school')->findOrFail($this->planId);
    }

    #[Computed]
    public function isOutOfDate(): bool
    {
        return $this->plan->isOutOfDate();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function staleSources(): array
    {
        return $this->plan->staleSources();
    }

    #[Computed]
    public function annualTotal(): float
    {
        return $this->plan->annualFinancialTotal();
    }

    #[Computed]
    public function flaggedCount(): int
    {
        return $this->plan->flaggedCount();
    }

    /**
     * The plan's items grouped by `group_key`, preserving position order, so
     * the preview reads as one WFP row per grouping.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function groups(): array
    {
        $groups = [];

        foreach ($this->plan->items()->with('sourceMatrix')->get() as $item) {
            /** @var WfpItem $item */
            $key = (string) $item->group_key;

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'key' => $key,
                    'section' => $item->section,
                    'objective' => $item->objective,
                    'program_activity' => $item->program_activity,
                    'account_title' => $item->account_title,
                    'account_code' => $item->account_code,
                    'items' => [],
                ];
            }

            $groups[$key]['items'][] = $item;
        }

        return array_values($groups);
    }

    private static function nullIfBlank(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    public function render()
    {
        return view('livewire.wfp.plan-editor', [
            'plan' => $this->plan,
            'templateName' => WfpTemplate::fileName(),
        ]);
    }
}
