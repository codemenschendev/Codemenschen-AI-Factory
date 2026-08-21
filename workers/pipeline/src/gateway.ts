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

export async function gatewayComplete(system: string, user: string): Promise<GatewayResult> {
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
