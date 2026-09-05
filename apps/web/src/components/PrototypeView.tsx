"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { API_BASE, api } from "@/lib/api";
import { forget, rename } from "@/lib/history";
import type { Dict, Locale } from "@/lib/i18n";

interface Meta {
  kind?: string;
  photo_credit?: string | null;
  photo_credit_url?: string | null;
  id: string;
  status: "queued" | "building" | "ready" | "failed" | "expired";
  title: string | null;
  error: string | null;
}

/**
 * The share page. Polls while the prototype builds, then shows it.
 *
 * The generated HTML is untrusted, so it is loaded from the API origin (api.appwerk, not this
 * origin) inside a sandbox that allows scripts but NOT same-origin. That gives the page a null
 * origin: its scripts run so the prototype feels alive, but they cannot reach this site's cookies
 * or localStorage. The API also sends a CSP that blocks every external request.
 */
export function PrototypeView({ id, locale, d }: { id: string; locale: Locale; d: Dict }) {
  const p = d.proto;
  const [meta, setMeta] = useState<Meta | null>(null);

  useEffect(() => {
    let stop = false;
    const tick = async () => {
      try {
        const m = await api<Meta>(`/prototypes/${id}`);
        if (stop) return;
        setMeta(m);
        // The list in the visitor's browser only knows the sentence they typed. The build knows
        // what it called itself, and an expired one has nothing left to open.
        if (m.status === "ready") rename(id, m.title);
        if (m.status === "expired") forget(id);
        if (m.status === "queued" || m.status === "building") setTimeout(tick, 3000);
      } catch {
        if (!stop) setMeta({ id, status: "failed", title: null, error: null });
      }
    };
    tick();
    return () => {
      stop = true;
    };
  }, [id]);

  if (!meta) return <p className="est-empty">{p.building}</p>;

  if (meta.status === "queued" || meta.status === "building") {
    return <p className="est-empty">{p.building}</p>;
  }
  if (meta.status === "expired") return <p className="est-empty">{p.expired}</p>;
  if (meta.status === "failed") {
    return (
      <div>
        <p className="est-empty">{p.failed}</p>
        <Link href={`/${locale}/prototype`}>{p.another}</Link>
      </div>
    );
  }

  return (
    <div>
      <div
        style={{
          display: "flex",
          gap: 12,
          flexWrap: "wrap",
          alignItems: "center",
          justifyContent: "space-between",
          marginBottom: 16,
        }}
      >
        <p className="est-empty" style={{ margin: 0 }}>
          {p.shareHint}
        </p>
        <div style={{ display: "flex", gap: 12 }}>
          <Link className="lang-toggle" href={`/${locale}/create?from=${id}`}>
            {p.makeReal}
          </Link>
          <Link className="lang-toggle" href={`/${locale}/prototype`}>
            {p.another}
          </Link>
        </div>
      </div>
      {/* An app is shown in a phone and a website in a window. Squeezing a 1120px landing page
          into 390px would be as wrong as hanging one app screen across a desktop. */}
      {meta.kind === "app" ? (
        <div className="device-stage">
          <div>
            <div className="device">
              <iframe
                title={meta.title ?? "Prototype"}
                src={`${API_BASE}/api/prototypes/${id}/raw`}
                sandbox="allow-scripts allow-popups"
              />
            </div>
            {/* Under the phone, not inside it: a credit belongs to the page, not to the mockup. */}
            {meta.photo_credit && (
              <p className="small muted" style={{ textAlign: "center", marginTop: 12 }}>
                Foto: {meta.photo_credit}
                {meta.photo_credit_url && (
                  <>
                    {" · "}
                    <a href={meta.photo_credit_url} target="_blank" rel="noopener">
                      Pexels
                    </a>
                  </>
                )}
              </p>
            )}
          </div>
        </div>
      ) : (
        <iframe
          title={meta.title ?? "Prototype"}
          src={`${API_BASE}/api/prototypes/${id}/raw`}
          sandbox="allow-scripts allow-popups"
          style={{ width: "100%", height: "80vh", border: "1px solid rgba(0,0,0,.12)", borderRadius: 12 }}
        />
      )}
    </div>
  );
}
