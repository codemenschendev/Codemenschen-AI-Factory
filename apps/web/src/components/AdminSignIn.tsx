"use client";

import { useState } from "react";
import { api } from "@/lib/api";
import { setToken } from "@/lib/token";
import type { Dict, Locale } from "@/lib/i18n";

/**
 * The console's own sign-in, so an operator never leaves it to get in.
 *
 * Same magic link as a customer's, asked for with `to: admin`: the server sends the link back to
 * the console, and only for an address that belongs to an admin. For anyone else the answer on
 * this screen is identical, so the form cannot be used to find out who the admins are.
 */
export function AdminSignIn({ locale, d, denied }: { locale: Locale; d: Dict; denied: boolean }) {
  const a = d.admin;
  const [email, setEmail] = useState("");
  const [sent, setSent] = useState(false);
  const [busy, setBusy] = useState(false);
  const [failed, setFailed] = useState(false);

  async function send(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true);
    setFailed(false);
    try {
      await api("/auth/magic-link", { method: "POST", body: JSON.stringify({ email, locale, to: "admin" }) });
      setSent(true);
    } catch {
      // Five tries a minute. Saying so beats a button that silently does nothing.
      setFailed(true);
    }
    setBusy(false);
  }

  return (
    <div className="ops-signin">
      <div className="ops-brand">
        <span className="ops-logo" aria-hidden="true">A</span>
        <span className="ops-brand-text">
          <strong>Appwerk</strong>
          <span>{a.consoleName}</span>
        </span>
      </div>

      {denied && (
        <div className="note" style={{ marginBottom: 16 }}>
          <p style={{ margin: 0 }}>{a.noAccess}</p>
          <button className="btn btn-ghost btn-sm" style={{ marginTop: 8 }} onClick={() => setToken(null)}>
            {a.signOut}
          </button>
        </div>
      )}

      {sent ? (
        <>
          <h1>{a.signInSentTitle}</h1>
          <p className="muted">{a.signInSent.replace("{email}", email)}</p>
          <button className="btn btn-ghost btn-sm" onClick={() => setSent(false)}>{a.signInAgain}</button>
        </>
      ) : (
        <form onSubmit={send}>
          <h1>{a.signInTitle}</h1>
          <p className="muted small">{a.signInPrompt}</p>
          <label htmlFor="ops-email" className="sr-only">E-Mail</label>
          <input
            id="ops-email"
            type="email"
            required
            autoFocus
            autoComplete="email"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            style={{ width: "100%", marginTop: 6 }}
          />
          <button className="btn btn-primary" type="submit" disabled={busy} style={{ width: "100%", marginTop: 10 }}>
            {busy ? "…" : a.signInSend}
          </button>
          {failed && <p className="note" style={{ marginTop: 10 }}>{a.signInFailed}</p>}
        </form>
      )}
    </div>
  );
}
