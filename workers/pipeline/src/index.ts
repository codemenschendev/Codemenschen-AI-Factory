/**
 * Pipeline worker service. POST /run (Bearer WORKER_TOKEN) admits a stage
 * job with 202, executes it (one at a time per project), heartbeats the
 * API every 60s and reports the result to the per-run callback URL.
 */
import { createServer } from "node:http";
import { AGENT_MODE, runStage } from "./stages.ts";
import type { StageJob, StageResult } from "./types.ts";

const PORT = Number(process.env.PORT ?? 8300);
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
  if (req.method !== "POST" || req.url !== "/run") return reply(404, { error: "not found" });
  if (!TOKEN || req.headers.authorization !== `Bearer ${TOKEN}`) {
    return reply(403, { error: "forbidden" });
  }

  let raw = "";
  req.on("data", (c) => (raw += c));
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
}).listen(PORT, () => console.log(`pipeline worker on :${PORT} (agent mode: ${AGENT_MODE})`));
