<?php

namespace App\Http\Controllers;

use App\Domain\Security\Totp;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Two-factor sign-in for the console and the audit log (2026-09-24).
 *
 * Optional per admin. Switching it on: the console asks for a secret, shows it as a QR code, and
 * the admin proves the app reads it by typing one code. Only then is 2FA on, and the admin gets ten
 * recovery codes, shown once. From then on every new sign-in asks for a code before /admin opens.
 * Switching it off takes a current code too, so a stolen session cannot remove it. A lost phone is
 * reset from the shell (factory:admin-2fa-reset), never from a browser.
 */
class AdminSecurityController extends Controller
{
    public function status(Request $request): JsonResponse
    {
        $me = $request->user();
        $token = $me->currentAccessToken();

        return response()->json([
            'enabled' => $me->two_factor_enabled_at !== null,
            // Nothing to pass while it is off.
            'passed' => $me->two_factor_enabled_at === null || ! $token instanceof PersonalAccessToken || $token->two_factor_at !== null,
            'recovery_left' => count($me->two_factor_recovery ?? []),
        ]);
    }

    /**
     * The secret to scan, not active until a code from it is confirmed. A pending one is handed
     * out again rather than replaced, so a page loaded twice (or two tabs) shows one QR code that
     * matches what the server checks. Refused once 2FA is on.
     */
    public function setup(Request $request): JsonResponse
    {
        $me = $request->user();
        abort_if($me->two_factor_enabled_at !== null, 409, 'Two-factor sign-in is already on.');
        $secret = $me->two_factor_secret ?: Totp::secret();
        $me->forceFill(['two_factor_secret' => $secret, 'two_factor_last_step' => null])->save();

        return response()->json(['secret' => $secret, 'uri' => Totp::uri($secret, $me->email)]);
    }

    public function enable(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => 'required|string|max:12']);
        $me = $request->user();
        abort_if($me->two_factor_enabled_at !== null, 409, 'Two-factor sign-in is already on.');
        abort_if(! $me->two_factor_secret, 422, 'Start the setup first.');
        $step = Totp::verify($me->two_factor_secret, $data['code']);
        abort_if($step === null, 422, 'That code does not match. Check the time on your phone and try the next one.');

        $codes = collect(range(1, 10))->map(fn () => Str::lower(Str::random(5).'-'.Str::random(5)))->all();
        $me->forceFill([
            'two_factor_enabled_at' => now(),
            'two_factor_last_step' => $step,
            'two_factor_recovery' => array_map(fn ($c) => Hash::make($c), $codes),
        ])->save();
        $this->pass($me);

        return response()->json(['enabled' => true, 'recovery_codes' => $codes]);
    }

    /** A code from the app, or one of the recovery codes, which then stops working. */
    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => 'required|string|max:20']);
        $me = $request->user();
        abort_if($me->two_factor_enabled_at === null, 409, 'Two-factor sign-in is not set up yet.');
        abort_unless($this->accepts($me, trim($data['code'])), 422, 'That code does not match.');
        $this->pass($me);

        return response()->json(['passed' => true, 'recovery_left' => count($me->two_factor_recovery ?? [])]);
    }

    /** A fresh code from the app, or a recovery code, which is used up by this. */
    private function accepts($me, string $code): bool
    {
        $step = Totp::verify($me->two_factor_secret, $code, $me->two_factor_last_step);
        if ($step !== null) {
            $me->forceFill(['two_factor_last_step' => $step])->save();

            return true;
        }
        $left = $me->two_factor_recovery ?? [];
        foreach ($left as $i => $hash) {
            if (Hash::check(Str::lower($code), $hash)) {
                unset($left[$i]);
                $me->forceFill(['two_factor_recovery' => array_values($left)])->save();

                return true;
            }
        }

        return false;
    }

    /** Switches 2FA off. Needs a code from the app or a recovery code, like a sign-in. */
    public function disable(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => 'required|string|max:20']);
        $me = $request->user();
        abort_if($me->two_factor_enabled_at === null, 409, 'Two-factor sign-in is already off.');
        abort_unless($this->accepts($me, trim($data['code'])), 422, 'That code does not match.');
        $me->forceFill(['two_factor_secret' => null, 'two_factor_enabled_at' => null, 'two_factor_recovery' => null, 'two_factor_last_step' => null])->save();

        return response()->json(['enabled' => false]);
    }

    /** The newest entries first, 100 at a time; `before` pages back. */
    public function audit(Request $request): JsonResponse
    {
        $q = AuditLog::query()->orderByDesc('id')->limit(100);
        if ($before = (int) $request->query('before')) {
            $q->where('id', '<', $before);
        }
        if ($actor = trim((string) $request->query('actor'))) {
            $q->where('actor', 'like', '%'.addcslashes($actor, '%_').'%');
        }
        if ($action = trim((string) $request->query('action'))) {
            $q->where('action', 'like', '%'.addcslashes($action, '%_').'%');
        }

        return response()->json(['entries' => $q->get()->map(fn (AuditLog $l) => [
            'id' => $l->id, 'at' => $l->created_at?->toIso8601String(), 'actor' => $l->actor, 'action' => $l->action,
            'subject' => $l->subject, 'status' => $l->status, 'data' => $l->data, 'ip' => $l->ip,
        ])]);
    }

    private function pass($me): void
    {
        $token = $me->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $token->forceFill(['two_factor_at' => now()])->save();
        }
    }
}
