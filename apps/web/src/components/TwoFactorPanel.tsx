"use client";

import { useCallback, useEffect, useState } from "react";
import QRCode from "qrcode";
import { api, ApiError } from "@/lib/api";
import type { Dict } from "@/lib/i18n";

type Status = { enabled: boolean; recovery_left: number };

/**
 * Switching two-factor sign-in on or off for the signed-in admin. Optional per admin (owner's
 * decision, 2026-09-24). Switching it off takes a current code, so a stolen session cannot.
 */
export function TwoFactorPanel({ token, d }: { token: string; d: Dict }) {
  const t = d.admin.twoFactor;
  const [status, setStatus] = useState<Status | null>(null);
  const [setup, setSetup] = useState<{ secret: string; qr: string } | null>(null);
  const [codes, setCodes] = useState<string[] | null>(null);
  const [code, setCode] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const read = useCallback(() => api<Status>("/admin/2fa", { token }).catch(() => null), [token]);

  useEffect(() => {
    let alive = true;
    void read().then((r) => {
      if (alive && r) setStatus(r);
    });
    return () => {
      alive = false;
    };
  }, [read]);

  const fail = (err: unknown) => {
    setError(err instanceof ApiError && err.status === 429 ? t.tooMany : t.wrong);
    setCode("");
  };

  async function start() {
    setBusy(true);
    setError(null);
    try {
      const r = await api<{ secret: string; uri: string }>("/admin/2fa/setup", { method: "POST", token });
      setSetup({ secret: r.secret, qr: await QRCode.toDataURL(r.uri, { margin: 1, width: 220 }) });
    } catch {
      setError(t.failed);
    }
    setBusy(false);
  }

  async function enable(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      const r = await api<{ recovery_codes: string[] }>("/admin/2fa/enable", { method: "POST", token, body: JSON.stringify({ code }) });
      setCodes(r.recovery_codes);
      setStatus({ enabled: true, recovery_left: r.recovery_codes.length });
      setSetup(null);
      setCode("");
    } catch (err) {
      fail(err);
    }
    setBusy(false);
  }

  async function disable(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      await api("/admin/2fa/disable", { method: "POST", token, body: JSON.stringify({ code }) });
      setCode("");
      setStatus(await read());
    } catch (err) {
      fail(err);
    }
    setBusy(false);
  }

  async function done() {
    setCodes(null);
    setStatus(await read());
  }

  if (!status) return <p className="est-empty">{d.admin.loading}</p>;

  const codeInput = (
    <>
      <label htmlFor="ops-otp-set" className="sr-only">{t.codeLabel}</label>
      <input
        id="ops-otp-set"
        autoComplete="one-time-code"
        required
        maxLength={20}
        placeholder={t.codeLabel}
        value={code}
        onChange={(e) => setCode(e.target.value)}
        style={{ width: 200, fontSize: 18, letterSpacing: "0.15em" }}
      />
    </>
  );

  return (
    <div style={{ maxWidth: 560 }}>
      <p className="muted small" style={{ marginTop: 0 }}>{t.intro}</p>
      <p>
        <span className={`badge ${status.enabled ? "badge-live" : "badge-dim"}`}>{status.enabled ? t.on : t.off}</span>
        {status.enabled && <span className="small muted" style={{ marginLeft: 10 }}>{t.recoveryLeft.replace("{n}", String(status.recovery_left))}</span>}
      </p>

      {codes ? (
        <div className="card">
          <h3>{t.savedTitle}</h3>
          <p className="muted small" style={{ margin: 0 }}>{t.savedText}</p>
          <pre style={{ fontSize: 14, lineHeight: 1.7, userSelect: "all", whiteSpace: "pre-wrap" }}>{codes.join("\n")}</pre>
          <button className="btn btn-primary btn-sm" onClick={() => void done()}>{t.savedDone}</button>
        </div>
      ) : status.enabled ? (
        <form className="card" onSubmit={disable}>
          <h3>{t.disableTitle}</h3>
          <p className="muted small" style={{ margin: 0 }}>{t.disableText}</p>
          <div style={{ display: "flex", gap: 10, flexWrap: "wrap", alignItems: "center" }}>
            {codeInput}
            <button className="btn btn-ghost btn-sm" disabled={busy}>{t.disable}</button>
          </div>
        </form>
      ) : setup ? (
        <form className="card" onSubmit={enable}>
          <h3>{t.setupTitle}</h3>
          <p className="muted small" style={{ margin: 0 }}>{t.setupText}</p>
          {/* eslint-disable-next-line @next/next/no-img-element -- a data URL made in the browser, nothing to optimise */}
          <img src={setup.qr} alt={t.qrAlt} width={220} height={220} style={{ display: "block", borderRadius: 8, background: "#fff" }} />
          <p className="small muted" style={{ margin: 0 }}>
            {t.manual} <code style={{ userSelect: "all", wordBreak: "break-all" }}>{setup.secret}</code>
          </p>
          <div style={{ display: "flex", gap: 10, flexWrap: "wrap", alignItems: "center" }}>
            {codeInput}
            <button className="btn btn-primary btn-sm" disabled={busy}>{t.enable}</button>
          </div>
        </form>
      ) : (
        <button className="btn btn-primary btn-sm" onClick={() => void start()} disabled={busy}>{t.start}</button>
      )}
      {error && <p className="note" style={{ marginTop: 10 }}>{error}</p>}
    </div>
  );
}
