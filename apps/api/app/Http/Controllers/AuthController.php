<?php

namespace App\Http\Controllers;

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

        return response()->json(['sent' => true]);
    }

    /** Signed link → Sanctum token → hand off to the storefront account page. */
    public function verify(Request $request, Customer $customer): RedirectResponse
    {
        abort_unless($request->hasValidSignature(), 403, 'Link expired or invalid');
        $token = $customer->createToken('portal', ['portal'])->plainTextToken;
        $front = rtrim(config('services.frontend_url'), '/');
        $locale = $request->query('locale', $customer->locale);

        return redirect()->away("$front/$locale/account#token=$token");
    }
}
