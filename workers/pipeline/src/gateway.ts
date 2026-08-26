/**
 * OpenClaw gateway mode — the text-producing stages (product, uiux, assets,
 * marketing) can run through the OpenClaw gateway's OpenAI-compatible
 * endpoint, reusing the gateway's own Claude subscription session. No second
 * login needed. Code-executing stages (coding/test/fix) still need the SDK
 * sandbox and therefore their own session.
 */
export const GATEWAY_URL = process.env.OPENCLAW_GATEWAY_URL ?? "";
export const GATEWAY_TOKEN = process.env.OPENCLAW_GATEWAY_TOKEN ?? "";
export const GATEWAY_MODEL = process.env.OPENCLAW_GATEWAY_MODEL ?? "openclaw/main";

export const GATEWAY_MODE = !!(GATEWAY_URL && GATEWAY_TOKEN);

/** Stages OpenClaw handles end-to-end. `test` and `release` are deterministic
    (run tests / archive) and never need a model. */
export const GATEWAY_STAGES = new Set(["product", "uiux", "coding", "fix", "assets", "marketing"]);

/** Where the repos live ON THE HOST — OpenClaw's tools run there, not in this container. */
export const REPOS_HOST_PATH = process.env.REPOS_HOST_PATH ?? "/var/www/ai-factory/infra/repos";

export interface GatewayResult {
  text: string;
  tokens_in: number;
  tokens_out: number;
}

/** Replies the gateway sends when the agent produced nothing — worth a retry, not a failure. */
const NO_RESPONSE = /^\s*no response from openclaw/i;
const RETRY_DELAYS_MS = [15_000, 45_000];

const sleep = (ms: number): Promise<void> => new Promise((r) => setTimeout(r, ms));

/**
 * One completion, retried on transient conditions: network errors, HTTP
 * 429/5xx, an empty completion, or the gateway's "No response from OpenClaw"
 * text (the agent session was busy or aborted). Each retry waits longer so a
 * short outage at the gateway does not burn the stage's attempts.
 */
export async function gatewayComplete(system: string, user: string): Promise<GatewayResult> {
  let lastError: unknown;
  for (let attempt = 0; attempt <= RETRY_DELAYS_MS.length; attempt++) {
    try {
      return await gatewayCompleteOnce(system, user);
    } catch (e) {
      lastError = e;
      const message = e instanceof Error ? e.message : String(e);
      const transient = /gateway HTTP (429|5\d\d)|empty completion|no response from openclaw|fetch failed|timeout|ECONN|socket/i.test(message);
      if (!transient || attempt === RETRY_DELAYS_MS.length) break;
      console.warn(`gateway attempt ${attempt + 1} failed (${message.slice(0, 120)}); retrying in ${RETRY_DELAYS_MS[attempt] / 1000}s`);
      await sleep(RETRY_DELAYS_MS[attempt]);
    }
  }
  throw lastError instanceof Error ? lastError : new Error(String(lastError));
}

async function gatewayCompleteOnce(system: string, user: string): Promise<GatewayResult> {
  const res = await fetch(`${GATEWAY_URL.replace(/\/$/, "")}/v1/chat/completions`, {
    method: "POST",
    headers: {
      authorization: `Bearer ${GATEWAY_TOKEN}`,
      "content-type": "application/json",
      accept: "application/json",
    },
    body: JSON.stringify({
      model: GATEWAY_MODEL,
      messages: [
        { role: "system", content: system },
        { role: "user", content: user },
      ],
      stream: false,
    }),
    signal: AbortSignal.timeout(Number(process.env.OPENCLAW_GATEWAY_TIMEOUT_MS ?? 600_000)),
  });
  if (!res.ok) throw new Error(`gateway HTTP ${res.status}: ${(await res.text()).slice(0, 300)}`);
  const data = (await res.json()) as {
    choices?: { message?: { content?: string } }[];
    usage?: { prompt_tokens?: number; completion_tokens?: number };
  };
  const text = data.choices?.[0]?.message?.content ?? "";
  if (!text) throw new Error("gateway returned empty completion");
  if (NO_RESPONSE.test(text)) throw new Error("gateway said: no response from openclaw");
  return {
    text,
    tokens_in: data.usage?.prompt_tokens ?? 0,
    tokens_out: data.usage?.completion_tokens ?? 0,
  };
}

/** Models love to wrap JSON in prose or fences; pull the first JSON value out. */
export function extractJson(text: string): unknown {
  const fenced = text.match(/```(?:json)?\s*([\s\S]*?)```/);
  const candidate = fenced ? fenced[1] : text;
  const start = candidate.search(/[[{]/);
  if (start < 0) throw new Error("no JSON in gateway response");
  const body = candidate.slice(start).trim();
  // try progressively shorter tails in case of trailing prose
  for (let end = body.length; end > 0; end--) {
    const ch = body[end - 1];
    if (ch !== "}" && ch !== "]") continue;
    try {
      return JSON.parse(body.slice(0, end));
    } catch {
      /* keep trimming */
    }
  }
  throw new Error("unparseable JSON in gateway response");
}

/* ---------------- host relay (openclaw agent CLI with tools) ---------------- */

export const RELAY_URL = process.env.OPENCLAW_RELAY_URL ?? "";
export const RELAY_TOKEN = process.env.OPENCLAW_RELAY_TOKEN ?? "";
export const RELAY_MODE = !!(RELAY_URL && RELAY_TOKEN);

export interface RelayResult {
  text: string;
  status: string;
  tokens_in: number;
  tokens_out: number;
}

/** Full OpenClaw agent turn (coding tool profile) via the host relay. */
export async function relayAgent(message: string, sessionKey: string, timeoutS = 900): Promise<RelayResult> {
  const res = await fetch(`${RELAY_URL.replace(/\/$/, "")}/agent`, {
    method: "POST",
    headers: { authorization: `Bearer ${RELAY_TOKEN}`, "content-type": "application/json" },
    body: JSON.stringify({ message, session_key: sessionKey, timeout_s: timeoutS }),
    signal: AbortSignal.timeout((timeoutS + 60) * 1000),
  });
  const data = (await res.json().catch(() => ({}))) as {
    text?: string; status?: string; error?: string;
    usage?: { input?: number; output?: number; inputTokens?: number; outputTokens?: number };
  };
  if (!res.ok) throw new Error(`relay HTTP ${res.status}: ${data.error ?? ""}`);
  return {
    text: data.text ?? "",
    status: data.status ?? "unknown",
    tokens_in: data.usage?.input ?? data.usage?.inputTokens ?? 0,
    tokens_out: data.usage?.output ?? data.usage?.outputTokens ?? 0,
  };
}
