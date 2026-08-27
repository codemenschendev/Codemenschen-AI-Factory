# Idea: Launch Kit pipeline + "Connect to Claude" MCP

**Date:** 2026-08-27 · **Status:** Parked idea, not scheduled · **Owner:** Patrick

Two related ideas from one day of research. Neither is on the roadmap yet;
this doc exists so the reasoning is not lost.

---

## 1. Where the ideas come from

### 1.1 Market scan (2026-08-27)

| Product | What it is | Layer | Model |
|---|---|---|---|
| Arcads.ai | AI video ads with 1,000+ AI actors, 30+ languages, Seedance/Kling/Sora | Creative production — video | Self-serve SaaS, credits |
| AdCreative.ai | Banners, copy, product photos + "conversion score", competitor insights | Creative production — static | SaaS $39–$999/mo, credits |
| Ryze (get-ryze.ai) | **MCP server** that connects Google Ads / Meta / GA to Claude; audit, campaign creation, scheduling from inside Claude | Operations — autopilot | Free connector as lead magnet → managed service |
| Glorya.ai | Done-for-you funnels for German SMBs (AI ads + landing + qualifying form, human strategist) | Service | Productized service, DACH |

Common thread: SMB / D2C / agencies without a creative or media team, sold
as *results* (ads, leads), produced in volume and iterated.

Lesson from Ryze: **don't build an agent, build tools for the agent people
already use.** Their moat is thin (anyone can wrap the Ads API) and the real
hurdle is Google/Meta API approval, not MCP. Pick domains where *we* own the
data.

### 1.2 Client-defined pipeline (received 2026-08-27, German original)

```
Claude            -> write the script (concept, lyrics, scene prompts)
Suno API          -> 10 generations (very small cost), take one of the shortest -> MP3
Local Whisper     -> audio-to-text with timestamps
gpt-image-2       -> still image for the opening
Local image-to-video -> MP4
Claude Design     <- app concept + all image assets as reference + the video
==> download finished product
```

Open questions sent to the client (English, 2026-08-27):

1. What exactly is the final deliverable — MP4 only, MP4 + landing/presentation, or an editable Claude Design canvas?
2. What are the Whisper timestamps for — burned-in karaoke subtitles, or cutting scenes to the lyrics?
3. Which local image-to-video model, and where does the GPU come from? Our server has none.
4. "Claude Design" = the canvas inside Claude Code, or Anthropic's standalone product?
5. Target length, aspect ratio (9:16 store preview / 16:9 landing / both), language(s) of the lyrics.
6. Suno account/key — exists or to be created? Selection rule beyond "shortest of 10"?

---

## 2. Idea A — "Launch Kit" as a paid add-on in AI Factory

**What:** after a build reaches `READY`, generate a promo package for the app:
jingle (30–45 s) + 9:16 store-preview video + 16:9 landing video + landing
page assembled from Claude Design. Sold as a paid add-on at checkout or as a
paid revision round (see [[ai-factory-revisions-open-items]]).

**Why it fits:** the Factory already knows the app (product/uiux stages), owns
on-brand images (assets stage), and knows the customer's store-listing
languages. Arcads/AdCreative users must describe all of that by hand.

**What exists:** pipeline stages, OpenClaw gateway for all AI calls, artifact
download, preview link, revision loop, Notify e-mails.

**What is missing:**

| Step | Tool | Cost/run | Runs where |
|---|---|---|---|
| Script + lyrics | Claude via OpenClaw gateway | ~€0 (subscription) | worker |
| Music | Suno API, 10 gens, pick shortest | cents | API |
| Lyrics timestamps | `faster-whisper` small/medium | €0 | server CPU (8 cores, fine for a 1-min track) |
| Key visual | gpt-image-2, 1 image | cents | API |
| Video assembly | **ffmpeg / Remotion**: Ken Burns on key visual + app screenshots, lyrics animated from Whisper timestamps | €0 | server CPU |
| Design package | Claude Design canvas with assets + video as reference | ~€0 | Claude |

**Bottleneck — image-to-video.** Server 65.108.206.249 has no GPU; a local
Wan/SVD/AnimateDiff run on CPU is minutes per second of video. Options:

| Option | Cost | Quality | Note |
|---|---|---|---|
| ffmpeg/Remotion motion graphics (recommended first) | ~0 | Clean, "app preview" look | Deterministic, what real app-preview tools do |
| API img2vid (Kling / Runway / Luma) | ~€0.10–0.50/clip | Cinematic | For a higher-priced tier |
| Own/rented GPU box + Wan 2.x | ~€0.50/h rented | High | Only at volume |

**Proof of concept (1–2 days, manual):** run the pipeline once for an app
already built in the Factory, using ffmpeg for video. Output: one 9:16 MP4.
That decides pricing and whether an img2vid API is needed at all.

---

## 3. Idea B — "Plan your app with Codemenschen" MCP connector

**What:** a free remote MCP server listed as a Claude connector. The user
describes an app idea inside Claude → our tool returns a spec, a price
estimate and a link to appwerk checkout / preview.

**Why:** the tool *is* the advertisement. The user is thinking about their
product at that moment; we appear as a tool, not a banner. Every call is a
lead that already has a spec.

**What exists:** `specification`/`product` stage, pricing, checkout,
magic-link accounts, preview links, the `codemenschen-manager` MCP + OpenClaw
gateway as remote-MCP infrastructure.

**What is missing:** a thin remote MCP (OAuth 2.1) exposing 3–4 tools:
`draft_spec`, `estimate_price`, `create_checkout_link`, `project_status`.
Estimate: 1–2 days on top of the manager MCP.

**Funnel:** free tool → preview → Factory build → agency for custom work.
Metrics: tool calls, specs generated, specs → orders.

**Variant with lower risk of no-traffic:** "Connect your WordPress to Claude"
(wp-sofa.chat / cmsbuddy already point this way) — same mechanism, an
audience we already serve.

**Risk:** a lead magnet only works if someone handles the leads. Wire
tool calls into the existing admin Notify e-mail so nothing is dropped.

---

## 4. Idea C — use the pipeline on ourselves

Run Idea A for appwerk / Codemenschen itself → real ads for Meta/TikTok/
LinkedIn **and** the POC shown to the client ("this video came out of your
pipeline"). Cheapest, fastest, useful even if the client does not sign.

---

## 5. Recommended order

1. **C** — POC video for appwerk (1–2 days, a few euros). Deliverable for the client conversation and for our own marketing.
2. **A** — productise as a paid add-on once the POC quality and cost are known.
3. **B** — MCP connector after pricing/positioning of the Factory is settled; it opens a channel that needs tending.

Do not start A or B before the client answers the six questions above — the
answers change the video assembly step and the deliverable format.
