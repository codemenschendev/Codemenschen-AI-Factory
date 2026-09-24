"use client";

import { useEffect, useState } from "react";
import QRCode from "qrcode";
import { api, ApiError } from "@/lib/api";
import { setToken } from "@/lib/token";
import type { Dict } from "@/lib/i18n";

/**
 * The console's second step after the e-mail link (2026-09-24): set up an authenticator app once,
 * then type its six digits at every sign-in. The server refuses every /admin call until this is
 * passed, so this screen is a convenience, not the lock.
 */
export function TwoFactorGate({ token, d, enabled, onPassed }: { token: string; d: Dict; enabled: boolean; onPassed: () => void }) {
  const t = d.admin.twoFactor;
  const [setup, setSetup] = useState<{ secret: string; qr: string } | null>(null);
  const [code, setCode] = useState("");
  const [codes, setCodes] = useState<string[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    if (enabled) return;
    let alive = true;
    void (async () => {
      try {
        const r = await api<{ secret: string; uri: string }>("/admin/2fa/setup", { method: "POST", token });
        const qr = await QRCode.toDataURL(r.uri, { margin: 1, width: 220 });
        if (alive) setSetup({ secret: r.secret, qr });
      } catch {
        if (alive) setError(t.failed);
      }
    })();
    return () => {
      alive = false;
    };
  }, [enabled, token, t.failed]);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      if (enabled) {
        await api("/admin/2fa/verify", { method: "POST", token, body: JSON.stringify({ code }) });
        onPassed();
      } else {
        const r = await api<{ recovery_codes: string[] }>("/admin/2fa/enable", { method: "POST", token, body: JSON.stringify({ code }) });
        setCodes(r.recovery_codes);
      }
    } catch (err) {
      setError(err instanceof ApiError && err.status === 429 ? t.tooMany : t.wrong);
      setCode("");
    }
    setBusy(false);
  }

  const frame = (body: React.ReactNode) => (
    <div className="ops-signin">
      <div className="ops-brand">
        <span className="ops-logo" aria-hidden="true">A</span>
        <span className="ops-brand-text">
          <strong>Appwerk</strong>
          <span>{d.admin.consoleName}</span>
        </span>
      </div>
      {body}
      <p className="small" style={{ marginTop: 22 }}>
        <button className="btn btn-ghost btn-sm" onClick={() => setToken(null)}>{d.admin.signOut}</button>
      </p>
    </div>
  );

  if (codes) {
    return frame(
      <>
        <h1>{t.savedTitle}</h1>
        <p className="muted small">{t.savedText}</p>
        <pre style={{ fontSize: 14, lineHeight: 1.7, userSelect: "all", whiteSpace: "pre-wrap" }}>{codes.join("\n")}</pre>
        <button className="btn btn-primary" style={{ width: "100%", marginTop: 10 }} onClick={onPassed}>{t.savedDone}</button>
      </>,
    );
  }

  return frame(
    <form onSubmit={submit}>
      <h1>{enabled ? t.verifyTitle : t.setupTitle}</h1>
      {enabled ? (
        <p className="muted small">{t.verifyText}</p>
      ) : (
        <>
          <p className="muted small">{t.setupText}</p>
          {setup ? (
            <>
              {/* eslint-disable-next-line @next/next/no-img-element -- a data URL made in the browser, nothing to optimise */}
              <img src={setup.qr} alt={t.qrAlt} width={220} height={220} style={{ display: "block", margin: "8px 0", borderRadius: 8, background: "#fff" }} />
              <p className="small muted" style={{ margin: "0 0 10px" }}>
                {t.manual} <code style={{ userSelect: "all", wordBreak: "break-all" }}>{setup.secret}</code>
              </p>
            </>
          ) : (
            !error && <p className="est-empty">{d.admin.loading}</p>
          )}
        </>
      )}
      <label htmlFor="ops-otp" className="sr-only">{t.codeLabel}</label>
      <input
        id="ops-otp"
        inputMode={enabled ? "text" : "numeric"}
        autoComplete="one-time-code"
        autoFocus
        required
        maxLength={enabled ? 20 : 7}
        placeholder={t.codeLabel}
        value={code}
        onChange={(e) => setCode(e.target.value)}
        style={{ width: "100%", marginTop: 6, fontSize: 18, letterSpacing: "0.15em" }}
      />
      <button className="btn btn-primary" type="submit" disabled={busy || (!enabled && !setup)} style={{ width: "100%", marginTop: 10 }}>
        {busy ? "…" : enabled ? t.verify : t.enable}
      </button>
      {error && <p className="note" style={{ marginTop: 10 }}>{error}</p>}
      {enabled && <p className="small muted" style={{ marginTop: 12 }}>{t.lost}</p>}
    </form>,
  );
}
