import { execFile } from "node:child_process";
import { promisify } from "node:util";
import { readFile } from "node:fs/promises";
import { existsSync } from "node:fs";
import path from "node:path";
import type { StageJob, StageResult } from "./types.ts";
import { archiveRepo, commitAll, ensureRepo, writeRepoFile } from "./repo.ts";
import { GATEWAY_MODE, GATEWAY_STAGES, RELAY_MODE, REPOS_HOST_PATH, extractJson, gatewayComplete, relayAgent } from "./gateway.ts";

const exec = promisify(execFile);

// Agent mode: AGENT_MODE=agent|stub forces it; unset auto-detects an API
// key, a subscription OAuth token env, or mounted Claude credentials
// (~/.claude/.credentials.json) — all read natively by the SDK runtime.
export const AGENT_MODE =
  process.env.AGENT_MODE === "stub"
    ? false
    : process.env.AGENT_MODE === "agent" ||
      !!(
        process.env.ANTHROPIC_API_KEY ||
        process.env.CLAUDE_CODE_OAUTH_TOKEN ||
        existsSync(path.join(process.env.HOME ?? "/root", ".claude", ".credentials.json"))
      );

/**
 * Stage contracts (what the orchestrator consumes from `output`):
 *   product → { criteria: [{key, criterion, kind}] }        + SPEC.md in repo
 *   test    → { report: {passed, failed, …}, criteria_results: {key: status} }
 *   release → { builds: [{platform, version, artifact_path}] }
 * Agent mode drives the Claude Agent SDK inside the repo; stub mode produces
 * deterministic artifacts so the whole factory can run without an API key.
 */
export async function runStage(job: StageJob): Promise<StageResult> {
  const dir = await ensureRepo(job.project_id, job.context.stack);
  // Text stages prefer the OpenClaw gateway (one shared subscription session);
  // code stages need the SDK sandbox; everything falls back to stubs.
  // test/release are deterministic — always the local implementation.
  if (job.stage === "test" || job.stage === "release") return stubStage(job, dir);
  const isCode = job.stage === "coding" || job.stage === "fix";
  if (isCode && RELAY_MODE) return gatewayStage(job, dir);
  if (!isCode && GATEWAY_MODE && GATEWAY_STAGES.has(job.stage)) return gatewayStage(job, dir);
  const fn = AGENT_MODE ? agentStage : stubStage;
  return fn(job, dir);
}

/* ---------------- gateway mode (OpenClaw /v1/chat/completions) ---------------- */

const GATEWAY_SCHEMAS: Record<string, string> = {
  product:
    "Respond in EXACTLY this layout and nothing else:\n===SPEC===\n<full SPEC.md markdown>\n===CRITERIA===\n<JSON array of {\"key\": \"kebab-case\", \"criterion\": \"...\", \"kind\": \"automated\"|\"manual\"} with 5-12 entries>",
  uiux: "Respond in EXACTLY this layout and nothing else:\n===SCREENS===\n<full SCREENS.md markdown>\n===TOKENS===\n<JSON object of design tokens>",
  assets: 'Respond with ONLY a JSON array of {kind, locale, content} as specified.',
  marketing: 'Respond with ONLY the JSON object {campaigns: [...]} as specified.',
  coding:
    'Use your file and shell tools to do the work DIRECTLY in the repository directory given below (it is on this machine). When finished, respond with ONLY {"done": true, "summary": "<what you implemented>", "files": ["..."]}.',
  fix: 'Use your file and shell tools to fix the code DIRECTLY in the repository directory given below. Never weaken tests or criteria. Re-run `npm test` there. Respond with ONLY {"done": true, "summary": "<what you fixed>"}.',
};

async function gatewayStage(job: StageJob, dir: string): Promise<StageResult> {
  const spec = existsSync(path.join(dir, "SPEC.md")) ? await readFile(path.join(dir, "SPEC.md"), "utf8") : "";
  const hostDir = `${REPOS_HOST_PATH}/${job.project_id}`;
  const isCode = job.stage === "coding" || job.stage === "fix";
  const system = `${STAGE_PROMPTS[job.stage]}\n\n${GATEWAY_SCHEMAS[job.stage]} No prose, no markdown fences.`;
  const lastReport = job.context.last_test_report ? `\n\nLast test report:\n${JSON.stringify(job.context.last_test_report)}` : "";
  const user = `${isCode ? `Repository directory on this machine: ${hostDir}\n\n` : ""}Project context:\n${JSON.stringify(job.context, null, 2)}${spec ? `\n\nSPEC.md:\n${spec}` : ""}${isCode ? lastReport : ""}`;
  // Code stages go through the host relay (full agent with shell/file tools);
  // text stages through the completions endpoint (faster, no tools needed).
  const res = isCode
    ? await relayAgent(`${system}\n\n${user}`, `factory:${job.project_id}`, 1200)
    : await gatewayComplete(system, user);
  const section = (text: string, a: string, b?: string): string => {
    const i = text.indexOf(a);
    if (i < 0) throw new Error(`gateway ${job.stage}: missing ${a} — reply starts: ${text.slice(0, 160)}`);
    const from = i + a.length;
    const j = b ? text.indexOf(b, from) : -1;
    return (j >= 0 ? text.slice(from, j) : text.slice(from)).trim();
  };
  const parse = (text: string): unknown => {
    try {
      return extractJson(text);
    } catch (e) {
      throw new Error(`gateway ${job.stage}: ${String(e)} — reply starts: ${text.slice(0, 160)}`);
    }
  };

  switch (job.stage) {
    case "product": {
      const spec = section(res.text, "===SPEC===", "===CRITERIA===");
      const criteria = parse(section(res.text, "===CRITERIA==="));
      if (!spec || !Array.isArray(criteria)) throw new Error("gateway product output invalid");
      await writeRepoFile(dir, "SPEC.md", spec);
      await writeRepoFile(dir, "acceptance-criteria.json", JSON.stringify(criteria, null, 2));
      break;
    }
    case "uiux": {
      const screens = section(res.text, "===SCREENS===", "===TOKENS===");
      if (!screens) throw new Error("gateway uiux output invalid");
      await writeRepoFile(dir, "SCREENS.md", screens);
      try {
        const tokens = parse(section(res.text, "===TOKENS==="));
        await writeRepoFile(dir, "design-tokens.json", JSON.stringify(tokens, null, 2));
      } catch {
        /* tokens are optional */
      }
      break;
    }
    case "assets":
      await writeRepoFile(dir, "store-assets.json", JSON.stringify(parse(res.text), null, 2));
      break;
    case "marketing":
      await writeRepoFile(dir, "marketing-plan.json", JSON.stringify(parse(res.text), null, 2));
      break;
    case "coding":
    case "fix": {
      const d = parse(res.text) as { done?: boolean };
      if (!d.done) throw new Error(`gateway ${job.stage}: agent did not report done`);
      break;
    }
  }
  await commitAll(dir, `${job.stage}: gateway pass ${job.attempt}`);
  const output = await collectStageOutput(job, dir);
  return { status: "succeeded", output, tokens_in: res.tokens_in, tokens_out: res.tokens_out };
}

/* ---------------- agent mode ---------------- */

const STAGE_PROMPTS: Record<string, string> = {
  product:
    "You are the Product Agent. From the context below, write SPEC.md (features, screens, data model, out-of-scope) and acceptance-criteria.json — a JSON array of {key, criterion, kind:'automated'|'manual'} with 5-12 verifiable criteria. Keep scope strictly inside the paid feature list.",
  uiux: "You are the UI/UX Agent. Read SPEC.md; write SCREENS.md (screen map, navigation, reusable components) and design-tokens.json consistent with the spec.",
  coding:
    "You are the Coding Agent. Implement the app per SPEC.md and SCREENS.md in this repository, committing per feature. The stack is defined in package.json (create it if missing: TypeScript, runnable tests via `npm test`). Every automated acceptance criterion must have a matching test.",
  test: "You are the Test Agent. Run `npm test` (and typecheck if configured). Then write test-report.json: {passed, failed, criteria_results: {<criterion key>: 'passed'|'failed'}} judging each automated acceptance criterion from acceptance-criteria.json against the codebase and test results. Be strict.",
  fix: "You are the Fix Agent. test-report.json lists failures. Fix the code (never weaken tests or criteria), re-run `npm test`, update test-report.json.",
  release:
    "You are the Release Agent. Ensure the app builds cleanly and bump the version in package.json. Do not publish anything.",
  assets:
    "You are the Store-Asset Agent. Read SPEC.md and the app context, then write store-assets.json: a JSON array of {kind, locale, content} with kind in [name, subtitle, description, keywords, release_notes] for BOTH locales 'de' and 'en' (10 entries). Rules: honest, no hype, no unverifiable claims; keywords = comma-separated list ≤100 chars; description ≤ 4000 chars, subtitle ≤ 30 chars, name ≤ 30 chars.",
  marketing:
    "You are the Marketing Agent. Read SPEC.md and the app context, then write marketing-plan.json: {campaigns: [{platform: 'google'|'meta', strategy: {audience, angle, funnel, budget_hint}, creatives: [{kind: 'headline'|'ad_copy'|'landing'|'image_prompt', locale: 'de'|'en', content}]}]} — one campaign per platform, ≥3 headlines + 2 ad copies + 1 landing section + 1 image_prompt per campaign and locale. Rules: honest, no earnings promises, no fake urgency, comply with Google/Meta ad policies; German for DACH audience first.",
};

async function agentStage(job: StageJob, dir: string): Promise<StageResult> {
  const { query } = await import("@anthropic-ai/claude-agent-sdk");
  const prompt = `${STAGE_PROMPTS[job.stage]}\n\nProject context:\n${JSON.stringify(job.context, null, 2)}`;
  let tokensIn = 0;
  let tokensOut = 0;

  for await (const msg of query({
    prompt,
    options: {
      cwd: dir,
      permissionMode: "bypassPermissions",
      allowedTools: ["Read", "Write", "Edit", "Glob", "Grep", "Bash"],
    },
  })) {
    if (msg.type === "result") {
      tokensIn = msg.usage?.input_tokens ?? 0;
      tokensOut = msg.usage?.output_tokens ?? 0;
      if (msg.subtype !== "success") {
        return { status: "failed", output: {}, error: `agent: ${msg.subtype}`, tokens_in: tokensIn, tokens_out: tokensOut };
      }
    }
  }

  await commitAll(dir, `${job.stage}: agent pass ${job.attempt}`);
  const output = await collectStageOutput(job, dir);
  return { status: "succeeded", output, tokens_in: tokensIn, tokens_out: tokensOut };
}

/* ---------------- stub mode (no API key: deterministic dry run) ---------------- */

async function stubStage(job: StageJob, dir: string): Promise<StageResult> {
  const c = job.context;
  switch (job.stage) {
    case "product": {
      const features = c.features?.length ? c.features : ["core"];
      const criteria = [
        { key: "app-boots", criterion: "The app starts without errors", kind: "automated" },
        ...features.map((f) => ({
          key: `feat-${f}`,
          criterion: `Feature "${f}" works as specified`,
          kind: "automated",
        })),
      ];
      await writeRepoFile(
        dir,
        "SPEC.md",
        `# ${c.name}\n\n> STAGING dry-run spec (no ANTHROPIC_API_KEY configured)\n\nIdea: ${c.idea ?? c.listing_slug}\nAudience: ${c.audience} · Platform: ${c.platform} · Type: ${c.app_type}\n\n## Features\n${features.map((f) => `- ${f}`).join("\n")}\n`,
      );
      await writeRepoFile(dir, "acceptance-criteria.json", JSON.stringify(criteria, null, 2));
      await commitAll(dir, "product: spec + acceptance criteria (stub)");
      return ok({ criteria });
    }
    case "uiux": {
      await writeRepoFile(dir, "SCREENS.md", `# Screens — ${c.name}\n\n- Home\n- Detail\n- Settings\n`);
      await commitAll(dir, "uiux: screen map (stub)");
      return ok({});
    }
    case "coding":
    case "fix": {
      // Template repos ship test/run.mjs; add one always-passing case per
      // automated criterion. Non-template repos get a minimal runner.
      const criteria = JSON.parse(await readFile(path.join(dir, "acceptance-criteria.json"), "utf8"));
      if (existsSync(path.join(dir, "test", "run.mjs"))) {
        for (const cr of criteria.filter((c: { kind: string }) => c.kind === "automated")) {
          await writeRepoFile(
            dir,
            `test/cases/${cr.key}.mjs`,
            `export const key = ${JSON.stringify(cr.key)};\nexport async function run() {}\n`,
          );
        }
      } else {
        await writeRepoFile(
          dir,
          "package.json",
          JSON.stringify(
            { name: job.project_id, version: "0.1.0", private: true, scripts: { test: "node test.mjs" } },
            null,
            2,
          ),
        );
        await writeRepoFile(
          dir,
          "test.mjs",
          `import { readFileSync } from "node:fs";
const criteria = JSON.parse(readFileSync("acceptance-criteria.json", "utf8"));
const auto = criteria.filter(c => c.kind === "automated");
console.log(JSON.stringify({ passed: auto.length, failed: 0, criteria_results: Object.fromEntries(auto.map(c => [c.key, "passed"])) }));
`,
        );
      }
      await commitAll(dir, `${job.stage}: implementation (stub)`);
      return ok({});
    }
    case "test": {
      const entry = existsSync(path.join(dir, "test", "run.mjs")) ? "test/run.mjs" : "test.mjs";
      const { stdout } = await exec("node", [entry], { cwd: dir });
      const lines = stdout.trim().split("\n");
      const report = JSON.parse(lines[lines.length - 1]);
      return ok({ report, criteria_results: report.criteria_results ?? {} });
    }
    case "release": {
      const artifact = await archiveRepo(job.project_id, "0.1.0");
      return ok({ builds: [{ platform: "bundle", version: "0.1.0", artifact_path: artifact }] });
    }
    case "assets": {
      const c = job.context;
      const name = c.name.slice(0, 30);
      const mk = (locale: "de" | "en") => [
        { kind: "name", locale, content: name },
        { kind: "subtitle", locale, content: locale === "de" ? "Einfach. Fokussiert." : "Simple. Focused." },
        {
          kind: "description",
          locale,
          content:
            locale === "de"
              ? `${name} — ${c.idea ?? "eine fokussierte App"}. [STAGING-Platzhalter: echte Beschreibung entsteht im Agent-Modus.]`
              : `${name} — ${c.idea ?? "a focused app"}. [Staging placeholder: the real description is generated in agent mode.]`,
        },
        { kind: "keywords", locale, content: (c.features ?? []).concat([c.audience ?? ""]).filter(Boolean).join(",").slice(0, 100) },
        { kind: "release_notes", locale, content: locale === "de" ? "Erstes Release." : "Initial release." },
      ];
      return ok({ assets: [...mk("de"), ...mk("en")] });
    }
    case "marketing": {
      const c = job.context;
      const mkCampaign = (platform: "google" | "meta") => ({
        platform,
        strategy: {
          audience: c.audience === "b2b" ? "DACH small businesses" : "DACH consumers",
          angle: `[STAGING] One-job tool for: ${c.idea ?? c.name}`,
          funnel: platform === "google" ? "search intent" : "interest targeting",
          budget_hint: "start small, scale on CPA",
        },
        creatives: (["de", "en"] as const).flatMap((locale) => [
          { kind: "headline", locale, content: `${c.name} — ${locale === "de" ? "einfach erledigt" : "done simply"}` },
          {
            kind: "ad_copy",
            locale,
            content:
              locale === "de"
                ? `${c.name}: ${c.idea ?? "fokussierte App"}. [STAGING-Platzhalter — echte Copy entsteht im Agent-Modus.]`
                : `${c.name}: ${c.idea ?? "a focused app"}. [Staging placeholder — real copy is generated in agent mode.]`,
          },
          { kind: "landing", locale, content: locale === "de" ? `Warum ${c.name}? Ein Werkzeug, eine Aufgabe, richtig gemacht.` : `Why ${c.name}? One tool, one job, done right.` },
          { kind: "image_prompt", locale, content: `Clean product shot of a mobile app called ${c.name}, minimal, no text` },
        ]),
      });
      return ok({ campaigns: [mkCampaign("google"), mkCampaign("meta")] });
    }
  }
}

/* ---------------- shared ---------------- */

async function collectStageOutput(job: StageJob, dir: string): Promise<Record<string, unknown>> {
  const readJson = async (rel: string) => {
    const p = path.join(dir, rel);
    return existsSync(p) ? JSON.parse(await readFile(p, "utf8")) : null;
  };
  switch (job.stage) {
    case "product": {
      const criteria = await readJson("acceptance-criteria.json");
      if (!Array.isArray(criteria) || criteria.some((c) => !c.key || !c.criterion)) {
        throw new Error("product agent produced invalid acceptance-criteria.json");
      }
      return { criteria };
    }
    case "test":
    case "fix": {
      const report = await readJson("test-report.json");
      if (!report || typeof report.failed !== "number") {
        throw new Error(`${job.stage} agent produced invalid test-report.json`);
      }
      return { report, criteria_results: report.criteria_results ?? {} };
    }
    case "release": {
      const pkg = await readJson("package.json");
      const artifact = await archiveRepo(job.project_id, pkg?.version ?? "0.1.0");
      return { builds: [{ platform: "bundle", version: pkg?.version ?? "0.1.0", artifact_path: artifact }] };
    }
    case "assets": {
      const assets = await readJson("store-assets.json");
      const kinds = ["name", "subtitle", "description", "keywords", "release_notes"];
      if (!Array.isArray(assets) || assets.some((a) => !kinds.includes(a.kind) || !a.locale || !a.content)) {
        throw new Error("assets agent produced invalid store-assets.json");
      }
      return { assets };
    }
    case "marketing": {
      const plan = await readJson("marketing-plan.json");
      const platforms = ["google", "meta"];
      const kinds = ["headline", "ad_copy", "landing", "image_prompt"];
      if (
        !plan ||
        !Array.isArray(plan.campaigns) ||
        plan.campaigns.some(
          (c: { platform: string; creatives?: { kind: string; content?: string }[] }) =>
            !platforms.includes(c.platform) ||
            !Array.isArray(c.creatives) ||
            c.creatives.some((cr) => !kinds.includes(cr.kind) || !cr.content),
        )
      ) {
        throw new Error("marketing agent produced invalid marketing-plan.json");
      }
      return { campaigns: plan.campaigns };
    }
    default:
      return {};
  }
}

function ok(output: Record<string, unknown>): StageResult {
  return { status: "succeeded", output, tokens_in: 0, tokens_out: 0 };
}
