<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Global, cross-process pacing for outbound Omniful API calls.
 *
 * The order backfill (GET seller orders) and the inventory qty push (POST hub
 * inventory) both authenticate with the SAME seller token, so Omniful meters
 * them against the SAME rate-limit bucket. Their per-feature throttles are
 * independent, so running both at once can sum past the limit and 429 each
 * other. This limiter reserves a shared "next slot" in the cache (guarded by a
 * short lock) so that AT MOST one Omniful call happens per min-interval across
 * every worker, regardless of how many run concurrently.
 *
 * Each caller reserves the next free slot (now, now+interval, now+2·interval…)
 * and sleeps to it. The lock is held only for the reserve (fast) — never during
 * the sleep — so callers pace out instead of serialising on the lock.
 */
class OmnifulRateLimiter
{
    private const SLOT_KEY = 'omniful:ratelimit:next_ms';

    private const LOCK_KEY = 'omniful:ratelimit:lock';

    public static function throttle(?int $minIntervalMs = null): void
    {
        $interval = $minIntervalMs ?? (int) config('omniful.rate_limit.min_interval_ms', 600);
        if ($interval <= 0) {
            return;
        }

        $waitMs = 0;
        $lock = Cache::lock(self::LOCK_KEY, 10);

        try {
            $lock->block(10);

            $nowMs = (int) (microtime(true) * 1000);
            $next = (int) Cache::get(self::SLOT_KEY, $nowMs);
            if ($next < $nowMs) {
                $next = $nowMs;
            }

            Cache::put(self::SLOT_KEY, $next + $interval, now()->addMinutes(5));
            $waitMs = $next - $nowMs;
        } catch (\Throwable) {
            // Never let a cache/lock hiccup block the actual API call.
            $waitMs = 0;
        } finally {
            optional($lock)->release();
        }

        if ($waitMs > 0) {
            usleep($waitMs * 1000);
        }
    }
}
