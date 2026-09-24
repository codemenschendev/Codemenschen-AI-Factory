<?php

namespace App\Domain\Security;

use App\Models\AuditLog;
use App\Models\Customer;
use Illuminate\Http\Request;

/**
 * The audit log (2026-09-24): who changed what in the console, and what the machine did on its own.
 * Writing it must never break the action it records, so every failure here is swallowed.
 */
class Audit
{
    /** Request fields that are never written down, whatever their value. */
    private const SECRET = '/(token|secret|password|passwd|api_?key|recovery|^code$)/i';

    public static function record(string $action, ?Customer $actor, ?string $subject = null, array $data = [], ?Request $request = null, ?int $status = null): void
    {
        try {
            AuditLog::create([
                'customer_id' => $actor?->id,
                'actor' => $actor?->email ?? 'system',
                'action' => mb_substr($action, 0, 120),
                'subject' => $subject !== null ? mb_substr($subject, 0, 190) : null,
                'data' => $data === [] ? null : self::clean($data),
                'status' => $status,
                'ip' => $request?->ip(),
                'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 300) : null,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Something the scheduler or a job did with nobody pressing a button. */
    public static function system(string $action, ?string $subject = null, array $data = []): void
    {
        self::record($action, null, $subject, $data);
    }

    /** Secrets out, long text cut, nesting capped: the log says what changed, not every byte of it. */
    public static function clean(array $data, int $depth = 0): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::SECRET, $key)) {
                $out[$key] = '[removed]';
            } elseif (is_array($value)) {
                $out[$key] = $depth >= 2 ? '[…]' : self::clean($value, $depth + 1);
            } elseif (is_string($value)) {
                $out[$key] = mb_strlen($value) > 300 ? mb_substr($value, 0, 300).'…' : $value;
            } elseif (is_scalar($value) || $value === null) {
                $out[$key] = $value;
            } else {
                $out[$key] = '['.get_debug_type($value).']';
            }
        }

        return $out;
    }
}
