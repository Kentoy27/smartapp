<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\CarbonInterface;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'position',
        'role',
        'username',
        'email',
        'password',
        'is_superadmin',
        'google_id',
        'school_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'password' => 'hashed',
        'is_superadmin' => 'boolean',
        'signature_size' => 'integer',
        'signature_uploaded_at' => 'datetime',
        'signature_updated_at' => 'datetime',
    ];

    /**
     * The school this account belongs to (District → School → User) —
     * null for superadmins and accounts created before a school was
     * assigned.
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * Whether this account has access to administrative workflows.
     */
    public function hasAdminAccess(): bool
    {
        return $this->is_superadmin || $this->role === 'administrator';
    }

    public function isBudgetRole(): bool
    {
        return ! $this->is_superadmin && $this->role === 'budget';
    }

    /**
     * Whether this account is a Bookkeeper — the role that checks the
     * correctness of the schools' account titles and codes.
     *
     * The Bookkeeper works the Budget office-style, division-wide checking
     * pages (Monthly Expenditure and Per Line Item Expenditure — see
     * {@see canCheckLineItems()}), never writes a school's plan, and signs
     * the annual report's "Checked as to the Correctness of Account Titles
     * and Codes" block.
     */
    public function isBookkeeperRole(): bool
    {
        return ! $this->is_superadmin && $this->role === 'bookkeeper';
    }

    /** Whether this account may review submitted AIP plans. */
    public function canReviewAip(): bool
    {
        return $this->hasAdminAccess() || $this->isBudgetRole();
    }

    /** Whether this account may read and download submitted WFP workbooks. */
    public function canReviewWfp(): bool
    {
        return $this->hasAdminAccess() || $this->isBudgetRole();
    }

    /**
     * Whether this account may set the school allotment budgets the School
     * Heads consume through their Monthly Expenditure Matrices.
     *
     * Setting the ceiling is the Budget office's job (the Budget role); the
     * administrative side that oversees the schools may also set it. It is
     * never the School Head's own call — the SH reads the ceiling on their
     * own report, they do not write it.
     */
    public function canManageAllotments(): bool
    {
        return $this->isBudgetRole() || $this->hasAdminAccess();
    }

    /**
     * Whether this account may certify that a school's allotment is available.
     *
     * The certification is the Budget Officer's statement on the School
     * Head's report, so it belongs to the Budget role alone. The
     * administrative side may set the ceiling but does not sign for the
     * Budget office; it still sees the certification status on the page.
     */
    public function canCertifyAllotments(): bool
    {
        return $this->isBudgetRole();
    }

    /**
     * Whether this account may read the per-line expenditure items the
     * schools encode in their Monthly Expenditure Matrices, school by
     * school, and each school's annual MOOE allotment report.
     *
     * Checking the lines — their account titles, codes and amounts — is the
     * Budget office's work, and the school Bookkeeper's: both work the same
     * division-wide, read-only pages (Monthly Expenditure and Per Line Item
     * Expenditure). The School Head owns the lines and never checks their
     * own entry, so neither is the SH's.
     */
    public function canCheckLineItems(): bool
    {
        return $this->isBudgetRole() || $this->isBookkeeperRole();
    }

    /** Whether this account may manage accounts and school organization. */
    public function canManageUsersAndSchools(): bool
    {
        return $this->role === 'administrator' && ! $this->is_superadmin;
    }

    public function roleLabel(): string
    {
        if ($this->is_superadmin || $this->role === 'superadmin') {
            return 'Super Admin';
        }

        return match ($this->role) {
            'administrator' => 'Administrator',
            'bookkeeper' => 'Bookkeeper',
            'budget' => 'Budget',
            'viewer' => 'SDS Viewer',
            default => 'SH',
        };
    }

    /**
     * Whether this account may access the School Head's Work and Financial
     * Plan (WFP) workspace — the /wfp pages, the matrices and the report's
     * school-scoped copy. The role column uses 'user' for the School Head
     * (SH, the UI labels it 'SH'); only a non-superadmin SH reaches it —
     * everyone else gets a 404 from the route guard.
     *
     * The Bookkeeper does NOT work these pages: its interface is the Budget
     * office-style, division-wide pair (Monthly Expenditure and Per Line
     * Item Expenditure — see {@see canCheckLineItems()}).
     */
    public function canAccessWfp(): bool
    {
        return ! $this->is_superadmin && $this->role === 'user';
    }

    /**
     * Whether this account may author the WFP work: edit matrices, generate
     * and download workbooks, upload completed plans. WFP is written by the
     * School Head (SH) role alone — the one account that owns a school's
     * plan.
     */
    public function canManageWfp(): bool
    {
        return ! $this->is_superadmin && $this->role === 'user';
    }

    /**
     * Whether this account may sign the report's "Checked as to the
     * Correctness of Account Titles and Codes" block.
     *
     * The check is the Bookkeeper's statement about the lines' account
     * titles and codes, so it belongs to the Bookkeeper role alone. The
     * School Head whose lines are checked never signs for the checking, and
     * the administrative side reads the status but does not stand for the
     * Bookkeeper's office.
     */
    public function canCheckWfpReport(): bool
    {
        return $this->isBookkeeperRole();
    }

    /**
     * Whether this account uses the staff-facing school pages (OPCRF, MOV).
     *
     * Those pages are the non-administrative school work, so the
     * administrative side (including superadmins) is refused. The Bookkeeper
     * is excluded as well: its module is the division-wide checking pages
     * alone, so it carries no OPCRF or MOV destination of its own.
     */
    public function canAccessStaffPages(): bool
    {
        return ! $this->hasAdminAccess() && ! $this->isBookkeeperRole();
    }

    /**
     * Whether this account may author an Annual Implementation Plan (AIP).
     *
     * An AIP belongs to a school, so its author is the School Head (SH) —
     * the same role that owns the WFP and the Monthly Expenditure Matrix.
     * Administrative accounts review AIPs (hasAdminAccess()), they do not
     * author them, so they are excluded here.
     */
    public function canAccessAip(): bool
    {
        return ! $this->is_superadmin && $this->role === 'user';
    }

    /**
     * The account's one digital signature. It belongs to the account, not to a
     * role: a user and a superadmin manage theirs the same way, on the same
     * profile page.
     */
    public function hasSignature(): bool
    {
        return $this->signature_path !== null && $this->signature_path !== '';
    }

    /**
     * The label the profile page shows for the signature's state.
     */
    public function signatureStatusLabel(): string
    {
        return $this->hasSignature() ? 'Signature uploaded' : 'Not uploaded';
    }

    /**
     * The URL the profile page previews the signature from. Served through an
     * authorised route, never from the public directory: the file sits on the
     * private `local` disk.
     */
    public function signatureUrl(): ?string
    {
        return $this->hasSignature() ? route('profile.signature') : null;
    }

    /**
     * Serve this account's stored signature inline to an authorised caller.
     *
     * There are exactly two callers: the account itself (the profile page's
     * preview) and whoever may read a submission whose stamped workbook
     * encodes the same signature. The file is always resolved from this
     * account's own columns — no path ever comes from the client — and a
     * signature is personal data, so the response must never be cached by a
     * shared cache or kept in the browser's history as a downloadable file.
     */
    public function signatureImageResponse(): Response
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk('local');

        abort_unless(
            $this->hasSignature() && $disk->exists((string) $this->signature_path),
            404
        );

        $extension = strtolower(pathinfo((string) $this->signature_original_name, PATHINFO_EXTENSION));
        $name = 'signature-'.$this->id.($extension !== '' ? '.'.$extension : '');

        $response = $disk->response((string) $this->signature_path, $name);

        $response->headers->set(
            'Content-Disposition',
            $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $name),
        );

        if ($this->signature_mime_type) {
            $response->headers->set('Content-Type', $this->signature_mime_type);
        }

        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');

        return $response;
    }

    /**
     * When the signature was first uploaded. Kept across replacements, so the
     * page can show both "uploaded" and "last updated".
     */
    public function signatureUploadedAt(): ?CarbonInterface
    {
        return $this->signature_uploaded_at;
    }

    /**
     * When the signature last changed — the first upload sets both dates; a
     * replacement only moves this one.
     */
    public function signatureUpdatedAt(): ?CarbonInterface
    {
        return $this->signature_updated_at;
    }

    /**
     * A human size for the stored file ("84.2 KB").
     */
    public function signatureHumanSize(): ?string
    {
        if ($this->signature_size === null) {
            return null;
        }

        $bytes = (int) $this->signature_size;

        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1).' KB';
        }

        return round($bytes / (1024 * 1024), 2).' MB';
    }

    /**
     * Forget the signature: the stored file first, then the columns.
     *
     * Removing the file never blocks removing the record — a signature the
     * profile no longer points at must not stay readable through the preview
     * route, so the columns are cleared even when the delete fails.
     */
    public function clearSignature(): void
    {
        if ($this->hasSignature()) {
            try {
                Storage::disk('local')->delete($this->signature_path);
            } catch (\Throwable) {
                // A file that is already gone is not an error here.
            }
        }

        $this->forceFill([
            'signature_path' => null,
            'signature_original_name' => null,
            'signature_mime_type' => null,
            'signature_size' => null,
            'signature_uploaded_at' => null,
            'signature_updated_at' => null,
        ])->save();
    }
}
