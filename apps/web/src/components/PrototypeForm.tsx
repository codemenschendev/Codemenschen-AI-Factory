"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { api } from "@/lib/api";
import type { Dict, Locale } from "@/lib/i18n";

/**
 * The public, anonymous prompt box. No token: this is the lead magnet. On submit it creates a
 * prototype and sends the visitor to its share page, which polls while it builds.
 */
export function PrototypeForm({ locale, d }: { locale: Locale; d: Dict }) {
  const p = d.proto;
  const router = useRouter();
  const [prompt, setPrompt] = useState("");
  const [kind, setKind] = useState<"site" | "app" | "ads">("site");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    if (prompt.trim().length < 12) return;
    setBusy(true);
    setError(null);
    try {
      const r = await api<{ id: string }>("/prototypes", {
        method: "POST",
        body: JSON.stringify({ prompt, kind }),
      });
      router.push(`/${locale}/p/${r.id}`);
    } catch (err) {
      // 429 from the per-IP cap is the common one; show its message.
      const msg = err && typeof err === "object" && "status" in err && (err as { status: number }).status === 429;
      setError(msg ? p.limit : p.failed);
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
          {(["site", "app", "ads"] as const).map((k) => (
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
          onChange={(e) => setPrompt(e.target.value)}
          rows={4}
          placeholder={p.hints[kind]}
          style={{ width: "100%", marginTop: 8, fontSize: "1rem", padding: 12 }}
        />
      </label>
      {error && <p className="est-empty">{error}</p>}
      <button type="submit" disabled={busy || prompt.trim().length < 12} style={{ justifySelf: "start" }}>
        {busy ? p.building : p.go}
      </button>
    </form>
  );
}
