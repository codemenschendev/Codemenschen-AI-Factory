/**
 * The change chat assistant (docs/specs/change-chat.md). The API owns the prompt wording
 * (resources/prompts/change/assistant.md), the limits and the thread; this side adds what only the
 * worker can see, the project's SPEC.md, and makes one completion without tools.
 */
import { existsSync } from "node:fs";
import { readFile } from "node:fs/promises";
import path from "node:path";
import { extractJson, gatewayComplete } from "./gateway.ts";
import { repoDir } from "./repo.ts";

export interface ChangeChatInput {
  system: string;
  transcript: { role: string; body: string; card?: string[] | null }[];
  project_id: string;
  features: string[];
}

export interface ChangeChatOutput {
  reply: string;
  questions: { q: string; options: string[] }[];
  items: { text: string }[];
  scope: "in" | "borderline" | "out";
  reason: string;
}

const ROLE_LABEL: Record<string, string> = {
  customer: "Customer",
  assistant: "You",
  operator: "Codemenschen team",
  system: "System",
};

export async function changeChat(input: ChangeChatInput): Promise<ChangeChatOutput> {
  const file = path.join(repoDir(input.project_id), "SPEC.md");
  const spec = existsSync(file) ? (await readFile(file, "utf8")).slice(0, 8000) : "(no SPEC.md found)";
  // Colours and sizes the app really uses, so a vague "our brown" becomes a hex code on the checklist.
  const tokensFile = path.join(repoDir(input.project_id), "design-tokens.json");
  const tokens = existsSync(tokensFile) ? (await readFile(tokensFile, "utf8")).slice(0, 4000) : "(none)";
  const features = input.features.length ? input.features.join(", ") : "none beyond the base app";
  const conversation = input.transcript
    .slice(-20)
    .map((m) => {
      const label = ROLE_LABEL[m.role] ?? m.role;
      const card = m.card?.length ? `\n[checklist shown: ${m.card.map((t, i) => `${i + 1}. ${t}`).join(" | ")}]` : "";
      return `${label}: ${m.body.slice(0, 2000)}${card}`;
    })
    .join("\n\n");

  const user = `Paid features: ${features}\n\nSPEC.md:\n${spec}\n\ndesign-tokens.json:\n${tokens}\n\nConversation so far (the last line is the newest):\n${conversation}`;
  const res = await gatewayComplete(input.system, user);
  const raw = extractJson(res.text) as Partial<Record<keyof ChangeChatOutput, unknown>> | null;
  if (!raw || typeof raw !== "object" || typeof raw.reply !== "string" || !raw.reply.trim()) {
    throw new Error(`change-chat: no usable reply, starts: ${res.text.slice(0, 160)}`);
  }

  const questions = (Array.isArray(raw.questions) ? raw.questions : []).slice(0, 2).flatMap((item) => {
    const q = item as { q?: unknown; options?: unknown };
    if (!q || typeof q.q !== "string" || !q.q.trim()) return [];
    const options = (Array.isArray(q.options) ? q.options : [])
      .filter((o): o is string => typeof o === "string" && o.trim() !== "")
      .slice(0, 4)
      .map((o) => o.trim().slice(0, 80));
    return options.length >= 2 ? [{ q: q.q.trim().slice(0, 200), options }] : [];
  });
  const items = (Array.isArray(raw.items) ? raw.items : []).slice(0, 8).flatMap((item) => {
    const text = typeof item === "string" ? item : (item as { text?: unknown })?.text;
    return typeof text === "string" && text.trim() ? [{ text: text.trim().slice(0, 300) }] : [];
  });
  const scope = raw.scope === "out" || raw.scope === "borderline" ? raw.scope : "in";

  return {
    reply: raw.reply.trim().slice(0, 2000),
    questions,
    items,
    scope,
    reason: typeof raw.reason === "string" ? raw.reason.trim().slice(0, 400) : "",
  };
}
