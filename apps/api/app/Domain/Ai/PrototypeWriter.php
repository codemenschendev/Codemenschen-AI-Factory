<?php

namespace App\Domain\Ai;

use App\Domain\Design\AppStoreShots;
use App\Domain\Design\DesignLibrary;
use App\Domain\Design\DesignRefs;
use App\Domain\Design\Layouts;
use App\Domain\Design\WebShots;
use App\Domain\Qa\ContentClaims;
use App\Domain\Qa\LayoutFit;
use App\Domain\Qa\PageAudit;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use App\Models\Setting;
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
        private readonly ProductPage $pages,
    ) {}

    /** The three things a visitor can ask for. `site` is the default and the original behaviour. */
    public const KINDS = ['site', 'app', 'ads', 'email'];

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
     * @param  list<array{path:string,name:string}>  $uploads  the pictures the visitor uploaded
     * @return array{title:string,html:string,qa:array<string,mixed>}
     */
    public function build(string $prompt, string $kind = 'site', ?DesignRefs $refs = null,
        ?PageAudit $audit = null, ?DesignLibrary $library = null, ?PrototypePhoto $photo = null,
        ?\Closure $progress = null, ?Layouts $layouts = null, array $uploads = []): array
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

        // The business's own website, when the sentence names it. Read before the trade is named,
        // because the trade, the competitors and every ad follow from what is sold, and the name
        // alone said "cash for gift cards" about a WordPress voucher plugin.
        [$site, $product] = [null, null];
        if (($domain = ProductPage::domainIn($prompt)) !== null) {
            $stage('studying');
            $site = $this->pages->read($domain);
            if ($site !== null) {
                $product = $this->study->product($prompt, $site);
                $site['images'] = $this->study->pictures($site['url'], $site['images'] ?? [],
                    fn (string $url) => $this->pages->download($url, $domain));
            }
            $lap('read');
            if ($site === null && ProductPage::sentenceIsThin($prompt, $domain)) {
                throw new SiteUnreadable($domain);
            }
        }
        // The pictures the visitor uploaded are the business's own pictures too, and chosen for
        // this build, so they come before anything read from the website.
        $uploaded = [];
        foreach ($uploads as $n => $up) {
            if (is_file((string) ($up['path'] ?? '')) && ($size = @getimagesize($up['path'])) !== false) {
                $url = 'upload:'.substr(sha1($up['path']), 0, 12).'/'.rawurlencode(($n + 1).' '.($up['name'] ?? 'picture'));
                $uploaded[$url] = ['url' => $url, 'alt' => '', 'size' => $size[0].'x'.$size[1],
                    'kind' => ProductPage::pictureKind($url, '', (string) file_get_contents($up['path'])), 'path' => $up['path']];
            }
        }
        $fetchOwn = function (string $url) use ($uploaded, $domain): ?string {
            if (isset($uploaded[$url])) {
                return (string) file_get_contents($uploaded[$url]['path']);
            }

            return $domain === null ? null : $this->pages->download($url, $domain);
        };
        if ($uploaded !== []) {
            $uploaded = $this->study->pictures('uploaded by the customer', array_values($uploaded), $fetchOwn);
        }
        $ownImages = [...$uploaded, ...($site['images'] ?? [])];

        // The owner's switch between the ad modes (admin panel, 2026-09-19): hybrid, Claude writes
        // the page while Codex renders the scenes; claude, the page with the business's own and
        // library pictures and no render; codex, Codex gets the customer's words and designs the
        // whole creative, text included, the way it does when asked directly.
        $adsMode = $kind === 'ads' ? self::adsMode() : null;
        $codexReady = config('services.ai_image.backend') === 'codex' && (string) config('services.ai_image.codex_token') !== '';
        if ($adsMode === 'codex' && $codexReady) {
            $stage('rendering');

            return $this->direct($prompt, $product, $ownImages, $fetchOwn, $site, $timing, $lap);
        }

        // What the page may state as fact: the customer's sentence and their own website.
        $facts = $site === null ? $prompt : $prompt."\n".$site['text'];

        // The look at the trade's best apps, before anything is drawn. Only with a library to
        // draw from: the tests build without one and expect the writer to go straight to work.
        $brief = null;
        $studied = [];
        $meta = [];
        // The trade study is for apps only (owner's decision, 2026-09-17). An app is compared with
        // the apps a user already has on the phone; a website, an ad or an e-mail is about THIS
        // business, and studying its competitors printed their names as references and cost a
        // minute and two model calls. The product brief from the business's own site stays for
        // every kind.
        if ($library !== null && $kind === 'app') {
            $stage('studying');
            $plan = $this->study->plan($product === null ? $prompt
                : $prompt."\n\nWhat the business sells, read from its own website:\n".$product, $kind);
            if ($plan !== null && $site !== null) {
                // The customer's own domain is not its competitor.
                $plan['sites'] = array_values(array_filter($plan['sites'], fn ($s) => ! str_contains(strtolower($s), $domain)));
            }
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
            'email' => Prompts::get('prototype/email').$laws,
            default => Prompts::get('prototype/site').$laws.$this->conventions('web-conventions.md'),
        };

        // A layout pack, when the operator has switched them on for this kind and this build did
        // not fall into the control group. Null is the normal answer and the page is then written
        // from nothing, exactly as before. The skeleton rides in the system prompt because it is
        // about how the page is built, not about what this business sells.
        $layout = $layouts?->pick($kind, $prompt);
        if ($layout !== null) {
            $system .= "\n\n".Prompts::get('prototype/layout')."\n\n".$layout['skeleton'];
        }

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
        if ($site !== null) {
            // What is sold, from the business's own site. Without it the model sells what the
            // name suggests; with it, the brief and the page text are the only source of facts.
            $user[] = ['type' => 'text', 'text' => Prompts::get('prototype/product', [
                'url' => $site['url'],
                'brief' => $product ?? '(no brief could be written; read the website text below yourself)',
                'page' => mb_substr($site['text'], 0, 2500),
            ])];
        }
        if ($ownImages !== []) {
            $lines = [];
            foreach ($ownImages as $n => $img) {
                $lines[] = ($n + 1).'. '.rawurldecode(basename((string) parse_url($img['url'], PHP_URL_PATH))).(isset($img['size']) ? ' ('.$img['size'].(isset($img['kind']) ? ', '.$img['kind'] : '').')' : '').(($img['shows'] ?? '') !== '' ? ': '.$img['shows'] : ($img['alt'] !== '' ? ': '.$img['alt'] : ''));
            }
            $k = count($uploaded);
            $from = match (true) {
                $k > 0 && $site !== null => 'uploaded by the customer for this build (numbers 1 to '.$k.', use these before any other) and from its website at '.$site['url'],
                $k > 0 => 'uploaded by the customer for this build',
                default => 'from its website at '.$site['url'],
            };
            $user[] = ['type' => 'text', 'text' => Prompts::get('prototype/site-images', ['from' => $from, 'images' => implode("\n", $lines)])];
        }
        if (($site['logo'] ?? null) !== null) {
            $user[] = ['type' => 'text', 'text' => Prompts::get('prototype/site-logo')];
        }
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
            'model' => config('services.ai_image.chat_model', 'openclaw/appwerk'),
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
        // The opening picture of an ad page renders on the Codex image agent WHILE the page is
        // written: in series it added a minute to a four-minute build. It is briefed from the
        // website's product brief and the business's own picture, not from the page.
        // Two since 2026-09-18 (owner's decision): the story and the square, both on the agent at once.
        $renders = $adsMode === 'hybrid' && $photo !== null && $codexReady
            ? min(2, (int) config('services.ai_image.prototype_renders', 0)) : 0;
        $jobs = array_map(fn (string $frame) => PrototypePhoto::openingJob($prompt, $product, $ownImages,
            $ownImages === [] ? null : $fetchOwn, $frame),
            array_slice(['ad-story', 'ad-square'], 0, $renders));
        $opening = $jobs === [] ? null : $jobs;
        $pictures = null;

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
            if ($opening !== null && $pictures === null) {
                $images = app(ImageService::class);
                $both = Http::pool(function ($pool) use ($baseUrl, $token, $backend, $body, $images, $jobs) {
                    $chat = $pool->as('chat')->baseUrl($baseUrl)->withToken($token)->acceptJson()->timeout(630)->connectTimeout(10);
                    if ($backend !== null) {
                        $chat = $chat->withHeaders(['x-openclaw-model' => $backend]);
                    }

                    return [$chat->post('/v1/chat/completions', $body),
                        ...array_map(fn ($job, $k) => $images->codexOn($pool, $job, 'codex'.$k), $jobs, array_keys($jobs))];
                });
                $pictures = array_map(fn ($k) => $images->codexBytes($both['codex'.$k] ?? null), array_keys($jobs));
                $lap('generate+render');
                $res = $both['chat'] ?? null;
                if ($res instanceof \Throwable) {
                    throw $res;
                }
                if (! $res instanceof \Illuminate\Http\Client\Response) {
                    throw new RuntimeException('The gateway gave no answer.');
                }
            } else {
                $res = $request->post('/v1/chat/completions', $body);
            }

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
        if ($kind === 'ads') {
            $markup = self::adFrameWidths($markup);
        }
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
        // Prices and credentials the customer never gave. The prompt forbids them and the model
        // printed them anyway, so they are checked here and repaired like any other fault.
        if (($claims = ContentClaims::findings($page, $facts, $kind)) !== []) {
            array_push($qa['findings'], ...$claims);
            $qa['ok'] = false;
        }
        // A page built on a pack that dropped what the pack was chosen for.
        if ($layout !== null && ($dropped = LayoutFit::findings($page, $layout['keeps'] ?? [], $layout['slug'])) !== []) {
            array_push($qa['findings'], ...$dropped);
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
            if (($claims = ContentClaims::findings($fixed, $facts, $kind)) !== []) {
                array_push($after['findings'], ...$claims);
                $after['ok'] = false;
            }
            if ($layout !== null && ($dropped = LayoutFit::findings($fixed, $layout['keeps'] ?? [], $layout['slug'])) !== []) {
                array_push($after['findings'], ...$dropped);
                $after['ok'] = false;
            }

            // Keep the repair only if it actually helped. A model asked to fix five things can
            // come back with a shorter page and six, and shipping that would be worse than
            // shipping the flaw we already knew about.
            if (PageAudit::weight(PageAudit::repairable($after, ownsStyle: true)) >= PageAudit::weight($blocking)) {
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
            // The opening picture of an ad page, rendered beside the page above. A render that
            // failed leaves the slot to the site's own pictures and the library.
            $render = $pictures === null ? [] : ['pictures' => $pictures, 'products' => array_column($jobs, 'product')];
            $shot = $photo->apply($page, $site === null && $ownImages === [] ? $render : $render + [
                'images' => $ownImages,
                'logo' => $site['logo'] ?? null,
                'fetch' => $fetchOwn,
                // An ad or an e-mail fills a slot the builder left unmarked with another of the
                // business's own pictures before a stock photograph: a chain-link fence from Pexels
                // in a Christmas ad for a WordPress plugin was the alternative.
                'fill' => in_array($kind, ['ads', 'email'], true),
                'avatar' => $kind === 'ads',
            ]);
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
        // Which skeleton this page followed, or that it followed none. Without this line the
        // comparison the switch exists for cannot be made after the fact.
        $qa['layout'] = $layout === null ? null : ['slug' => $layout['slug'], 'source' => $layout['source']];
        if ($meta !== []) {
            $qa['study'] = $meta;
        }
        // What the build understood the business to sell, so a wrong ad can be traced to a wrong
        // reading rather than argued about.
        if ($domain !== null) {
            $qa['product'] = ['domain' => $domain, 'url' => $site['url'] ?? null, 'read' => $site !== null, 'brief' => $product];
        }
        if ($first !== []) {
            $qa['first_faults'] = $first;
        }
        // The page without its photographs, which is what the model actually wrote and what the
        // minutes are spent on.
        $qa['bytes'] = strlen($markup);

        // The sender line of an e-mail mock-up. The prompt forbids a made-up sender address and
        // the wp-giftcard.com build printed "Gift Cards Pro <hello@wp-giftcard.com>" anyway, four
        // times. The name stays; an address nobody gave is removed here, after the last repair.
        if ($kind === 'email') {
            $page = self::withoutSenderAddress($page, $facts);
        }

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
            'model' => config('services.ai_image.chat_model', 'openclaw/appwerk'),
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

    /** "Name <someone@domain>" becomes "Name", unless the customer's sentence or website gave that address. */
    public static function withoutSenderAddress(string $html, string $facts): string
    {
        return preg_replace_callback('~\s*(?:&lt;|<)\s*([^\s<>&;"\']+@[^\s<>&;"\']+\.[a-z]{2,})\s*(?:&gt;|>)~iu',
            fn (array $m): string => stripos($facts, $m[1]) !== false ? $m[0] : '',
            $html) ?? $html;
    }

    /** " – " and " — " in the <title> become ": ", which is how every other line here breaks. */
    /**
     * Each creative claims its width in the row. The first two-creative build put the story in a
     * wrapping flex row with no basis: the article shrank to its content, the frame's width: 100%
     * followed it, and a 1080 x 1920 story came out 70px wide beside a full-size square. Written
     * after the page's own stylesheet, so it wins, and it holds in a grid as well.
     */
    /**
     * The one change a signed-in visitor may ask for. An ad Codex designed is rendered again with
     * the current picture as the reference and the change as the brief; a page Claude wrote gets
     * patches, with its pictures swapped for short tokens so the model reads the page and not a
     * megabyte of base64, and put back afterwards.
     *
     * @param  array<string,mixed>  $qa
     * @return array{title:string,html:string,qa:array<string,mixed>}
     */
    public function revise(string $html, string $kind, array $qa, string $prompt, string $change, ?PrototypePhoto $photo = null): array
    {
        $t0 = microtime(true);
        if (($qa['mode'] ?? null) === 'codex') {
            $page = $this->reviseCodex($html, $qa, $prompt, $change);
        } else {
            $page = $this->reviseClaude($html, $kind, $change);
            if ($photo !== null) {
                // A slot the change added still holds its brief: it gets a picture like any other.
                $page = $photo->apply($page, ['fill' => false])['html'];
            }
        }
        $qa['revision'] = ['request' => mb_substr($change, 0, 1000), 'seconds' => round(microtime(true) - $t0, 1), 'at' => now()->toIso8601String()];

        return ['title' => $this->titleOf($page), 'html' => $page, 'qa' => $qa];
    }

    private function reviseClaude(string $html, string $kind, string $change): string
    {
        $pictures = [];
        $lean = preg_replace_callback('~src="(data:[^"]+)"~', function (array $m) use (&$pictures): string {
            $pictures[] = $m[1];

            return 'src="img:'.count($pictures).'"';
        }, $html);

        $baseUrl = rtrim((string) config('services.ai_image.base_url'), '/');
        $request = Http::baseUrl($baseUrl)->withToken((string) config('services.ai_image.token'))->acceptJson()->timeout(630)->connectTimeout(10);
        if (($backend = ChatBackend::pin()) !== null) {
            $request = $request->withHeaders(['x-openclaw-model' => $backend]);
        }
        $res = $request->post('/v1/chat/completions', [
            'model' => config('services.ai_image.chat_model', 'openclaw/appwerk'),
            'messages' => [
                ['role' => 'system', 'content' => Prompts::get('prototype/laws')],
                ['role' => 'user', 'content' => "This is the {$kind} prototype you wrote for a customer:"],
                ['role' => 'assistant', 'content' => $lean],
                ['role' => 'user', 'content' => Prompts::get('prototype/revise', ['request' => trim($change)])],
            ],
            'max_completion_tokens' => 6000,
        ]);
        if (! $res->successful()) {
            throw new RuntimeException('The gateway answered '.$res->status().' to the change.');
        }
        $reply = (string) $res->json('choices.0.message.content');
        [$patched, $applied] = self::patch($lean, $reply);
        if ($applied === 0) {
            $patched = $this->extractHtml($reply);
            if ($patched === '') {
                throw new RuntimeException('The change came back without a patch or a page.');
            }
        }
        $patched = $this->titleWithoutDash($patched);

        return preg_replace_callback('~src="img:(\d+)"~', fn (array $m) => 'src="'.($pictures[(int) $m[1] - 1] ?? '').'"', $patched);
    }

    private function reviseCodex(string $html, array $qa, string $prompt, string $change): string
    {
        preg_match_all('~<figure class="ad ad-(\w+)"><img src="data:[^;]+;base64,([^"]+)"~', $html, $found, PREG_SET_ORDER);
        if ($found === []) {
            throw new RuntimeException('The ad holds no picture to change.');
        }
        $sizes = ['banner' => '1200x628', 'square' => '1080x1080', 'story' => '1080x1920'];
        $product = $qa['product']['brief'] ?? null;
        $brief = trim($prompt)
            .(is_string($product) && trim($product) !== '' ? "\n\nWhat the business sells, read from its own website:\n".mb_substr(trim($product), 0, 1800) : '')
            ."\n\nTHE CHANGE: the attached picture is the current ad. Keep it as it is: the same layout, logo, product, colours and words, and change only this, as the customer asked: ".trim($change);
        $jobs = [];
        foreach ($found as [, $format, $b64]) {
            $jobs[$format] = ['prompt' => $brief, 'size' => $sizes[$format] ?? '1080x1080',
                'refs' => array_values(array_filter([self::asPng((string) base64_decode($b64))])), 'creative' => true];
        }
        $images = app(ImageService::class);
        $res = Http::pool(fn ($pool) => array_map(fn ($f) => $images->codexOn($pool, $jobs[$f], $f), array_keys($jobs)));
        $done = 0;
        foreach (array_keys($jobs) as $format) {
            if (($bytes = $images->codexBytes($res[$format] ?? null)) === null) {
                continue;
            }
            $bytes = self::toSize($bytes, $jobs[$format]['size']);
            $mime = (@getimagesizefromstring($bytes)['mime'] ?? null) ?: 'image/jpeg';
            $html = preg_replace('~(<figure class="ad ad-'.$format.'"><img src=")[^"]+~', '${1}data:'.$mime.';base64,'.base64_encode($bytes), $html, 1);
            $done++;
        }
        if ($done === 0) {
            throw new RuntimeException('The image agent rendered no changed creative.');
        }

        return $html;
    }

    public const ADS_MODES = ['hybrid', 'claude', 'codex'];

    public static function adsMode(): string
    {
        // A switch that cannot be read is the default, not a failed build.
        try {
            $mode = Setting::read('ads.mode', 'hybrid');
        } catch (\Illuminate\Database\QueryException) {
            return 'hybrid';
        }

        return in_array($mode, self::ADS_MODES, true) ? $mode : 'hybrid';
    }

    /**
     * Codex as the whole ad designer: the customer's words as they wrote them, what the website
     * says it sells, and the business's own pictures (uploads, logo, product) as references. A
     * wide banner and a feed square render at once; the page only frames them.
     *
     * @param  list<array{url:string}>  $images
     * @return array{title:string,html:string,qa:array<string,mixed>}
     */
    private function direct(string $prompt, ?string $product, array $images, \Closure $fetch, ?array $site,
        array &$timing, \Closure $lap): array
    {
        $refs = [];
        if (($site['logo'] ?? null) !== null && ($logo = $fetch($site['logo'])) !== null) {
            $refs[] = $logo;
        }
        foreach ($images as $img) {
            if (count($refs) >= 4) {
                break;
            }
            if (($bytes = $fetch($img['url'])) !== null) {
                $refs[] = $bytes;
            }
        }
        // Codex reads JPEG, PNG and WebP; the logo of wp-giftcard.com is an SVG and arrived as
        // "image content omitted", so the ad spelled the name in its own type. Everything goes as PNG.
        $refs = array_values(array_filter(array_map(fn (string $b) => self::asPng($b), $refs)));
        $brief = trim($prompt)
            .($product !== null && trim($product) !== '' ? "\n\nWhat the business sells, read from its own website".(isset($site['url']) ? " {$site['url']}" : '').":\n".mb_substr(trim($product), 0, 1800) : '');
        // The platforms' own sizes: a link or display banner at 1.91:1 and a feed square. Codex
        // draws 3:2 or 1:1 and is told the exact size; the picture is cropped to it here.
        $formats = ['banner' => '1200x628', 'square' => '1080x1080'];
        $jobs = [];
        foreach ($formats as $name => $size) {
            $jobs[$name] = ['prompt' => $brief, 'size' => $size, 'refs' => $refs, 'creative' => true];
        }

        $images = app(ImageService::class);
        $res = Http::pool(fn ($pool) => array_map(fn ($name) => $images->codexOn($pool, $jobs[$name], $name), array_keys($jobs)));
        $lap('render');
        $shown = [];
        foreach (array_keys($formats) as $name) {
            if (($bytes = $images->codexBytes($res[$name] ?? null)) !== null) {
                $bytes = self::toSize($bytes, $formats[$name]);
                $mime = (@getimagesizefromstring($bytes)['mime'] ?? null) ?: 'image/png';
                $shown[$name] = 'data:'.$mime.';base64,'.base64_encode($bytes);
            }
        }
        if ($shown === []) {
            throw new RuntimeException('The image agent rendered no creative.');
        }

        $name = htmlspecialchars(isset($site['url']) ? (string) parse_url($site['url'], PHP_URL_HOST) : 'Ads', ENT_QUOTES, 'UTF-8');
        $cards = '';
        foreach ($shown as $format => $src) {
            $cards .= '<figure class="ad ad-'.$format.'"><img src="'.$src.'" alt=""><figcaption>'.$formats[$format].'</figcaption></figure>';
        }
        $html = '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<title>'.$name.': Ads</title><style>'
            .'body{margin:0;background:#f3f1ec;font-family:system-ui,sans-serif;color:#555}'
            .'.ads{display:flex;flex-wrap:wrap;gap:32px;justify-content:center;align-items:flex-start;padding:32px 16px}'
            .'.ad{margin:0;text-align:center}.ad img{display:block;width:100%;height:auto;border-radius:14px;box-shadow:0 18px 40px rgba(0,0,0,.18)}'
            .'.ad-banner{flex:1 1 620px;max-width:1200px}.ad-square{flex:0 1 420px}'
            .'figcaption{font-size:12px;margin-top:10px}</style></head><body><main class="ads">'.$cards.'</main></body></html>';

        $timing['total'] = round(array_sum($timing), 1);

        return ['title' => html_entity_decode($name, ENT_QUOTES).': Ads', 'html' => $html, 'qa' => [
            'ok' => null, 'findings' => [], 'mode' => 'codex', 'formats' => array_keys($shown),
            'refs' => count($refs), 'timing' => $timing,
            'product' => isset($site['url']) ? ['url' => $site['url'], 'brief' => $product] : null,
        ]];
    }

    /** Any picture ImageMagick can read (SVG, ICO, AVIF, ...) as a PNG at most 1600px wide; null when it cannot. */
    public static function asPng(string $bytes): ?string
    {
        $bin = collect(['/usr/bin/magick', '/opt/homebrew/bin/magick'])->first(fn (string $p) => is_executable($p));
        if ($bin === null) {
            return $bytes;
        }
        $proc = new \Symfony\Component\Process\Process([$bin, '-density', '300', '-background', 'none', '-', '-resize', '1600x1600>', 'png:-'], null, null, $bytes, 60);
        $proc->run();
        $out = $proc->getOutput();

        return $proc->isSuccessful() && strlen($out) > 200 ? $out : null;
    }

    /** The picture cropped from the centre and scaled to exactly $size; as it came when that fails. */
    public static function toSize(string $bytes, string $size): string
    {
        $bin = collect(['/usr/bin/magick', '/opt/homebrew/bin/magick'])->first(fn (string $p) => is_executable($p));
        if ($bin === null || preg_match('~^\d+x\d+$~', $size) !== 1) {
            return $bytes;
        }
        $proc = new \Symfony\Component\Process\Process([$bin, '-', '-resize', $size.'^', '-gravity', 'center', '-extent', $size, '-quality', '90', 'jpg:-'], null, null, $bytes, 60);
        $proc->run();
        $out = $proc->getOutput();

        return $proc->isSuccessful() && strlen($out) > 1000 ? $out : $bytes;
    }

    public static function adFrameWidths(string $html): string
    {
        $css = '<style>.ads>.ad-story{flex:0 1 300px;width:100%;max-width:300px;min-width:0}'
            .'.ads>.ad-square{flex:0 1 400px;width:100%;max-width:400px;min-width:0}</style>';

        return stripos($html, '</head>') !== false
            ? (string) preg_replace('~</head>~i', $css.'</head>', $html, 1)
            : $css.$html;
    }

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
