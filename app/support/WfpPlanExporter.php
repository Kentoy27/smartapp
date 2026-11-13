<?php

namespace App\Support;

use App\Models\MonthlyExpenditureMatrix;
use App\Models\SchoolAllotment;
use App\Models\User;
use App\Models\WfpPlan;
use App\Models\WfpReportCheck;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * A plan's WFP as a workbook: the official template patched with the plan's
 * rows and stamped with the school's name.
 *
 * One implementation, two entry points — the plan's own download (the plan
 * editor's `/wfp/plans/{plan}/export`) and the "Generate and Download WFP"
 * button, which prefers the WFP the school's Monthly Expenditure Matrices add
 * up to and only falls back to the blank account-code form when the school has
 * no matrix data yet. Both must hand back the same workbook, so neither owns
 * the patching.
 */
final class WfpPlanExporter
{
    public const CONTENT_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    private const SIGNATURE_STAGES = ['prepared', 'bookkeeper', 'budget', 'superadmin'];

    /**
     * The box an e-signature is stamped in, in pixels: the width a signature
     * needs to be read and the height of the form's signing band.
     *
     * The form's own sign-off columns are narrow (the Bookkeeper's is six
     * characters wide) and its rows are short, so a signature stretched to
     * the cell it lands in is either a flat line or a smudge. A box of a
     * fixed size is the Per Line Item report's, so the two forms carry their
     * signatures alike — and the box may reach into the empty columns beside
     * the block, which is where the room comes from.
     */
    private const SIGNATURE_WIDTH_PX = 90;

    private const SIGNATURE_HEIGHT_PX = 60;

    /**
     * The plan as workbook bytes.
     *
     * The plan is not consulted for its "shape": the template's own layout
     * decides whether the targets are written per month or into a single annual
     * column (see WfpTemplateFiller), so a plan stays portable across template
     * revisions.
     *
     * The named sign-off blocks are sourced from the existing workflow records:
     * the Bookkeeper's report check, the Budget certification, and the final
     * Superadmin approval. Only the account attached to each valid record can
     * contribute its registered profile signature. With
     * The School Head's signature comes from the authenticated preparer; the
     * other signatures require their existing, role-appropriate workflow
     * records. With `$includeSignatures = false` every e-signature block is
     * left blank; no workflow record or signature is changed.
     *
     * @throws RuntimeException when the template cannot be read
     */
    public static function bytes(
        WfpPlan $plan,
        bool $includeSignatures = true,
        ?User $preparedBy = null,
    ): string
    {
        $layout = WfpTemplate::inspect();

        $bytes = WfpTemplateFiller::fill(
            WfpTemplate::bytes(),
            WfpGenerator::buildExportRows($plan, ($layout['months'] ?? []) !== []),
            $layout,
        );

        // The form identifies itself: the plan's school and planning year go
        // into the template's own title block.
        $bytes = WfpTemplateFiller::writeCells(
            $bytes,
            WfpTemplateFiller::titleCells((string) ($plan->school?->name ?? ''), (int) $plan->year),
        );

        $blocks = WfpTemplateFiller::signatureBlocks($bytes);

        if ($blocks === []) {
            return $bytes;
        }

        // Clear signature names in both modes. The signed copy restores only
        // names supported by an existing, role-appropriate workflow record.
        $bytes = self::blankSignatureNames($bytes, $blocks);

        if (! $includeSignatures) {
            return $bytes;
        }

        $signers = self::authorizedSigners($plan, $preparedBy);

        foreach (self::SIGNATURE_STAGES as $stage) {
            $block = $blocks[$stage] ?? null;
            $signer = $signers[$stage] ?? null;

            if ($block === null || $signer === null) {
                continue;
            }

            $name = self::signerName($signer);

            if ($name !== '') {
                $bytes = WfpTemplateFiller::writeCells($bytes, [$block['name_cell'] => $name]);
            }

            $bytes = self::stampSignature($bytes, $signer, $block);
        }

        return $bytes;
    }

    /**
     * The sign-off sections whose authorized signer or registered profile
     * image is unavailable. Used to ask the SH before exporting a partially
     * signed copy.
     *
     * @return array<int, string>
     */
    public static function missingSignatureLabels(?WfpPlan $plan, ?User $preparedBy = null): array
    {
        $definitions = (array) config('wfp.template.signatures', []);
        $signers = $plan === null ? [] : self::authorizedSigners($plan, $preparedBy);
        $missing = [];

        foreach (self::SIGNATURE_STAGES as $stage) {
            $definition = (array) ($definitions[$stage] ?? []);
            $signer = $signers[$stage] ?? null;

            if ($signer !== null && self::hasRegisteredSignature($signer)) {
                continue;
            }

            $title = trim((string) ($definition['title'] ?? ucfirst($stage)));
            $label = trim((string) ($definition['label'] ?? ''));
            $missing[] = $title.($label !== '' ? ' — '.$label : '');
        }

        return $missing;
    }

    /**
     * Clear every discovered signature block's printed name, keeping the
     * block's cells and styles — the signature lines and role labels are
     * part of the form. Used by both export modes: the with-signatures export
     * then writes back only what a current signature supports.
     *
     * @param  array<string, array{name_cell: string}>  $blocks
     */
    private static function blankSignatureNames(string $bytes, array $blocks): string
    {
        $cells = [];

        foreach ($blocks as $block) {
            $cells[$block['name_cell']] = '';
        }

        return WfpTemplateFiller::writeCells($bytes, $cells);
    }

    /**
     * Stamp the authorized signer's registered profile image over its block.
     *
     * @param  array{range: string}  $block
     */
    private static function stampSignature(string $bytes, User $signer, array $block): string
    {
        $path = (string) $signer->signature_path;

        if ($path === '' || ! Storage::disk('local')->exists($path)) {
            return $bytes;
        }

        $disk = Storage::disk('local');
        $contents = (string) $disk->get($path);
        $extension = strtolower(pathinfo((string) $signer->signature_original_name, PATHINFO_EXTENSION));
        $extension = $extension !== '' ? $extension : (strtolower(pathinfo($path, PATHINFO_EXTENSION)) ?: 'png');
        $stamped = OpcrfSignatureStamper::stampAtRange(
            $bytes,
            $contents,
            $block['range'],
            $extension,
            self::signerName($signer),
            self::SIGNATURE_WIDTH_PX,
            self::SIGNATURE_HEIGHT_PX,
        );

        if ($stamped === null) {
            throw new RuntimeException('The registered signature for '.$signer->name.' could not be placed in the WFP template.');
        }

        return $stamped;
    }

    /**
     * The School Head is identified by the authorized preparer; the other
     * printed sections require their existing approval/check records. Role and
     * school/division access are rechecked when exporting; a profile signature
     * alone never establishes approval.
     *
     * @return array<string, User>
     */
    private static function authorizedSigners(WfpPlan $plan, ?User $preparedBy = null): array
    {
        if ($plan->school_id === null) {
            return [];
        }

        $schoolId = (int) $plan->school_id;
        $division = $plan->schoolDivision();
        $year = (int) $plan->year;
        $signers = [];

        if ($preparedBy !== null && $plan->managedBy($preparedBy)) {
            $signers['prepared'] = $preparedBy;
        }

        $check = WfpReportCheck::query()
            ->with('checker')
            ->where('school_id', $schoolId)
            ->where('school_division', $division)
            ->where('year', $year)
            ->first();
        $bookkeeper = $check?->checker;

        if ($check?->isChecked()
            && $bookkeeper?->canCheckWfpReport()
            && $bookkeeper->canAccessSchool($schoolId)
            && $bookkeeper->canAccessDivision($division)) {
            $signers['bookkeeper'] = $bookkeeper;
        }

        $allotment = SchoolAllotment::query()
            ->with('certifier')
            ->where('school_id', $schoolId)
            ->where('school_division', $division)
            ->where('year', $year)
            ->first();
        $budget = $allotment?->certifier;

        if ($allotment?->isCertified()
            && $budget?->canCertifyAllotments()
            && $budget->canAccessDivision($division)) {
            $signers['budget'] = $budget;
        }

        if ($plan->isApproved()) {
            $approval = $plan->finalApproval()->with('decidedBy')->first();
            $approver = $approval?->decidedBy;

            if ($approval?->isApproved()
                && $approval->decided_by !== null
                && (int) $approval->decided_by === (int) $plan->approved_by
                && $approver?->canApproveWfp()) {
                $signers['superadmin'] = $approver;
            }
        }

        return $signers;
    }

    private static function hasRegisteredSignature(User $signer): bool
    {
        $path = trim((string) $signer->signature_path);

        return $path !== '' && Storage::disk('local')->exists($path);
    }

    private static function signerName(User $signer): string
    {
        $name = trim((string) $signer->name);

        return $name !== '' ? $name : trim((string) $signer->username);
    }

    /**
     * The plan the SH's own download should come from, for the year they
     * picked, brought up to date with the MEM first — or null when the school
     * has nothing to build from, in which case the caller falls back to the
     * blank account-code form.
     *
     * Generation happens HERE rather than leaving a stale workbook to be
     * downloaded: the button says "Generate and Download", and a plan whose
     * matrices have moved on since it was generated would hand the SH numbers
     * the MEM no longer has. A plan that is pending final approval or already
     * approved is never regenerated behind the approver's back — regeneration
     * would change the content being reviewed — so it is exported exactly as
     * it stands.
     *
     * The year is a PREFERENCE, not an instruction: a year the school has no
     * plan or matrix lines for resolves to one it does have (see dataYearFor),
     * so a crafted value cannot generate from another year's data.
     */
    public static function exportableFor(
        ?User $user,
        ?int $preferredYear = null,
        ?string $division = null,
    ): ?WfpPlan {
        $division = self::resolveDivision($user, $division);
        $year = self::dataYearFor($user, $preferredYear, $division);

        if ($year === null) {
            return null;
        }

        /** @var User $user */
        $plan = WfpPlan::firstOrCreate(
            [
                'school_id' => $user->school_id,
                'school_division' => $division,
                'year' => $year,
            ],
            ['status' => WfpPlan::STATUS_DRAFT],
        );

        if ($plan->isEditable() && (! $plan->items()->exists() || $plan->isOutOfDate())) {
            $plan->generated_by = $user->id;
            WfpGenerator::generate($plan); // reconciles in place, and saves the plan
        }

        return $plan;
    }

    /**
     * Find the plan for an SH's selected year without creating or regenerating
     * it. Used to identify missing sign-offs before the confirmation prompt.
     */
    public static function existingFor(?User $user, int $year, ?string $division = null): ?WfpPlan
    {
        if ($user === null || ! $user->canAccessWfp() || $user->school_id === null) {
            return null;
        }

        return WfpPlan::query()
            ->where('school_id', (int) $user->school_id)
            ->where('school_division', self::resolveDivision($user, $division))
            ->where('year', $year)
            ->first();
    }

    /**
     * The year the SH's generated WFP belongs to: the year they picked when
     * the school has data in it, otherwise the newest year it has data for.
     * Null when it has none: an empty year of matrices is not data (the
     * twelve months exist as soon as a year is opened), so the blank form is
     * then the only thing that can be generated.
     *
     * Read-only on purpose: the card renders it to say which workbook is
     * coming, and rendering must never create a plan.
     */
    public static function dataYearFor(
        ?User $user,
        ?int $preferredYear = null,
        ?string $division = null,
    ): ?int {
        $years = self::dataYears($user, $division);

        if ($years === []) {
            return null;
        }

        return $preferredYear !== null && in_array($preferredYear, $years, true)
            ? $preferredYear
            : $years[0];
    }

    /**
     * Every year the school has something to generate from — a plan it has
     * already generated, or matrix lines to generate one from — newest first.
     * Empty when it has neither; the caller decides what to offer then (the
     * generate button still offers the current year, for the blank form).
     *
     * @return array<int, int>
     */
    public static function dataYears(?User $user, ?string $division = null): array
    {
        if ($user === null || ! $user->canAccessWfp() || $user->school_id === null) {
            return [];
        }

        $division = self::resolveDivision($user, $division);
        $schoolId = (int) $user->school_id;

        $planYears = WfpPlan::query()
            ->where('school_id', $schoolId)
            ->where('school_division', $division)
            ->pluck('year');

        $matrixYears = MonthlyExpenditureMatrix::query()
            ->where('school_id', $schoolId)
            ->where('school_division', $division)
            ->whereHas('items')
            ->pluck('year');

        return $planYears
            ->merge($matrixYears)
            ->map(static fn ($year): int => (int) $year)
            ->unique()
            ->sortDesc()
            ->values()
            ->all();
    }

    /** The division to export: the explicit one, else the account's active division. */
    private static function resolveDivision(?User $user, ?string $division): string
    {
        return $division ?? $user?->activeDivision() ?? SchoolDivision::ELEMENTARY;
    }
}
