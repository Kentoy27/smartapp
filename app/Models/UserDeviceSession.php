<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserDeviceSession extends Model
{
    protected $fillable = [
        'user_id',
        'session_hash',
        'browser',
        'platform',
        'device_type',
        'last_seen_at',
        'logged_out_at',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'logged_out_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function recordActivity(User $user, string $sessionId, ?string $userAgent): void
    {
        $session = static::query()->firstOrNew([
            'session_hash' => hash('sha256', $sessionId),
        ]);
        $now = now();

        if (
            ! $session->exists
            || (int) $session->user_id !== (int) $user->id
            || $session->logged_out_at !== null
            || $session->last_seen_at === null
            || $session->last_seen_at->lte($now->copy()->subMinute())
        ) {
            [$browser, $platform, $deviceType] = static::describeUserAgent($userAgent);

            $session->fill([
                'user_id' => $user->id,
                'browser' => $browser,
                'platform' => $platform,
                'device_type' => $deviceType,
                'last_seen_at' => $now,
                'logged_out_at' => null,
            ])->save();
        }
    }

    public static function endCurrentSession(User $user, string $sessionId): void
    {
        static::query()
            ->where('user_id', $user->id)
            ->where('session_hash', hash('sha256', $sessionId))
            ->whereNull('logged_out_at')
            ->update(['logged_out_at' => now(), 'updated_at' => now()]);
    }

    public function isCurrentSession(string $sessionId): bool
    {
        return hash_equals($this->session_hash, hash('sha256', $sessionId));
    }

    /**
     * @return array{string, string, string}
     */
    private static function describeUserAgent(?string $userAgent): array
    {
        $agent = strtolower($userAgent ?? '');

        $browser = match (true) {
            str_contains($agent, 'edg/') || str_contains($agent, 'edga/') || str_contains($agent, 'edgios/') => 'Microsoft Edge',
            str_contains($agent, 'opr/') => 'Opera',
            str_contains($agent, 'firefox/') => 'Firefox',
            str_contains($agent, 'chrome/') || str_contains($agent, 'crios/') => 'Chrome',
            str_contains($agent, 'safari/') => 'Safari',
            str_contains($agent, 'msie') || str_contains($agent, 'trident/') => 'Internet Explorer',
            default => 'Unknown browser',
        };

        $platform = match (true) {
            str_contains($agent, 'windows') => 'Windows',
            str_contains($agent, 'android') => 'Android',
            str_contains($agent, 'iphone') || str_contains($agent, 'ipad') || str_contains($agent, 'ipod') => 'iOS',
            str_contains($agent, 'mac os') || str_contains($agent, 'macintosh') => 'macOS',
            str_contains($agent, 'linux') => 'Linux',
            default => 'Unknown platform',
        };

        $deviceType = match (true) {
            str_contains($agent, 'ipad'), str_contains($agent, 'tablet') => 'Tablet',
            str_contains($agent, 'mobile'), str_contains($agent, 'iphone'), str_contains($agent, 'android') => 'Phone',
            default => 'Computer',
        };

        return [$browser, $platform, $deviceType];
    }
}
