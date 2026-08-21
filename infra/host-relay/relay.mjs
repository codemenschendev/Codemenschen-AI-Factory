/**
 * AI Factory host relay — runs as the `openclaw` user on the host (same
 * pattern as the manager's portal-worker): the factory worker POSTs a stage
 * prompt, the relay spawns `openclaw agent` (full OpenClaw agent with its
 * coding tool profile and its own Claude session) and returns the reply.
 * Loopback only; Bearer RELAY_TOKEN.
 */
import { createServer } from "node:http";
import { spawn } from "node:child_process";

const PORT = Number(process.env.PORT ?? 8310);
const BIND = process.env.BIND ?? "127.0.0.1";
const TOKEN = process.env.RELAY_TOKEN ?? "";
const OPENCLAW_BIN = process.env.OPENCLAW_BIN ?? "openclaw";
const AGENT = process.env.OPENCLAW_AGENT ?? "main";

const runAgent = (message, sessionKey, timeoutS) =>
  new Promise((resolve, reject) => {
    const args = ["agent", "--agent", AGENT, "--session-key", sessionKey, "--timeout", String(timeoutS), "--json", "--message", message];
    const child = spawn(OPENCLAW_BIN, args, { env: process.env, stdio: ["ignore", "pipe", "pipe"] });
    let out = "", err = "";
    const killer = setTimeout(() => child.kill("SIGTERM"), (timeoutS + 30) * 1000);
    child.stdout.on("data", (d) => (out += d));
    child.stderr.on("data", (d) => (err += d));
    child.on("error", reject);
    child.on("close", (code) => {
      clearTimeout(killer);
      const start = out.indexOf("{");
      if (start < 0) return reject(new Error((err || out || `openclaw exited ${code}`).slice(-2000)));
      try {
        const j = JSON.parse(out.slice(start));
        const text = (j.result?.payloads ?? []).map((p) => p.text ?? "").join("\n").trim();
        resolve({ status: j.status, text, stopReason: j.result?.meta?.completion?.stopReason ?? null, usage: j.result?.meta?.usage ?? null });
      } catch (e) {
        reject(new Error(`bad openclaw json: ${String(e)} :: ${out.slice(-500)}`));
      }
    });
  });

createServer((req, res) => {
  const reply = (code, body) => { res.writeHead(code, { "content-type": "application/json" }); res.end(JSON.stringify(body)); };
  if (req.method === "GET" && req.url === "/healthz") return reply(200, { ok: true });
  if (req.method !== "POST" || req.url !== "/agent") return reply(404, { error: "not found" });
  if (!TOKEN || req.headers.authorization !== `Bearer ${TOKEN}`) return reply(403, { error: "forbidden" });
  let raw = "";
  req.on("data", (c) => (raw += c));
  req.on("end", async () => {
    try {
      const { message, session_key, timeout_s } = JSON.parse(raw);
      if (!message || !session_key) return reply(422, { error: "message and session_key required" });
      const r = await runAgent(message, session_key, Math.min(Number(timeout_s) || 600, 1800));
      reply(200, r);
    } catch (e) {
      reply(500, { error: String(e.message ?? e).slice(0, 2000) });
    }
  });
}).listen(PORT, BIND, () => console.log(`ai-factory relay on ${BIND}:${PORT} → ${OPENCLAW_BIN} agent --agent ${AGENT}`));
