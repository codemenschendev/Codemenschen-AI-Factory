"use client";

import { useState } from "react";
import Link from "next/link";
import { api, ApiError } from "@/lib/api";
import { eur, type Dict, type Locale } from "@/lib/i18n";

export interface SiteInfo {
  url: string | null;
  live_at: string | null;
  live_starts_at: string | null;
  prototype_id: string | null;
  domain: string | null;
  domain_requested_at: string | null;
  server_ip: string;
  hosting_monthly_eur: number;
  hosting_free_months: number;
  imprint: Record<string, string> | null;
  imprint_complete: boolean;
  imprint_url: string | null;
}

/** The Impressum fields, in the order of the form; the required ones match Imprint::REQUIRED. */
const IMPRINT_FIELDS = ["name", "owner", "trade", "street", "zip_city", "country", "email", "phone", "vat_id", "register", "chamber", "authority", "extra"] as const;
const IMPRINT_REQUIRED = new Set(["name", "street", "zip_city", "country", "email"]);

/**
 * A bought website in the portal: where it is, the customer's own domain, and where changes
 * happen. No pipeline, no builds: the page the customer bought is the page that is live.
 */
export function SiteDetail({
  projectId,
  site,
  token,
  locale,
  d,
  hostingMonthlyEur,
  hostingFreeMonths,
}: {
  projectId: string;
  site: SiteInfo;
  token: string;
  locale: Locale;
  d: Dict;
  hostingMonthlyEur: number;
  hostingFreeMonths: number;
}) {
  const s = d.project.site;
  const [info, setInfo] = useState<SiteInfo>(site);
  const [domain, setDomain] = useState(site.domain ?? "");
  const [busy, setBusy] = useState(false);
  const [note, setNote] = useState<string | null>(null);
  const [imprint, setImprint] = useState<Record<string, string>>(site.imprint ?? {});
  const [imprintBad, setImprintBad] = useState<string[]>([]);
  const [imprintNote, setImprintNote] = useState<string | null>(null);
  const date = (iso: string) => new Date(iso).toLocaleDateString(locale === "de" ? "de-AT" : "en-IE");

  const save = async (value: string) => {
    setBusy(true);
    setNote(null);
    try {
      const r = await api<SiteInfo>(`/me/projects/${projectId}/domain`, { method: "POST", token, body: JSON.stringify({ domain: value }) });
      setInfo(r);
      setDomain(r.domain ?? "");
    } catch (e) {
      setNote(e instanceof ApiError && e.status === 422 ? s.domainInvalid : d.checkout.errors.generic);
    }
    setBusy(false);
  };

  const saveImprint = async () => {
    setBusy(true);
    setImprintNote(null);
    setImprintBad([]);
    try {
      const r = await api<SiteInfo>(`/me/projects/${projectId}/imprint`, { method: "POST", token, body: JSON.stringify(imprint) });
      setInfo(r);
      setImprintNote(s.imprintSaved);
    } catch (e) {
      const errors = e instanceof ApiError ? (e.body as { errors?: Record<string, string[]> } | null)?.errors : undefined;
      setImprintBad(Object.keys(errors ?? {}));
      setImprintNote(errors ? s.imprintInvalid : d.checkout.errors.generic);
    }
    setBusy(false);
  };

  const inputStyle = { width: "100%", padding: 10, fontSize: 14.5, border: "1px solid var(--border)", borderRadius: "var(--radius)", background: "var(--paper)", fontFamily: "var(--font-body)" } as const;

  return (
    <>
    {!info.imprint_complete && <p className="note" style={{ marginBottom: 16 }}>{s.imprintMissing}</p>}
    <div className="detail-layout">
      <div className="detail-stack">
        <div className="card">
          <h3>{s.title}</h3>
          {info.url && info.live_at ? (
            <>
              <p className="small muted" style={{ margin: 0 }}>{s.live}</p>
              <p className="num" style={{ margin: "4px 0 12px", overflowWrap: "anywhere" }}>{info.url}</p>
              <a className="btn btn-primary btn-block" href={info.url} target="_blank" rel="noopener noreferrer">{s.open}</a>
            </>
          ) : (
            <p className="est-empty" style={{ margin: 0 }}>
              {info.live_starts_at ? s.liveFrom.replace("{date}", date(info.live_starts_at)) : s.notLive}
            </p>
          )}
        </div>

        <div className="card">
          <h3>{s.domainTitle}</h3>
          <p className="small muted">{s.domainHint}</p>
          <label className="field-label" htmlFor="site-domain">{s.domainLabel}</label>
          <div style={{ display: "flex", gap: 8, flexWrap: "wrap" }}>
            <input
              id="site-domain"
              value={domain}
              onChange={(e) => setDomain(e.target.value)}
              placeholder="example.at"
              style={{ flex: "1 1 220px", padding: 12, fontSize: 14.5, border: "1px solid var(--border)", borderRadius: "var(--radius)", background: "var(--paper)", fontFamily: "var(--font-body)" }}
            />
            <button className="btn btn-primary" disabled={busy || domain.trim() === "" || domain.trim() === (info.domain ?? "")} onClick={() => void save(domain)}>
              {s.domainSave}
            </button>
          </div>
          {note && <p className="note" style={{ marginTop: 10 }}>{note}</p>}
          {info.domain && (
            <div style={{ marginTop: 12 }}>
              <p className="small" style={{ margin: 0 }}>
                {info.domain_requested_at && s.domainSaved.replace("{date}", date(info.domain_requested_at))}
              </p>
              <p className="small muted" style={{ margin: "8px 0 4px" }}>{s.domainDns}</p>
              <pre className="num" style={{ margin: 0, padding: 10, background: "var(--paper)", border: "1px solid var(--border)", borderRadius: "var(--radius)", fontSize: 13 }}>
                {`${info.domain}    A    ${info.server_ip}\nwww.${info.domain}    A    ${info.server_ip}`}
              </pre>
              <button className="lang-toggle" style={{ marginTop: 10 }} disabled={busy} onClick={() => void save("")}>{s.domainRemove}</button>
            </div>
          )}
        </div>
      </div>

      <div className="detail-stack">
        <div className="card">
          <h3>{s.changesTitle}</h3>
          <p className="small muted">{s.changesHint}</p>
          {info.prototype_id && (
            <Link className="btn btn-ghost" href={`/${locale}/p/${info.prototype_id}`}>{s.changesOpen}</Link>
          )}
        </div>
        <div className="card">
          <h3>{s.imprintTitle}</h3>
          {info.imprint_complete ? (
            <p className="small" style={{ margin: "0 0 8px" }}>
              {s.imprintDone}{" "}
              {info.imprint_url && <a href={info.imprint_url} target="_blank" rel="noopener noreferrer">{s.imprintOpen}</a>}
            </p>
          ) : (
            <p className="small muted" style={{ margin: "0 0 8px" }}>{s.imprintHint}</p>
          )}
          <div style={{ display: "grid", gap: 10 }}>
            {IMPRINT_FIELDS.map((f) => (
              <label key={f} style={{ display: "grid", gap: 4 }}>
                <span className="small" style={{ color: imprintBad.includes(f) ? "var(--danger, #b42318)" : undefined }}>{s.imprintFields[f]}</span>
                {f === "extra" ? (
                  <textarea rows={3} maxLength={1500} value={imprint[f] ?? ""} onChange={(e) => setImprint((v) => ({ ...v, [f]: e.target.value }))} style={inputStyle} />
                ) : (
                  <input
                    type={f === "email" ? "email" : "text"}
                    maxLength={200}
                    required={IMPRINT_REQUIRED.has(f)}
                    aria-invalid={imprintBad.includes(f)}
                    value={imprint[f] ?? ""}
                    onChange={(e) => setImprint((v) => ({ ...v, [f]: e.target.value }))}
                    style={{ ...inputStyle, borderColor: imprintBad.includes(f) ? "var(--danger, #b42318)" : undefined }}
                  />
                )}
              </label>
            ))}
          </div>
          <button className="btn btn-primary" style={{ marginTop: 12 }} disabled={busy} onClick={() => void saveImprint()}>{s.imprintSave}</button>
          {imprintNote && <p className="small" style={{ margin: "8px 0 0" }}>{imprintNote}</p>}
        </div>
        <div className="card">
          <h3>{s.hostingTitle}</h3>
          <p className="small muted" style={{ margin: 0 }}>
            {s.hostingHint.replace("{months}", String(hostingFreeMonths)).replace("{eur}", eur(hostingMonthlyEur, locale))}
          </p>
        </div>
      </div>
    </div>
    </>
  );
}
