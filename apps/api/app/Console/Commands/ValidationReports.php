<?php

namespace App\Console\Commands;

use App\Domain\Analytics\ValidationReport;
use App\Models\Customer;
use App\Models\MarketingCampaign;
use App\Models\Prototype;
use App\Services\Notify;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Sends each campaign's validation report once, when there is something to report: the test on
 * Meta has ended, or the landing page has been live for a week without one. To the campaign's
 * owner, and one line to the operators.
 */
class ValidationReports extends Command
{
    protected $signature = 'factory:validation-reports';

    protected $description = 'Mail the validation report of every campaign whose test or first week has ended';

    public const WEEK = 7;

    public function handle(ValidationReport $reports, Notify $notify): int
    {
        $sites = Prototype::where('kind', 'site')->whereNotNull('parent_id')->whereNotNull('published_at')->get();
        $sent = 0;
        foreach ($sites as $site) {
            $campaign = Prototype::find($site->parent_id);
            if ($campaign === null || isset($campaign->qa['report_sent_at']) || ! $campaign->isLive()) {
                continue;
            }
            $test = MarketingCampaign::where('prototype_id', $campaign->id)->latest('id')->first();
            $due = $test !== null
                ? $test->platform_status !== 'active' && $test->ends_at !== null && $test->ends_at->isPast()
                : $site->published_at->lte(now()->subDays(self::WEEK));
            if (! $due) {
                continue;
            }
            $report = $reports->build($campaign);
            $owner = $campaign->customer_id !== null ? Customer::find($campaign->customer_id) : null;
            if ($owner !== null) {
                try {
                    self::mail($owner, $campaign, $report);
                } catch (\Throwable $e) {
                    Log::warning('validation report mail failed', ['campaign' => $campaign->id, 'error' => $e->getMessage()]);

                    continue;
                }
            }
            $campaign->update(['qa' => ['report_sent_at' => now()->toIso8601String()] + ($campaign->qa ?? [])]);
            $f = $report['funnel'];
            $notify->system(sprintf('Validation report %s (%s): %s. %d visitors, %d confirmed sign-ups, %.2f EUR spent.',
                substr($campaign->id, 0, 8), mb_substr((string) $campaign->title, 0, 50), $report['verdict'], $f['visitors'], $f['confirmed'], $f['spend_eur']));
            $sent++;
        }
        $this->line("sent $sent");

        return self::SUCCESS;
    }

    /** @param  array<string,mixed>  $report */
    public static function mail(Customer $owner, Prototype $campaign, array $report): void
    {
        $de = ($owner->locale ?? 'de') === 'de';
        $f = $report['funnel'];
        $pct = fn (?float $v) => $v === null ? '-' : number_format($v * 100, 1, $de ? ',' : '.', '').' %';
        $eur = fn (?float $v) => $v === null ? '-' : number_format($v, 2, $de ? ',' : '.', '').' €';
        $verdict = [
            'go' => $de ? 'GO: Die Ziele sind erreicht. Die Idee hat Nachfrage.' : 'GO: the goals are met. The idea has demand.',
            'no_go' => $de ? 'NO-GO: Die Ziele sind nicht erreicht. So wie getestet, fehlt die Nachfrage.' : 'NO-GO: the goals are missed. As tested, the demand is not there.',
            'unclear' => $de ? 'UNKLAR: Ein Ziel ist erreicht, eines nicht. Ein zweiter Test mit geänderter Botschaft lohnt sich.' : 'UNCLEAR: one goal is met, one is not. A second test with a changed message is worth it.',
            'too_early' => $de ? 'ZU FRÜH: Zu wenige Besucher für eine sichere Aussage.' : 'TOO EARLY: too few visitors to say for sure.',
        ][$report['verdict']];
        $range = $f['rate_range'] === null ? '' : ' ('.($de ? 'wahrscheinlich zwischen ' : 'likely between ').$pct($f['rate_range'][0]).($de ? ' und ' : ' and ').$pct($f['rate_range'][1]).')';
        $url = rtrim((string) config('services.frontend_url'), '/').'/'.($de ? 'de' : 'en').'/p/'.$campaign->id;
        $lines = $de ? [
            'Hallo,', '', 'hier ist der Bericht zu deiner Kampagne "'.$campaign->title.'".', '', $verdict, '',
            'Besucher auf der Landingpage: '.$f['visitors'].($f['visitors_from_ads'] ? ' (davon über die Anzeige: '.$f['visitors_from_ads'].')' : ''),
            'Anmeldungen: '.$f['signups'].', bestätigt: '.$f['confirmed'],
            'Anmelderate: '.$pct($f['rate']).$range.', Ziel: '.$pct($report['goals']['rate']),
        ] : [
            'Hello,', '', 'here is the report on your campaign "'.$campaign->title.'".', '', $verdict, '',
            'Visitors on the landing page: '.$f['visitors'].($f['visitors_from_ads'] ? ' (from the ad: '.$f['visitors_from_ads'].')' : ''),
            'Sign-ups: '.$f['signups'].', confirmed: '.$f['confirmed'],
            'Sign-up rate: '.$pct($f['rate']).$range.', goal: '.$pct($report['goals']['rate']),
        ];
        if ($f['spend_eur'] > 0) {
            $lines[] = ($de ? 'Werbebudget ausgegeben: ' : 'Ad budget spent: ').$eur($f['spend_eur']).($f['impressions'] ? ', '.$f['impressions'].($de ? ' Einblendungen, ' : ' impressions, ').$f['clicks'].' Klicks' : '');
            $lines[] = ($de ? 'Kosten pro bestätigter Anmeldung: ' : 'Cost per confirmed sign-up: ').$eur($f['cost_per_signup_eur']).($de ? ', Ziel: ' : ', goal: ').$eur($report['goals']['cpl']);
        }
        $lines = [...$lines, '', ($de ? 'Den ganzen Bericht und deine Warteliste findest du hier:' : 'The full report and your waitlist are here:'), $url, '',
            $de ? 'Antworte einfach auf diese E-Mail, wenn du den nächsten Schritt besprechen willst.' : 'Just reply to this e-mail to talk about the next step.', '', 'Appwerk'];

        Mail::raw(implode("\n", $lines), fn ($m) => $m->to($owner->email)
            ->subject(($de ? 'Dein Kampagnen-Bericht: ' : 'Your campaign report: ').strtoupper(str_replace('_', '-', $report['verdict']))));
    }
}
