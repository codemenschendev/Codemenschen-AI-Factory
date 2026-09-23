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
            // Where the link lands. The console asks for its own, so an operator signing in is
            // not sent through the customer's account page to get back to where they started.
            'to' => 'nullable|in:account,admin',
        ]);
        $customer = Customer::where('email', strtolower($data['email']))->first();

        if ($customer !== null) {
            // The console is named in the mail only for somebody who can open it. Anyone else who
            // types their address into the console's form gets the ordinary link, which says
            // nothing about a console existing and lands on their own account page.
            $console = ($data['to'] ?? null) === 'admin' && $customer->is_admin;
            $url = URL::temporarySignedRoute('auth.verify', now()->addMinutes(30), [
                'customer' => $customer->id,
                'locale' => $data['locale'] ?? $customer->locale,
            ] + ($console ? ['to' => 'admin'] : []));
            if (config('mail.default') === 'log') {
                Log::info('auth.magic_link', ['email' => $customer->email, 'url' => $url]);
            } else {
                $de = ($data['locale'] ?? 'de') === 'de';
                Mail::raw(
                    match (true) {
                        $console && $de => "Dein Anmelde-Link für die Appwerk Konsole (30 Minuten gültig):\n\n$url",
                        $console => "Your sign-in link for the Appwerk console (valid 30 minutes):\n\n$url",
                        $de => "Dein Anmelde-Link (30 Minuten gültig):\n\n$url",
                        default => "Your sign-in link (valid 30 minutes):\n\n$url",
                    },
                    fn ($m) => $m->to($customer->email)->subject($console ? 'Appwerk ops sign-in' : 'Appwerk sign-in'),
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

    /**
     * Signed link → Sanctum token → hand off to the page that asked for it.
     *
     * `to` is part of the signature, so it cannot be edited on the way. It is still checked
     * again here: whoever stops being an admin between the mail and the click lands on their
     * account page, not in the console. The target is one of two fixed paths, never a URL.
     */
    public function verify(Request $request, Customer $customer): RedirectResponse
    {
        abort_unless($request->hasValidSignature(), 403, 'Link expired or invalid');
        $console = $request->query('to') === 'admin' && $customer->is_admin;
        // Named apart so a console session can be told from a customer's in the token table.
        $token = $customer->createToken($console ? 'ops' : 'portal', ['portal'])->plainTextToken;
        app(Analytics::class)->record('signin_completed', $request, ['customer_id' => $customer->id]);
        $front = rtrim(config('services.frontend_url'), '/');
        $locale = in_array($request->query('locale'), ['de', 'en'], true) ? $request->query('locale') : ($customer->locale ?: 'de');

        return redirect()->away("$front/$locale/".($console ? 'admin' : 'account')."#token=$token");
    }
}
