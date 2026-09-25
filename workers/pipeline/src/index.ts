/**
 * Pipeline worker service. POST /run (Bearer WORKER_TOKEN) admits a stage
 * job with 202, executes it (one at a time per project), heartbeats the
 * API every 60s and reports the result to the per-run callback URL.
 */
import { createServer } from "node:http";
import { AGENT_MODE, runStage } from "./stages.ts";
import { GATEWAY_MODE, RELAY_MODE, gatewayComplete } from "./gateway.ts";
import { REFINE_AVAILABLE, refineIdea } from "./refine.ts";
import { changeChat } from "./changechat.ts";
import { mockupSite } from "./mockupsite.ts";
import type { StageJob, StageResult } from "./types.ts";

const PORT = Number(process.env.PORT ?? 8300);
const BIND = process.env.BIND ?? "0.0.0.0";
const TOKEN = process.env.WORKER_TOKEN ?? "";

const busyProjects = new Set<string>();

async function post(url: string, token: string, body: unknown): Promise<void> {
  const res = await fetch(url, {
    method: "POST",
    headers: {
      "content-type": "application/json",
      accept: "application/json",
      authorization: `Bearer ${token}`,
    },
    body: JSON.stringify(body),
  });
  if (!res.ok) throw new Error(`callback ${url}: HTTP ${res.status}`);
}

async function execute(job: StageJob): Promise<void> {
  const heartbeatUrl = job.callback_url.replace(/\/complete$/, "/heartbeat");
  const beat = setInterval(() => {
    post(heartbeatUrl, job.callback_token, {}).catch(() => {});
  }, 60_000);

  let result: StageResult;
  try {
    result = await runStage(job);
  } catch (err) {
    result = {
      status: "failed",
      output: {},
      error: err instanceof Error ? err.message : String(err),
      tokens_in: 0,
      tokens_out: 0,
    };
  } finally {
    clearInterval(beat);
    busyProjects.delete(job.project_id);
  }

  for (let i = 0; i < 5; i++) {
    try {
      await post(job.callback_url, job.callback_token, result);
      return;
    } catch (e) {
      console.error(`callback attempt ${i + 1} failed:`, e);
      await new Promise((r) => setTimeout(r, 5000 * (i + 1)));
    }
  }
  console.error(`giving up on callback for run ${job.run_id}`);
}

createServer((req, res) => {
  const reply = (code: number, body: unknown) => {
    res.writeHead(code, { "content-type": "application/json" });
    res.end(JSON.stringify(body));
  };

  if (req.method === "GET" && req.url === "/healthz") return reply(200, { ok: true });
  if (req.method !== "POST" || (req.url !== "/run" && req.url !== "/refine" && req.url !== "/change-chat" && req.url !== "/vision-check" && req.url !== "/mockup-site")) return reply(404, { error: "not found" });
  if (!TOKEN || req.headers.authorization !== `Bearer ${TOKEN}`) {
    return reply(403, { error: "forbidden" });
  }

  let raw = "";
  req.on("data", (c) => (raw += c));

  if (req.url === "/mockup-site") {
    // A bought design picture built into the page by the code agent (mockupsite.ts). Synchronous:
    // the API's queue job waits for it, up to twenty minutes.
    req.on("end", () => {
      if (!RELAY_MODE) return reply(503, { error: "relay unavailable" });
      let input: { id?: unknown; system?: unknown; image?: unknown };
      try {
        input = JSON.parse(raw);
      } catch {
        return reply(422, { error: "invalid payload" });
      }
      const id = String(input.id ?? "");
      const image = String(input.image ?? "");
      const system = String(input.system ?? "");
      if (!id || !system || !/^[A-Za-z0-9+/=]+$/.test(image) || image.length > 20_000_000) return reply(422, { error: "invalid input" });
      mockupSite(id, system, image)
        .then((r) => reply(200, r))
        .catch((e) => reply(502, { error: e instanceof Error ? e.message : String(e) }));
    });
    return;
  }

  if (req.url === "/vision-check") {
    // Daily health check: can the model on the chat path see a picture? The gateway drops images
    // silently when its chat backend has no imageArg (2026-09-15), and nothing else would notice.
    req.on("end", () => {
      if (!REFINE_AVAILABLE) return reply(503, { error: "gateway unavailable" });
      let image = "";
      try {
        image = String((JSON.parse(raw) as { image?: unknown }).image ?? "");
      } catch {
        return reply(422, { error: "invalid payload" });
      }
      if (!/^data:image\/png;base64,[A-Za-z0-9+/=]+$/.test(image) || image.length > 200_000) return reply(422, { error: "invalid image" });
      gatewayComplete("Answer with only the large text you read in the picture. If there is no picture, answer NO IMAGE.", [
        { type: "text", text: "What does the large text in this picture say?" },
        { type: "image_url", image_url: { url: image } },
      ])
        .then((res) => reply(200, { text: res.text.trim().slice(0, 200) }))
        .catch((e) => reply(502, { error: e instanceof Error ? e.message : String(e) }));
    });
    return;
  }

  if (req.url === "/change-chat") {
    // Change chat assistant: synchronous, one completion without tools. The API holds the prompt.
    req.on("end", () => {
      if (!REFINE_AVAILABLE) return reply(503, { error: "gateway unavailable" });
      let input: { system?: unknown; transcript?: unknown; project_id?: unknown; features?: unknown; images?: unknown };
      try {
        input = JSON.parse(raw);
      } catch {
        return reply(422, { error: "invalid payload" });
      }
      const project_id = typeof input.project_id === "string" && /^[0-9a-f-]{36}$/.test(input.project_id) ? input.project_id : "";
      if (typeof input.system !== "string" || !input.system.trim() || !project_id || !Array.isArray(input.transcript)) {
        return reply(422, { error: "invalid payload" });
      }
      const transcript = input.transcript.flatMap((m) => {
        const t = m as { id?: unknown; role?: unknown; body?: unknown; card?: unknown };
        if (typeof t.role !== "string" || typeof t.body !== "string") return [];
        const card = Array.isArray(t.card) ? t.card.filter((c): c is string => typeof c === "string") : null;
        return [{ id: typeof t.id === "number" ? t.id : undefined, role: t.role, body: t.body, card }];
      });
      const images = (Array.isArray(input.images) ? input.images : []).slice(-4).flatMap((i) => {
        const img = i as { message_id?: unknown; mime?: unknown; data?: unknown };
        return typeof img.message_id === "number" && typeof img.mime === "string" && /^image\/(png|jpeg|webp)$/.test(img.mime) && typeof img.data === "string"
          ? [{ message_id: img.message_id, mime: img.mime, data: img.data }]
          : [];
      });
      if (!transcript.length) return reply(422, { error: "empty transcript" });
      const features = Array.isArray(input.features) ? input.features.filter((f): f is string => typeof f === "string").slice(0, 12) : [];
      changeChat({ system: input.system, transcript, project_id, features, images })
        .then((out) => reply(200, out))
        .catch((e) => {
          console.error("change-chat failed:", e);
          reply(502, { error: e instanceof Error ? e.message : String(e) });
        });
    });
    return;
  }

  if (req.url === "/refine") {
    // Wizard idea refinement: synchronous, one short gateway completion.
    req.on("end", () => {
      if (!REFINE_AVAILABLE) return reply(503, { error: "gateway unavailable" });
      let input: { mode?: unknown; text?: unknown; locale?: unknown; answers?: unknown; project_id?: unknown; features?: unknown };
      try {
        input = JSON.parse(raw);
      } catch {
        return reply(422, { error: "invalid payload" });
      }
      if (typeof input.text !== "string" || input.text.trim().length < 10) return reply(422, { error: "text too short" });
      const locale = input.locale === "en" ? "en" : "de";
      const answers = Array.isArray(input.answers) ? input.answers.filter((a): a is string => typeof a === "string").slice(0, 6) : [];
      const mode = input.mode === "change" ? "change" : "idea";
      const project_id = typeof input.project_id === "string" && /^[0-9a-f-]{36}$/.test(input.project_id) ? input.project_id : undefined;
      const features = Array.isArray(input.features) ? input.features.filter((f): f is string => typeof f === "string").slice(0, 12) : [];
      refineIdea({ mode, text: input.text, locale, answers, project_id, features })
        .then((out) => reply(200, out))
        .catch((e) => {
          console.error("refine failed:", e);
          reply(502, { error: e instanceof Error ? e.message : String(e) });
        });
    });
    return;
  }

  req.on("end", () => {
    let job: StageJob;
    try {
      job = JSON.parse(raw);
      if (!job.run_id || !job.stage || !job.callback_url) throw new Error("bad payload");
    } catch {
      return reply(422, { error: "invalid job payload" });
    }
    if (busyProjects.has(job.project_id)) {
      return reply(409, { error: "project busy" });
    }
    busyProjects.add(job.project_id);
    reply(202, { accepted: job.run_id });
    void execute(job);
  });
}).listen(PORT, BIND, () =>
  console.log(`pipeline worker on ${BIND}:${PORT} (agent mode: ${AGENT_MODE}, gateway mode: ${GATEWAY_MODE}, relay mode: ${RELAY_MODE})`),
);
