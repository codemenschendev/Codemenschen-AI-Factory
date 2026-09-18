"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { api } from "@/lib/api";
import { remember } from "@/lib/history";
import { getToken } from "@/lib/token";
import type { Dict, Locale } from "@/lib/i18n";

/**
 * The public, anonymous prompt box: this is the lead magnet. On submit it creates a prototype and
 * sends the visitor to its share page, which polls while it builds.
 *
 * What it built is remembered in this browser, so the page offers the visitor's own prototypes
 * instead of an empty box on every visit. Nothing about that leaves the machine.
 *
 * A token is sent only if the browser already has one, and only so that an operator testing the
 * funnel is not stopped by the daily cap meant for anonymous visitors. A customer's token changes
 * nothing: the API caps everyone who is not an admin.
 */
/** Mirrors PrototypeController::MAX_PROMPT. The API is the one that refuses; this only warns first. */
const MAX_PROMPT = 4000;
const DRAFT = "aifactory-proto-draft";

export function PrototypeForm({ locale, d }: { locale: Locale; d: Dict }) {
  const p = d.proto;
  const router = useRouter();
  const [prompt, setPrompt] = useState("");
  const [kind, setKind] = useState<"site" | "app" | "ads" | "email">("site");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  // Ads, and any prototype after the first, ask for an e-mail. The text typed so far is kept
  // in this browser and comes back when the sign-in link returns the visitor to the form.
  const [signIn, setSignIn] = useState<"ads" | "again" | null>(null);
  const [email, setEmail] = useState("");
  const [sent, setSent] = useState(false);

  useEffect(() => {
    try {
      const draft = JSON.parse(localStorage.getItem(DRAFT) ?? "null");
      if (draft && typeof draft.prompt === "string") {
        // eslint-disable-next-line react-hooks/set-state-in-effect -- localStorage exists only in the browser, after hydration
        setPrompt(draft.prompt);
        if (["site", "app", "ads", "email"].includes(draft.kind)) setKind(draft.kind);
        localStorage.removeItem(DRAFT);
      }
    } catch {}
  }, []);

  async function sendLink(e: React.SyntheticEvent) {
    e.preventDefault();
    try {
      localStorage.setItem(DRAFT, JSON.stringify({ prompt, kind }));
      localStorage.setItem("aifactory-next", `/${locale}/prototype`);
    } catch {}
    try {
      await api("/auth/magic-link", { method: "POST", body: JSON.stringify({ email, locale, join: true }) });
      setSent(true);
    } catch {
      setError(p.failed);
    }
  }

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    if (prompt.trim().length < 12) return;
    setBusy(true);
    setError(null);
    try {
      // Read here, not in an effect: this only runs in the browser, on a click.
      const r = await api<{ id: string }>("/prototypes", {
        method: "POST",
        token: getToken() ?? undefined,
        body: JSON.stringify({ prompt, kind }),
      });
      // Written before the redirect, so a visitor who never comes back to this tab still finds
      // the prototype in the list next time. The title is filled in by the share page.
      remember({ id: r.id, kind, prompt: prompt.trim() });
      router.push(`/${locale}/p/${r.id}`);
    } catch (err) {
      // 429 is the per-IP cap, 422 is a brief the API would not take (too long, in practice).
      // Both used to fall through to "try again", which is the one thing that does not help.
      const status = err && typeof err === "object" && "status" in err ? (err as { status: number }).status : 0;
      const body = err && typeof err === "object" && "body" in err ? (err as { body: { code?: string; reason?: string } | null }).body : null;
      if (status === 401 && body?.code === "sign_in") {
        setSignIn(body.reason === "ads" ? "ads" : "again");
        setBusy(false);
        return;
      }
      setError(status === 429 ? p.limit : status === 422 ? p.tooLong.replace("{max}", String(MAX_PROMPT)) : p.failed);
      setBusy(false);
    }
  }

  return (
    <form onSubmit={submit} style={{ display: "grid", gap: 16, maxWidth: 640 }}>
      {/* The choice comes before the sentence on purpose: what gets drawn changes what is worth
          writing, and a visitor who picks "app" describes screens rather than a company. */}
      <fieldset style={{ border: 0, padding: 0, margin: 0, display: "grid", gap: 8 }}>
        <legend style={{ padding: 0, marginBottom: 4 }}>{p.kindLabel}</legend>
        <div style={{ display: "flex", flexWrap: "wrap", gap: 8 }}>
          {(["site", "app", "ads", "email"] as const).map((k) => (
            <button
              key={k}
              type="button"
              className="tab"
              aria-pressed={kind === k}
              onClick={() => setKind(k)}
              style={{
                borderColor: kind === k ? "currentColor" : undefined,
                fontWeight: kind === k ? 600 : undefined,
              }}
            >
              {p.kinds[k]}
            </button>
          ))}
        </div>
        <p className="small muted" style={{ margin: 0 }}>{p.kindHints[kind]}</p>
      </fieldset>

      <label>
        {p.label}
        <textarea
          value={prompt}
          onChange={(e) => setPrompt(e.target.value.slice(0, MAX_PROMPT))}
          rows={prompt.length > 400 ? 10 : 4}
          maxLength={MAX_PROMPT}
          placeholder={p.hints[kind]}
          style={{ width: "100%", marginTop: 8, fontSize: "1rem", padding: 12 }}
        />
      </label>
      {/* The count only appears once there is something to count against: a visitor typing one
          sentence should not be told about a ceiling they will never reach. */}
      {prompt.length >= MAX_PROMPT / 2 && (
        <p className="small muted" style={{ margin: "-8px 0 0", textAlign: "right" }}>
          {prompt.length} / {MAX_PROMPT}
        </p>
      )}
      {error && <p className="est-empty">{error}</p>}
      {signIn === null ? (
        <button type="submit" disabled={busy || prompt.trim().length < 12} style={{ justifySelf: "start" }}>
          {busy ? p.building : p.go}
        </button>
      ) : sent ? (
        <p>{p.signIn.sent}</p>
      ) : (
        <div style={{ display: "grid", gap: 8 }}>
          <p style={{ margin: 0 }}>{p.signIn[signIn]}</p>
          <div style={{ display: "flex", flexWrap: "wrap", gap: 8 }}>
            <input
              type="email"
              required
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              placeholder={p.signIn.email}
              aria-label={p.signIn.email}
              style={{ flex: "1 1 220px", padding: 10, fontSize: "1rem" }}
            />
            <button type="button" onClick={sendLink} disabled={!/.+@.+\..+/.test(email)}>
              {p.signIn.send}
            </button>
          </div>
        </div>
      )}
    </form>
  );
}
