<?php

namespace App\Domain\Ai;

use App\Domain\Design\AppStoreShots;
use App\Domain\Design\DesignLibrary;
use App\Domain\Design\DesignRefs;
use App\Domain\Design\WebShots;
use App\Domain\Qa\PageAudit;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Turns a sentence into one self-contained HTML page: the clickable prototype.
 *
 * Goes through the same host sidecar as the other AI here. As with AdScriptWriter, the endpoint is
 * an AGENT with tools, so the prompt is written to keep it writing markup rather than running off
 * to build or deploy something: "you only write one HTML file, you never create files or run
 * anything".
 *
 * The output is untrusted and is only ever served inside a sandboxed, cross-origin iframe, so this
 * does not try to sanitise it. It does insist on a single self-contained file (inline CSS, no
 * external requests) both because the sandbox blocks external loads anyway and because a
 * prototype should look the same the day it expires as the day it was made.
 */
class PrototypeWriter
{
    /** Required, not nullable: the container skips a nullable class parameter and hands null. */
    public function __construct(
        private readonly DesignStudy $study,
        private readonly AppStoreShots $store,
        private readonly WebShots $web,
    ) {}

    /** The three things a visitor can ask for. `site` is the default and the original behaviour. */
    public const KINDS = ['site', 'app', 'ads'];

    /** Repair rounds at most. The second runs only if the first reduced the faults. */
    /**
     * One. A repair used to be a whole page written again, 100 to 115 seconds a round, and the
     * second round on a bad build was four minutes of the visitor's wait for two faults. Now a
     * repair is a set of patches, a fraction of the tokens, and one round of it is what a build
     * gets; what it still gets wrong after that ships with the audit on record.
     */
    private const REPAIRS = 1;

    /**
     * How long a trade's study is kept. The brief describes the trade, not the customer: what
     * every bakery site does, which colour Ankerbrot owns. Bakeries do not change in a week,
     * and the first build of the second bakery skips two minutes of looking.
     */
    private const STUDY_DAYS = 7;

    /** Screens a build studies: library screens plus store shots. */
    private const STUDY_SCREENS = 8;

    /**
     * Screens the builder sees again while drawing. The study digested all eight; the builder
     * with eight attached ran past the sidecar's limit and produced nothing. Three is the first
     * screen of the trade and one store shot per competitor, enough to keep the look honest.
     */
    private const BUILD_SCREENS = 3;

    /**
     * What real app screens do, distilled from the labelled reference library.
     *
     * A file rather than another heredoc because it is derived from data: when the library grows
     * and is labelled again, this is regenerated and the prompt improves without touching code.
     * Only the app prompt gets it; a landing page learns nothing from a phone screen.
     */
    /**
     * What the labelled reference library counted, as text.
     *
     * The pictures never reach the model; these numbers do. They are the durable half of the
     * library, and they survived the stylesheet: what real screens DO is independent of what ours
     * happen to look like.
     */
    private function conventions(string $file): string
    {
        $path = resource_path('design/'.$file);

        return is_file($path) ? "\n\n".trim((string) file_get_contents($path)) : '';
    }

    /**
     * @param  ?\Closure(string):void  $progress  told the stage as it changes: writing, auditing,
     *                                            repairing, photos
     * @return array{title:string,html:string,qa:array<string,mixed>}
     */
    public function build(string $prompt, string $kind = 'site', ?DesignRefs $refs = null,
        ?PageAudit $audit = null, ?DesignLibrary $library = null, ?PrototypePhoto $photo = null,
        ?\Closure $progress = null): array
    {
        // Where the minutes go, by step, kept with the audit. Every argument about speed so far
        // was settled by a stopwatch held by hand; this is the stopwatch.
        $t0 = microtime(true);
        $timing = [];
        $lap = function (string $step) use (&$timing, &$t0): void {
            $now = microtime(true);
            $timing[$step] = round($now - $t0, 1) + ($timing[$step] ?? 0);
            $t0 = $now;
        };
        $stage = fn (string $s) => $progress?->__invoke($s);

        // The look at the trade's best apps, before anything is drawn. Only with a library to
        // draw from: the tests build without one and expect the writer to go straight to work.
        $brief = null;
        $studied = [];
        $meta = [];
        if ($library !== null) {
            $stage('studying');
            $plan = $this->study->plan($prompt, $kind);
            if ($plan !== null) {
                // The study is of the trade, so it is kept per trade: same kind, industry,
                // country and screens means the same competitors, the same pictures and the
                // same brief, and the second customer of a trade in a week skips the looking.
                // The customer's own sentence is not in the brief; the builder reads it whole.
                $key = 'prototype-study:'.sha1(implode('|', [$kind, $plan['industry'], $plan['country'], implode(',', $plan['screens'])]));
                $cached = Cache::get($key);
                if (is_array($cached) && isset($cached['brief'], $cached['studied'])) {
                    $studied = $cached['studied'];
                    $brief = $cached['brief'];
                    $meta = $plan + ['references' => array_column($studied, 'id'), 'brief' => $brief, 'study_cached' => true];
                } else {
                    // The library's own pictures of the trade, then the world's: the apps' store
                    // screenshots for an app, the best-known businesses' live homepages for a
                    // website or an ad.
                    // Not $refs: that is the DesignRefs parameter the no-study path still uses, and
                    // shadowing it crashed every site build whose study found no pictures.
                    [$fromLibrary, $shots, $stats] = match ($kind) {
                        'app' => [
                            $library->references($plan['industry'], $plan['screens'], 4),
                            $this->store->forApps($plan['apps'], $plan['country'], 2),
                            $library->industryStats($plan['industry']),
                        ],
                        'ads' => [
                            $library->adReferences($plan['industry'], 4),
                            $this->web->forSites($plan['sites'], 2),
                            $library->adStats($plan['industry']),
                        ],
                        default => [
                            $library->siteReferences($plan['industry'], 4),
                            $this->web->forSites($plan['sites'], 3),
                            $library->webStats($plan['industry']),
                        ],
                    };
                    $studied = array_slice(array_merge($fromLibrary, $shots), 0, self::STUDY_SCREENS);
                    $brief = $this->study->study($plan, $studied, $stats, $kind);
                    $meta = $plan + ['references' => array_column($studied, 'id'), 'brief' => $brief, 'study_cached' => false];
                    if ($brief !== null) {
                        // Only the pictures the builder will be shown travel into the cache: the
                        // eight the study looked at are a megabyte, the three it hands on are not.
                        Cache::put($key, ['brief' => $brief, 'studied' => $this->forBuilder($studied)], now()->addDays(self::STUDY_DAYS));
                    }
                }
            }
            $lap('study');
        }

        $stage('writing');

        // Every kind writes its own CSS now and carries the laws and the photo contract. The ad
        // prototype was the last on the house stylesheet, and what it got for that was a landing
        // page hero above five text boxes: the sizes were right and nothing else was.
        // The wording is in resources/prompts/prototype: the kind's brief, then the photo contract
        // (class names other tools read), then the laws the page audit checks.
        $laws = "\n\n".Prompts::get('prototype/photo-slots')."\n\n".Prompts::get('prototype/laws');
        $system = match ($kind) {
            'app' => Prompts::get('prototype/app').$laws.$this->conventions('app-conventions.md'),
            'ads' => Prompts::get('prototype/ads').$laws.$this->conventions('ad-conventions.md'),
            default => Prompts::get('prototype/site').$laws.$this->conventions('web-conventions.md'),
        };

        $baseUrl = rtrim((string) config('services.ai_image.base_url'), '/');
        $token = (string) config('services.ai_image.token');

        if ($baseUrl === '' || $token === '') {
            throw new RuntimeException('The AI sidecar is not configured (services.ai_image).');
        }

        // The chain has to fire in order: the sidecar gives up at 300s and answers a clean 502
        // this client can explain, so this waits a little longer, and the queue job longer still.
        // With the old 240 here, a slow generation surfaced as a connection exception instead.
        $request = Http::baseUrl($baseUrl)->withToken($token)->acceptJson()->timeout(630)->connectTimeout(10);
        if (($backend = ChatBackend::pin()) !== null) {
            $request = $request->withHeaders(['x-openclaw-model' => $backend]);
        }

        // A reference screenshot, if one is filed for this kind. It rides along as an image and the
        // instruction that goes with it is the important half: aim at the composition, take
        // nothing else. Anything written inside a screenshot is somebody else's copy, and it is
        // also the one place an instruction could be smuggled in, so the prompt says plainly that
        // words in the picture are to be looked at and never obeyed.
        // An app brief is answered from the labelled reference library: 297 real app screens whose
        // text is legible, picked by the trade the brief is about. Until this line existed the app
        // prompt travelled with no picture at all, because every reference filed by hand is a
        // website. Anything else still uses those.
        // "Reply with the file" has to say where: the agent behind the gateway has a disk, and a
        // bakery build in Salzburg wrote baeckerei-steiner-salzburg.html to it, twice, and
        // answered "Done." Nothing on that disk ever reaches the visitor.
        $user = [['type' => 'text', 'text' => "Build a prototype for:\n\n{$prompt}\n\nReply with the HTML file only: the complete file, from <!doctype html> to </html>, as the TEXT of your reply. Do not use any tool, do not write a file to disk, do not describe what you did. The reply itself is the deliverable."]];
        if ($brief !== null) {
            // The study's brief is the requirement, and the screens it was written from travel
            // along so the builder sees what "like the leading apps" looks like.
            $n = count($studied);
            $what = match ($kind) {
                'app' => 'screens of the leading apps', 'ads' => 'ads and homepages of the leading names', default => 'pages of the leading names'
            };
            $user[] = ['type' => 'text', 'text' => "A designer studied {$n} {$what} of this trade and wrote this brief. It says what a customer of this trade will expect. Follow it; where it and your own habit differ, the brief wins.\n\nThe brief is about the trade. The customer's own sentence above is the requirement list: every feature, place, offer and fact it names must be VISIBLE, on the screen or in the section it belongs to, shown the way the trade shows it and not as a line in a list. \"See nearby drivers\" is car markers on the map before anything is typed. A feature the customer asked for and cannot see is the first thing they will ask about. Nothing the sentence does not give is invented.\n\nThe businesses and apps the brief names are COMPETITORS the designer looked at. They never appear in the page: not their names, domains, logos, colours or products. They are not the customer's clients, partners or references, and a competitor printed as proof is the one line a customer will not forgive.\n\n{$brief}"];
            $user[] = ['type' => 'text', 'text' => Prompts::get('prototype/reference')."\n\nThree of the images the brief was written from:"];
            foreach ($this->forBuilder($studied) as $i => $shot) {
                $label = 'Image '.($i + 1).': '.str_replace('_', ' ', $shot['screen_type']).($shot['note'] !== '' ? " ({$shot['note']})" : '');
                $user[] = ['type' => 'text', 'text' => $label];
                $user[] = ['type' => 'image_url', 'image_url' => ['url' => $shot['data']]];
            }
        } else {
            $ref = match ($kind) {
                'app' => $library?->reference($prompt),
                // No angle: the free prototype draws all three formats at once and is not written
                // to one story, so any labelled ad is a fair lesson in shape.
                'ads' => $library?->adReference(null, $prompt),
                'site' => $library?->siteReference($prompt),
                default => null,
            } ?? $refs?->pick($kind, $prompt);
            if ($ref) {
                $user[] = ['type' => 'text', 'text' => Prompts::get('prototype/reference').($ref['note'] !== '' ? "\n\nWhat is good about it: {$ref['note']}" : '')];
                $user[] = ['type' => 'image_url', 'image_url' => ['url' => $ref['data']]];
            }
        }

        $body = [
            'model' => config('services.ai_image.chat_model', 'openclaw/main'),
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
            // A page that writes its own CSS is roughly twice the output of one that only wrote
            // markup, and 8000 had it fighting the cap and the gateway's own 180s at once.
            'max_completion_tokens' => 16000,
        ];

        // Once more if the reply holds no page, or if the gateway fell over under it. An agent
        // that answers in prose, or is cut off before </html>, is a lost roll of the dice; a
        // gateway that answers 500 is, four times out of four so far, one that another session
        // restarted while this page was being written, and it is back five seconds later. A study
        // and minutes of a visitor's wait are worth one more roll before the build is called
        // failed. Not on 502: that is the sidecar's own timeout, and doubling it helps nobody.
        $markup = '';
        $lastReply = '';
        foreach ([1, 2] as $attempt) {
            if ($attempt === 2 && $lastReply !== '') {
                // The same request again gets the same "Done." from an agent that believes it
                // delivered. It is shown what it answered and told, once, where the file goes.
                $body['messages'] = [
                    ...$body['messages'],
                    ['role' => 'assistant', 'content' => mb_substr($lastReply, 0, 2000)],
                    ['role' => 'user', 'content' => 'Your reply held no HTML. Whatever you wrote to disk is not delivered; only the text of your reply is. Reply now with the complete HTML file, from <!doctype html> to </html>, and nothing else.'],
                ];
            }
            $res = $request->post('/v1/chat/completions', $body);

            if (! $res->successful()) {
                if ($res->status() !== 502 && $attempt === 1) {
                    Log::info('prototype: the gateway answered '.$res->status().', trying once more', ['kind' => $kind]);
                    sleep(8);

                    continue;
                }
                // The sidecar gives up on the gateway at CHAT_TIMEOUT_MS and answers 502. That is
                // a slow generation, not a broken service, and it is worth saying so plainly.
                // Operator-facing: the visitor sees the dictionary's "that did not work", the
                // admin tab shows this string, and the admin reads English.
                $msg = $res->status() === 502
                    ? 'The generation ran past the sidecar timeout (502): the page was too big for the time allowed.'
                    : 'The gateway answered '.$res->status().' twice.';
                throw new RuntimeException($msg);
            }

            $lastReply = (string) $res->json('choices.0.message.content');
            $markup = $this->extractHtml($lastReply);
            if ($markup !== '') {
                break;
            }
            Log::info('prototype: the reply held no html', ['attempt' => $attempt, 'kind' => $kind,
                'head' => mb_substr($lastReply, 0, 160)]);
        }
        if ($markup === '') {
            throw new RuntimeException('The agent answered twice without HTML. Last reply: "'.mb_substr(trim($lastReply), 0, 120).'"');
        }
        $lap('generate');
        // The title with a colon where the model put a dash. Both studied builds today were
        // clean except for "Bäckerei Wimmer – Frisches Brot in Salzburg", and each spent a
        // repair generation, a minute and a half, on that one character. The law stays and the
        // audit still catches a dash in the page; the title is the one place a machine fixes it
        // better than a model.
        $markup = $this->titleWithoutDash($markup);
        $stage('auditing');

        // The page is whole as it comes back: the model wrote the stylesheet, so there is nothing
        // to inline, and every fault the audit finds is its own and worth one repair.
        $page = $markup;
        $qa = $audit?->run($page) ?? ['ok' => null, 'findings' => [], 'skipped' => 'no auditor'];
        $lap('audit');
        // A competitor's name in the page. The link ad of one build read "Gebaut von
        // codemenschen.at. wixx.at · tante-emma-shop.at · webdorf.at": the agencies the study had
        // looked at, printed as the customer's references. Found here, repaired like any fault.
        if (($named = $this->competitorsNamed($page, $meta)) !== []) {
            $qa['findings'][] = ['severity' => 'blocking', 'check' => 'competitor-named', 'viewports' => [],
                'detail' => 'the page names competitors the study looked at; they are not the customer\'s clients or references, remove every mention',
                'elements' => $named];
            $qa['ok'] = false;
        }
        // What the model got wrong before anyone helped it. The repair overwrites the report, so
        // without this line the faults it makes most often, the ones a prompt could prevent, are
        // the ones nobody ever sees.
        $first = array_map(fn (array $f) => $f['check'].(($f['elements'][0] ?? '') !== '' ? ': '.$f['elements'][0] : ''),
            PageAudit::blocking($qa));

        // Up to two repairs, and the second only when the first helped: a model that took five
        // faults to three is worth one more pass, one that went in circles is not. Each round is
        // a generation the visitor waits through, so a round that changed nothing ends it.
        $rounds = 0;
        while (($blocking = PageAudit::repairable($qa, ownsStyle: true)) !== [] && $rounds < self::REPAIRS) {
            $rounds++;
            $stage('repairing');
            [$fixed, $why] = $this->repair($request, $system, $user, $page, $blocking);
            $lap('repair');
            $qa['repaired'] = false;
            if ($fixed === '') {
                // Record why, not just that. "The gateway gave up at 180s" and "the model
                // answered in prose" are the same empty string here and want opposite fixes.
                $qa['repair_failed'] = $why;
                break;
            }

            $after = $audit->run($fixed);
            $lap('audit');
            if (($named = $this->competitorsNamed($fixed, $meta)) !== []) {
                $after['findings'][] = ['severity' => 'blocking', 'check' => 'competitor-named', 'viewports' => [],
                    'detail' => 'the page still names competitors the study looked at; remove every mention', 'elements' => $named];
                $after['ok'] = false;
            }

            // Keep the repair only if it actually helped. A model asked to fix five things can
            // come back with a shorter page and six, and shipping that would be worse than
            // shipping the flaw we already knew about.
            if (count(PageAudit::repairable($after, ownsStyle: true)) >= count($blocking)) {
                break;
            }
            $page = $fixed;
            $qa = $after;
            $qa['repaired'] = true;
        }
        if ($rounds > 0) {
            $qa['repairs'] = $rounds;
        }

        // Last: every slot names what it would show, so the photographs are fetched once, after
        // the page has settled. Doing it before the repair pass would risk fetching for markup the
        // repair then throws away.
        if ($photo !== null) {
            $stage('photos');
            $shot = $photo->apply($page);
            $page = $shot['html'];
            $lap('photos');

            // Audit again, because the page that was audited is no longer the page that ships. A
            // dentist app came back clean and then reached 273px past a 390px screen once five
            // photographs were in it: the pictures change the layout, and the stored verdict has
            // to describe what the visitor actually gets.
            if ($shot['photo'] !== null) {
                $after = $audit?->run($page);
                $lap('audit');
                if (($after['ok'] ?? null) !== null) {
                    $qa = $after + array_intersect_key($qa, array_flip(['repaired', 'repairs', 'repair_failed']));
                }
            }

            $qa['photo'] = $shot['photo'];
            $qa['photos'] = $shot['photos'] ?? [];
            $qa['photo_source'] = $shot['source'] ?? null;
            $qa['photo_sources'] = $shot['sources'] ?? [];
            // Pexels asks for a visible credit when their API is used. It rides here and the share
            // page prints it under the phone, outside the mockup, where a credit belongs.
            $qa['photo_credit'] = $shot['credit'] ?? null;
            $qa['photo_credit_url'] = $shot['credit_url'] ?? null;
            $qa['photo_credits'] = $shot['credits'] ?? [];
        }

        $timing['total'] = round(array_sum($timing), 1);
        $qa['timing'] = $timing;
        if ($meta !== []) {
            $qa['study'] = $meta;
        }
        if ($first !== []) {
            $qa['first_faults'] = $first;
        }
        // The page without its photographs, which is what the model actually wrote and what the
        // minutes are spent on.
        $qa['bytes'] = strlen($markup);

        return ['title' => $this->titleOf($page), 'html' => $page, 'qa' => $qa];
    }

    /**
     * One more pass at the same page, with the browser's complaints attached.
     *
     * The model answers with patches, not with the page: blocks of "the lines as they are" and
     * "the lines as they should be", which are applied here. A page written again from the top
     * was 100 to 115 seconds for faults that touch three lines; the patches are ten to twenty.
     * A model that sends the whole page anyway still gets its page used, and one that sends
     * patches that match nothing has repaired nothing.
     *
     * @param  array<int,array<string,mixed>>  $user  the first user turn, replayed as it was
     * @return array{0:string,1:?string} the repaired markup, or '' plus why there is none
     */
    private function repair(PendingRequest $request, string $system,
        array $user, string $markup, array $blocking): array
    {
        $brief = PageAudit::brief($blocking);

        $res = $request->post('/v1/chat/completions', [
            'model' => config('services.ai_image.chat_model', 'openclaw/main'),
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
                ['role' => 'assistant', 'content' => $markup],
                ['role' => 'user', 'content' => <<<TXT
                    A browser opened your page at 320, 768 and 1280 pixels wide and found these
                    faults. Fix every one of them with PATCHES, not by writing the page again.

                    {$brief}

                    Reply with one or more blocks in exactly this form and nothing else:

                    <<<FIND
                    the lines to replace, copied from your page exactly, character for character
                    ===
                    the lines that replace them
                    >>>

                    Each FIND must be a short, unique passage of the page as you wrote it: whole
                    lines, three to ten of them, never a whole section. Leave the replacement empty
                    to delete the passage. Change as little as possible: same sections, same words
                    where they are not the fault, no new style block; put a CSS fix inside the
                    existing style block by patching the rule.

                    Overflow is almost always one element wider than the screen or one word that
                    cannot break. Placeholder text means write the real thing for this business.
                    A broken image means remove the tag, not point it somewhere else. A dash means
                    rewrite that sentence with a comma, a colon or a full stop, in the title too.
                    A competitor named means delete the name, the domain and the sentence around
                    it: those businesses were studied, they are not the customer's references.
                    TXT],
            ],
            'max_completion_tokens' => 4000,
        ]);

        if (! $res->successful()) {
            return ['', 'http '.$res->status()];
        }
        $reply = (string) $res->json('choices.0.message.content');

        [$patched, $applied, $missed] = self::patch($markup, $reply);
        if ($applied > 0) {
            if ($missed > 0) {
                Log::info('prototype: some patches matched nothing', ['applied' => $applied, 'missed' => $missed]);
            }

            return [$patched, null];
        }

        $html = $this->extractHtml($reply);
        if ($html !== '') {
            return [$html, null];
        }

        return ['', $missed > 0 ? 'no patch matched the page' : 'no patch in the reply'];
    }

    /**
     * The patches of a repair reply applied to the page.
     *
     * A FIND is matched exactly first, then with every run of whitespace allowed to differ,
     * because a model copies its own indentation imperfectly and a patch that fails on a tab is
     * a repair thrown away. A FIND that matches nothing, or more than one place, is skipped:
     * guessing where a patch goes is how a page gets a second footer.
     *
     * @return array{0:string,1:int,2:int} the page, patches applied, patches that matched nothing
     */
    public static function patch(string $html, string $reply): array
    {
        if (preg_match_all('/<<<FIND\R(.*?)\R===\R?(.*?)\R?>>>/s', $reply, $m, PREG_SET_ORDER) === 0) {
            return [$html, 0, 0];
        }
        $applied = 0;
        $missed = 0;
        foreach ($m as [, $find, $with]) {
            $find = rtrim($find, "\r\n");
            if (trim($find) === '') {
                $missed++;

                continue;
            }
            if (substr_count($html, $find) === 1) {
                $html = str_replace($find, $with, $html);
                $applied++;

                continue;
            }
            $loose = '/'.implode('\s+', array_map(fn (string $w) => preg_quote($w, '/'), preg_split('/\s+/', trim($find)))).'/';
            if (preg_match_all($loose, $html, $hits) === 1) {
                $html = preg_replace($loose, str_replace(['\\', '$'], ['\\\\', '\$'], $with), $html, 1);
                $applied++;
            } else {
                $missed++;
            }
        }

        return [$html, $applied, $missed];
    }

    /**
     * The first library screen, then the first store shot of each competitor, up to the cap.
     *
     * @param  list<array<string,mixed>>  $studied
     * @return list<array<string,mixed>>
     */
    private function forBuilder(array $studied): array
    {
        $library = array_values(array_filter($studied, fn (array $s) => ! isset($s['app'])));
        $store = [];
        foreach ($studied as $s) {
            if (isset($s['app']) && ! isset($store[$s['app']])) {
                $store[$s['app']] = $s;
            }
        }

        return array_slice(array_merge(array_slice($library, 0, 1), array_values($store), array_slice($library, 1)), 0, self::BUILD_SCREENS);
    }

    private function extractHtml(string $text): string
    {
        // Strip a code fence if the model added one anyway.
        if (preg_match('/```(?:html)?\s*(.*?)```/is', $text, $m) === 1) {
            $text = $m[1];
        }
        $lower = strtolower($text);
        $start = strpos($lower, '<!doctype');
        if ($start === false) {
            $start = strpos($lower, '<html');
        }
        $end = strrpos($lower, '</html>');
        if ($start === false || $end === false) {
            return '';
        }

        return trim(substr($text, $start, $end - $start + strlen('</html>')));
    }

    /**
     * The competitors the study looked at that the page mentions.
     *
     * Domains are matched whole ("wixx.at"), app and business names by word, four letters or
     * more: "Be" would match half the German copy, so "Be" goes unchecked, the cheaper mistake.
     *
     * @return list<string>
     */
    private function competitorsNamed(string $html, array $meta): array
    {
        $text = html_entity_decode(strip_tags(preg_replace('~<(script|style)\b.*?</\1>~is', ' ', $html) ?? $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $found = [];
        foreach (array_merge($meta['sites'] ?? [], $meta['apps'] ?? []) as $name) {
            $name = trim((string) $name);
            $host = (string) parse_url(str_contains($name, '://') ? $name : 'https://'.$name, PHP_URL_HOST);
            $needle = str_contains($host, '.') ? $host : $name;
            if (mb_strlen($needle) < 4) {
                continue;
            }
            if (preg_match('/(?<![\p{L}\p{N}.-])'.preg_quote($needle, '/').'(?![\p{L}\p{N}-])/iu', $text) === 1) {
                $found[] = $needle;
            }
        }

        return array_values(array_unique($found));
    }

    /** " – " and " — " in the <title> become ": ", which is how every other line here breaks. */
    private function titleWithoutDash(string $html): string
    {
        return preg_replace_callback('~(<title[^>]*>)(.*?)(</title>)~is', function (array $m): string {
            $t = preg_replace('~\s*[\x{2013}\x{2014}]\s*~u', ': ', $m[2]) ?? $m[2];

            return $m[1].$t.$m[3];
        }, $html) ?? $html;
    }

    private function titleOf(string $html): string
    {
        if (preg_match('~<title[^>]*>(.*?)</title>~is', $html, $m) === 1) {
            return mb_substr(trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5)), 0, 120);
        }

        return 'Prototype';
    }
}
