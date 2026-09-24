<?php

namespace App\Domain\Privacy;

use App\Domain\Security\Audit;
use App\Http\Controllers\PrototypeController;
use App\Models\AdAccountLink;
use App\Models\AdConversion;
use App\Models\AnalyticsEvent;
use App\Models\AuditLog;
use App\Models\ChangeMessage;
use App\Models\ChangeRequest;
use App\Models\Customer;
use App\Models\LandingSignup;
use App\Models\MarketingCampaign;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Prototype;
use App\Models\Quote;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * GDPR requests about one person, by e-mail address (2026-09-24): a copy of what we hold
 * (Art. 15 and 20) and erasure (Art. 17). Run by an admin from the shell, factory:gdpr.
 *
 * Erasure removes everything the law does not make us keep. Orders and payments stay for seven
 * years (§ 132 BAO), and with them the quote the order was made from and the project that was
 * delivered, but the account is anonymised: no address, no name, no sign-in, no chat. Somebody who
 * never ordered disappears completely.
 */
class DataRequest
{
    /** Everything held about this address, as one array for a JSON file. */
    public function export(string $email): array
    {
        $email = self::normal($email);
        $c = Customer::where('email', $email)->first();
        $out = [
            'requested_for' => $email,
            'created_at' => now()->toIso8601String(),
            'controller' => 'Codemenschen GmbH, office@codemenschen.at',
            'waitlist_signups' => LandingSignup::where('email', $email)->get()
                ->map(fn ($s) => $s->only(['prototype_id', 'status', 'consent', 'source', 'ip', 'user_agent', 'created_at', 'confirmed_at', 'confirm_ip']))->all(),
        ];
        if ($c === null) {
            return $out + ['account' => null];
        }

        // A quote made before checkout carries no customer; the order is what ties it to them.
        $quoteIds = Quote::where('customer_id', $c->id)->pluck('id')->merge(Order::where('customer_id', $c->id)->pluck('quote_id'))->unique()->values();
        $protoIds = Prototype::where('customer_id', $c->id)->pluck('id');
        $projects = Project::where('customer_id', $c->id)->get();

        return $out + [
            'account' => $c->only(['id', 'email', 'name', 'locale', 'created_at', 'updated_at']) + [
                'console_admin' => (bool) $c->is_admin,
                'two_factor' => $c->two_factor_enabled_at !== null,
                'sign_ins_open' => $c->tokens()->count(),
            ],
            'quotes' => Quote::whereIn('id', $quoteIds)->get()
                ->map(fn ($q) => $q->only(['id', 'listing_slug', 'idea', 'audience', 'platform', 'features', 'price_eur', 'status', 'locale', 'ad_click', 'created_at']))->all(),
            'orders' => Order::where('customer_id', $c->id)->get()
                ->map(fn ($o) => $o->only(['id', 'quote_id', 'packages', 'total_one_time_eur', 'hosting_monthly_eur', 'ad_budget_monthly_eur', 'status',
                    'fagg_waiver', 'fagg_waiver_at', 'fagg_waiver_ip', 'terms_accepted_at', 'terms_accepted_ip', 'locale', 'created_at']))->all(),
            'payments' => Payment::whereIn('order_id', Order::where('customer_id', $c->id)->pluck('id'))->get()
                ->map(fn ($p) => $p->only(['order_id', 'amount_eur', 'status', 'created_at']))->all(),
            'projects' => $projects->map(fn (Project $p) => $p->only(['id', 'name', 'status', 'created_at']) + [
                'change_requests' => ChangeRequest::where('project_id', $p->id)->get()->map(fn ($r) => $r->only(['round', 'text', 'items', 'status', 'created_at']))->all(),
                'chat' => ChangeMessage::where('project_id', $p->id)->orderBy('id')->get()
                    ->map(fn ($m) => $m->only(['role', 'author', 'body', 'created_at']) + ['screenshots' => count($m->meta['images'] ?? [])])->all(),
            ])->all(),
            'prototypes' => Prototype::whereIn('id', $protoIds)->get()
                ->map(fn ($p) => $p->only(['id', 'status', 'prompt', 'title', 'ip', 'created_at', 'expires_at']))->all(),
            'ad_conversions' => AdConversion::whereIn('quote_id', $quoteIds)->orWhereIn('prototype_id', $protoIds)->get()
                ->map(fn ($a) => $a->only(['platform', 'event', 'status', 'happened_at', 'sent_at', 'value_eur']))->all(),
            'ad_accounts' => AdAccountLink::where('customer_id', $c->id)->get()
                ->map(fn ($l) => $l->only(['platform', 'external_id', 'name', 'status', 'created_at']))->all(),
            'site_visits' => AnalyticsEvent::where('customer_id', $c->id)->orderBy('id')->get()
                ->map(fn ($e) => $e->only(['name', 'path', 'created_at']))->all(),
            'console_log' => AuditLog::where('customer_id', $c->id)->orderBy('id')->get()
                ->map(fn ($l) => $l->only(['action', 'subject', 'status', 'ip', 'created_at']))->all(),
        ];
    }

    /**
     * Erases this address. Without $apply nothing changes and the counts say what would.
     *
     * @return array<string,int|string>
     */
    public function delete(string $email, bool $apply): array
    {
        $email = self::normal($email);
        $c = Customer::where('email', $email)->first();
        if ($c?->is_admin) {
            throw new RuntimeException('This is a console admin. Take the admin flag away first.');
        }

        $ordered = $c ? Order::where('customer_id', $c->id)->pluck('quote_id') : collect();
        $quotes = $c ? Quote::where('customer_id', $c->id)->orWhereIn('id', $ordered)->get() : collect();
        $protos = $c ? Prototype::where('customer_id', $c->id)->get() : collect();
        $projects = $c ? Project::where('customer_id', $c->id)->get() : collect();

        $running = MarketingCampaign::where(fn ($q) => $q->whereIn('project_id', $projects->pluck('id'))->orWhereIn('prototype_id', $protos->pluck('id')))
            ->whereIn('platform_status', ['active', 'publishing'])->count();
        if ($running > 0) {
            throw new RuntimeException("$running ad campaign(s) of this customer are running. Pause them first.");
        }

        $throwaway = $protos->whereNull('project_id');
        $report = [
            'waitlist_signups_deleted' => LandingSignup::where('email', $email)->count(),
            'account' => $c === null ? 'none' : ($ordered->isEmpty() ? 'deleted' : 'anonymised, orders kept 7 years (§ 132 BAO)'),
            'quotes_deleted' => $quotes->whereNotIn('id', $ordered)->count(),
            'prototypes_deleted' => $throwaway->count(),
            'chat_messages_deleted' => $projects->isEmpty() ? 0 : ChangeMessage::whereIn('project_id', $projects->pluck('id'))->count(),
            'ad_conversions_deleted' => $c ? AdConversion::whereIn('quote_id', $quotes->pluck('id'))->orWhereIn('prototype_id', $protos->pluck('id'))->count() : 0,
            'ad_accounts_unlinked' => $c ? AdAccountLink::where('customer_id', $c->id)->count() : 0,
            'orders_kept' => $ordered->count(),
        ];
        if (! $apply) {
            return $report;
        }

        DB::transaction(function () use ($email, $c, $quotes, $ordered, $protos, $throwaway, $projects) {
            LandingSignup::where('email', $email)->delete();
            if ($c === null) {
                return;
            }
            AdConversion::whereIn('quote_id', $quotes->pluck('id'))->orWhereIn('prototype_id', $protos->pluck('id'))->delete();
            AdAccountLink::where('customer_id', $c->id)->delete();
            $c->tokens()->delete();
            AnalyticsEvent::where('customer_id', $c->id)->update(['customer_id' => null]);

            foreach ($throwaway as $p) {
                File::deleteDirectory(PrototypeController::uploadDir($p->id));
            }
            Prototype::whereIn('id', $throwaway->pluck('id'))->delete();
            // A prototype that became a project stays with the project, without who asked and from where.
            Prototype::whereIn('id', $protos->whereNotNull('project_id')->pluck('id'))->update(['customer_id' => null, 'ip' => null]);

            Quote::whereIn('id', $quotes->whereNotIn('id', $ordered)->pluck('id'))->delete();
            Quote::whereIn('id', $ordered)->update(['ad_click' => null]);

            foreach ($projects as $project) {
                File::deleteDirectory(rtrim((string) config('services.media.uploads_path'), '/').'/change-shots/'.$project->id);
            }
            ChangeMessage::whereIn('project_id', $projects->pluck('id'))->delete();

            if ($ordered->isEmpty()) {
                $c->delete();
            } else {
                $c->forceFill([
                    'email' => 'deleted-'.$c->id.'@deleted.invalid', 'name' => null, 'is_admin' => false,
                    'two_factor_secret' => null, 'two_factor_enabled_at' => null, 'two_factor_recovery' => null, 'two_factor_last_step' => null,
                ])->save();
            }
        });
        // Proof that the request was carried out, without writing the address down again.
        Audit::system('gdpr.delete', null, ['email_sha256' => hash('sha256', $email)] + $report);

        return $report;
    }

    private static function normal(string $email): string
    {
        return strtolower(trim($email));
    }
}
