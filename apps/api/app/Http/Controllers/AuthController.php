<?php

namespace App\Http\Controllers;

use App\Domain\Analytics\Analytics;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

class AuthController extends Controller
{
    /**
     * Passwordless login: e-mail a signed, short-lived verify link. Always
     * answers 200 so the endpoint can't be used to probe which e-mails exist.
     */
    public function magicLink(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => 'required|email',
            'locale' => 'nullable|in:de,en',
            // From the prototype form: an address we do not know yet gets a link that creates
            // the account when it is clicked, so an e-mail typed there is always one that works.
            'join' => 'nullable|boolean',
        ]);
        $customer = Customer::where('email', strtolower($data['email']))->first();

        if ($customer !== null) {
            $url = URL::temporarySignedRoute('auth.verify', now()->addMinutes(30), [
                'customer' => $customer->id,
                'locale' => $data['locale'] ?? $customer->locale,
            ]);
            if (config('mail.default') === 'log') {
                Log::info('auth.magic_link', ['email' => $customer->email, 'url' => $url]);
            } else {
                Mail::raw(
                    ($data['locale'] ?? 'de') === 'de'
                        ? "Dein Anmelde-Link (30 Minuten gültig):\n\n$url"
                        : "Your sign-in link (valid 30 minutes):\n\n$url",
                    fn ($m) => $m->to($customer->email)->subject('Appwerk sign-in'),
                );
            }
        }

        if ($customer === null && ($data['join'] ?? false)) {
            $email = strtolower($data['email']);
            $url = URL::temporarySignedRoute('auth.join', now()->addMinutes(30), [
                'email' => $email,
                'locale' => $data['locale'] ?? 'de',
            ]);
            if (config('mail.default') === 'log') {
                Log::info('auth.join_link', ['email' => $email, 'url' => $url]);
            } else {
                Mail::raw(
                    ($data['locale'] ?? 'de') === 'de'
                        ? "Dein Anmelde-Link für Appwerk (30 Minuten gültig):\n\n$url\n\nDanach geht es mit deinem Prototyp weiter."
                        : "Your Appwerk sign-in link (valid 30 minutes):\n\n$url\n\nAfter that, your prototype carries on.",
                    fn ($m) => $m->to($email)->subject('Appwerk sign-in'),
                );
            }
        }

        app(Analytics::class)->record('signin_requested', $request, ['customer_id' => $customer?->id], ['known' => $customer !== null]);

        return response()->json(['sent' => true]);
    }

    /** A signed join link: the account is created only now, when the address has proven itself. */
    public function join(Request $request): RedirectResponse
    {
        abort_unless($request->hasValidSignature(), 403, 'Link expired or invalid');
        $locale = in_array($request->query('locale'), ['de', 'en'], true) ? $request->query('locale') : 'de';
        $customer = Customer::firstOrCreate(['email' => strtolower((string) $request->query('email'))], ['locale' => $locale]);
        $token = $customer->createToken('portal', ['portal'])->plainTextToken;
        app(Analytics::class)->record('signin_completed', $request, ['customer_id' => $customer->id], ['joined' => $customer->wasRecentlyCreated]);
        $front = rtrim(config('services.frontend_url'), '/');

        return redirect()->away("$front/$locale/account#token=$token");
    }

    /** Signed link → Sanctum token → hand off to the storefront account page. */
    public function verify(Request $request, Customer $customer): RedirectResponse
    {
        abort_unless($request->hasValidSignature(), 403, 'Link expired or invalid');
        $token = $customer->createToken('portal', ['portal'])->plainTextToken;
        app(Analytics::class)->record('signin_completed', $request, ['customer_id' => $customer->id]);
        $front = rtrim(config('services.frontend_url'), '/');
        $locale = $request->query('locale', $customer->locale);

        return redirect()->away("$front/$locale/account#token=$token");
    }
}
