<?php

use App\Models\AipReview;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $reviewerId = $this->reviewerId((string) config('aip.review_route', 'Rebonie'), true);

        if ($reviewerId === null) {
            return;
        }

        DB::table('aip_plans')
            ->where('status', 'pending')
            ->whereNotNull('submitted_at')
            ->whereNotExists(function (Builder $reviews): void {
                $reviews->selectRaw('1')
                    ->from('aip_reviews')
                    ->whereColumn('aip_reviews.aip_plan_id', 'aip_plans.id')
                    ->whereIn('aip_reviews.action', [
                        AipReview::ACTION_COMPLIANCE,
                        AipReview::ACTION_RETURN,
                        AipReview::ACTION_RESUBMIT,
                    ]);
            })
            ->update([
                'reviewer_id' => $reviewerId,
                'assigned_to' => $reviewerId,
            ]);
    }

    public function down(): void
    {
        $previousReviewerId = $this->reviewerId((string) config('opcrf.review_route', 'Eve'), false);

        if ($previousReviewerId === null) {
            return;
        }

        $currentReviewerId = $this->reviewerId((string) config('aip.review_route', 'Rebonie'), true);

        if ($currentReviewerId === null) {
            return;
        }

        DB::table('aip_plans')
            ->where('status', 'pending')
            ->where('reviewer_id', $currentReviewerId)
            ->where('assigned_to', $currentReviewerId)
            ->whereNotExists(function (Builder $reviews): void {
                $reviews->selectRaw('1')
                    ->from('aip_reviews')
                    ->whereColumn('aip_reviews.aip_plan_id', 'aip_plans.id')
                    ->whereIn('aip_reviews.action', [
                        AipReview::ACTION_COMPLIANCE,
                        AipReview::ACTION_RETURN,
                        AipReview::ACTION_RESUBMIT,
                    ]);
            })
            ->update([
                'reviewer_id' => $previousReviewerId,
                'assigned_to' => $previousReviewerId,
            ]);
    }

    private function reviewerId(string $username, bool $allowViewer): ?int
    {
        if (trim($username) === '') {
            return null;
        }

        return DB::table('users')
            ->where('username', trim($username))
            ->where(function (Builder $roles) use ($allowViewer): void {
                $roles->where('is_superadmin', true)
                    ->orWhere('role', 'administrator');

                if ($allowViewer) {
                    $roles->orWhere('role', 'viewer');
                }
            })
            ->value('id');
    }
};
