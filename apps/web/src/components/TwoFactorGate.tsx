"use client";

import { useState } from "react";
import { api, ApiError } from "@/lib/api";
import { setToken } from "@/lib/token";
import type { Dict } from "@/lib/i18n";

/**
 * The second step after the e-mail link, for an admin who switched two-factor sign-in on
 * (Security > Two-factor). The server refuses every /admin call until this is passed, so this
 * screen is a convenience, not the lock.
 */
export function TwoFactorGate({ token, d, onPassed }: { token: string; d: Dict; onPassed: () => void }) {
  const t = d.admin.twoFactor;
  const [code, setCode] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      await api("/admin/2fa/verify", { method: "POST", token, body: JSON.stringify({ code }) });
      onPassed();
    } catch (err) {
      setError(err instanceof ApiError && err.status === 429 ? t.tooMany : t.wrong);
      setCode("");
    }
    setBusy(false);
  }

  return (
    <div className="ops-signin">
      <div className="ops-brand">
        <span className="ops-logo" aria-hidden="true">A</span>
        <span className="ops-brand-text">
          <strong>Appwerk</strong>
          <span>{d.admin.consoleName}</span>
        </span>
      </div>
      <form onSubmit={submit}>
        <h1>{t.verifyTitle}</h1>
        <p className="muted small">{t.verifyText}</p>
        <label htmlFor="ops-otp" className="sr-only">{t.codeLabel}</label>
        <input
          id="ops-otp"
          autoComplete="one-time-code"
          autoFocus
          required
          maxLength={20}
          placeholder={t.codeLabel}
          value={code}
          onChange={(e) => setCode(e.target.value)}
          style={{ width: "100%", marginTop: 6, fontSize: 18, letterSpacing: "0.15em" }}
        />
        <button className="btn btn-primary" type="submit" disabled={busy} style={{ width: "100%", marginTop: 10 }}>
          {busy ? "…" : t.verify}
        </button>
        {error && <p className="note" style={{ marginTop: 10 }}>{error}</p>}
        <p className="small muted" style={{ marginTop: 12 }}>{t.lost}</p>
      </form>
      <p className="small" style={{ marginTop: 22 }}>
        <button className="btn btn-ghost btn-sm" onClick={() => setToken(null)}>{d.admin.signOut}</button>
      </p>
    </div>
  );
}
