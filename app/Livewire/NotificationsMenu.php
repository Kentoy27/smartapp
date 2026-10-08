<?php

namespace App\Livewire;

use App\Notifications\OpcrfDecided;
use App\Notifications\OpcrfReturned;
use App\Notifications\OpcrfSubmitted;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The topbar notification centre: a bell with an unread count and a panel
 * of what happened while the user was elsewhere.
 *
 * It reads Laravel's database notifications — the same rows the staff
 * dashboard's "Action Required" banner is built from — and turns each one
 * into a line a person can act on: what happened, who did it, and where to
 * go. Clicking a line marks it read and navigates there; "Mark all read"
 * clears the badge in one go.
 *
 * The list is short on purpose (the newest handful) and the panel says how
 * many older ones there are, so the bell is a prompt to go and look, not a
 * full inbox.
 *
 * It polls (like the rest of the app's live chrome), so a submission
 * arriving or a reviewer's decision lands in the badge without a reload.
 */
class NotificationsMenu extends Component
{
    /** Notifications belong to the signed-in account. */
    public function mount(): void
    {
        abort_unless(Auth::check(), 404);
    }

    /**
     * How many notifications the panel lists before it starts counting the
     * rest. A phone-sized topbar cannot usefully show more.
     */
    private const VISIBLE = 6;

    public function render()
    {
        return view('livewire.notifications.menu');
    }

    /**
     * The panel's lines, newest first: the icon and colour that match the
     * event, a headline, the detail that makes it actionable, and where it
     * leads. Unknown notification types fall back to a neutral line rather
     * than being hidden — a notification the user cannot read is worse than
     * a plain one.
     *
     * @return array<int, array{id: string, icon: string, tone: string, title: string, detail: string, link: string, unread: bool, at: string}>
     */
    #[Computed]
    public function lines(): array
    {
        return Auth::user()
            ? Auth::user()->notifications()->latest()->limit(self::VISIBLE)->get()
                ->map(fn ($notification): array => $this->describe($notification))
                ->all()
            : [];
    }

    /**
     * Unread notifications for the bell's badge — every unread one, not
     * just the visible page, so a count of 12 is never reported as 6.
     */
    #[Computed]
    public function unreadCount(): int
    {
        return Auth::user()?->unreadNotifications()->count() ?? 0;
    }

    /**
     * The unread notifications beyond the visible page, so the panel can
     * say "and N older" instead of silently truncating.
     */
    #[Computed]
    public function olderUnread(): int
    {
        $unread = Auth::user()?->unreadNotifications()->count() ?? 0;

        return max(0, $unread - min($unread, self::VISIBLE));
    }

    /**
     * Everything in the bell is read — the badge clears, the list keeps its
     * history.
     */
    public function markAllRead(): void
    {
        Auth::user()?->unreadNotifications->markAsRead();

        unset($this->lines, $this->unreadCount, $this->olderUnread);
    }

    /**
     * One notification is read (the user clicked it, so it is on screen).
     * Navigation to its destination is left to the view's plain link — this
     * only clears the unread state.
     */
    public function markRead(string $id): void
    {
        $notification = Auth::user()?->notifications()->find($id);

        if ($notification !== null && $notification->read_at === null) {
            $notification->markAsRead();
        }

        unset($this->lines, $this->unreadCount, $this->olderUnread);
    }

    /**
     * Turn a stored notification into a display line.
     *
     * @return array{id: string, icon: string, tone: string, title: string, detail: string, link: string, unread: bool, at: string}
     */
    private function describe(object $notification): array
    {
        $data = is_array($notification->data) ? $notification->data : [];
        $user = Auth::user();

        $line = match ($notification->type) {
            OpcrfSubmitted::class => [
                'icon' => 'upload',
                'tone' => 'info',
                'title' => 'New OPCRF submitted',
                'detail' => $this->employee($data).' submitted an OPCRF for review.'
                    .$this->period($data),
                'link' => route('opcrf.review'),
            ],
            OpcrfReturned::class => [
                'icon' => 'pen-line',
                'tone' => 'warn',
                'title' => 'OPCRF returned for revision',
                'detail' => $this->remarks($data, (string) ($data['returned_by'] ?? 'Your reviewer').' returned it.'),
                'link' => route('opcrf.index'),
            ],
            OpcrfDecided::class => [
                'icon' => 'check-check',
                'tone' => 'ok',
                'title' => 'OPCRF marked compliant',
                'detail' => $this->remarks(
                    $data,
                    ($data['decided_by'] ?? 'Your reviewer').' marked it compliant'
                        .(($data['forwarded_onward'] ?? false) === true ? ' and routed it to the next reviewer.' : '.')
                ),
                'link' => route('opcrf.index'),
            ],
            default => [
                'icon' => 'bell',
                'tone' => 'muted',
                'title' => 'Notification',
                'detail' => 'Open the page this refers to for details.',
                'link' => $user?->hasAdminAccess() ? route('opcrf.review') : route('opcrf.index'),
            ],
        };

        return [
            'id' => (string) $notification->id,
            'unread' => $notification->read_at === null,
            'at' => $notification->created_at?->diffForHumans() ?? '',
            ...$line,
        ];
    }

    /**
     * The reviewer's remarks when they wrote any, otherwise the event's own
     * sentence — the point of the line is what the reader must act on.
     */
    private function remarks(array $data, string $fallback): string
    {
        $remarks = trim((string) ($data['remarks'] ?? ''));

        return $remarks !== '' ? '“'.mb_substr($remarks, 0, 120).'”' : $fallback;
    }

    /**
     * Who filed a submission: the name on the form, else the account.
     */
    private function employee(array $data): string
    {
        $name = trim((string) ($data['employee'] ?? ''));

        return $name !== '' ? $name : 'A staff member';
    }

    /**
     * The review period in brackets, when the notification carries one —
     * reviewers triage a queue by cycle, not by clock time.
     */
    private function period(array $data): string
    {
        $period = trim((string) ($data['review_period'] ?? ''));

        return $period !== '' ? ' ('.$period.')' : '';
    }
}
