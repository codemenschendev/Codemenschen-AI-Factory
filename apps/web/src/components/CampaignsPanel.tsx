"use client";

import { useCallback, useEffect, useState } from "react";
import { api } from "@/lib/api";
import type { Dict } from "@/lib/i18n";

type PlatformStatus = "unpublished" | "publishing" | "paused" | "active" | "failed";

interface Campaign {
  id: number;
  platform: "meta" | "google";
  platform_status: PlatformStatus;
  budget_monthly_eur: number;
  ad: { id: number; kind: string } | null;
  error: string | null;
  problems: string[];
  project: { id: string; name: string };
}

/**
 * Runs the generated creatives as real campaigns, on Codemenschen's ad accounts.
 *
 * Two buttons on purpose. "Create paused" builds the campaign on the platform without spending.
 * "Switch on" is the only thing that starts spend, kept as a separate, deliberate press and
 * labelled as such. Preflight problems are shown before either is offered, so a campaign that
 * would be rejected never gets a publish button.
 */
export function CampaignsPanel({ d, token }: { d: Dict; token: string }) {
  const [campaigns, setCampaigns] = useState<Campaign[] | null>(null);
  const [platforms, setPlatforms] = useState<Record<string, boolean>>({});
  const [busy, setBusy] = useState<number | null>(null);
  const a = d.ads;

  const load = useCallback(async () => {
    const r = await api<{ campaigns: Campaign[]; platforms: Record<string, boolean> }>("/me/campaigns", { token });
    setCampaigns(r.campaigns);
    setPlatforms(r.platforms);
    return r.campaigns;
  }, [token]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- the state is set after an await inside the loader, not in the effect body
    load().catch(() => setCampaigns([]));
  }, [load]);

  // Poll only while something is mid-publish; the queue cannot push to this page.
  useEffect(() => {
    if (!campaigns?.some((c) => c.platform_status === "publishing")) return;
    const t = setInterval(() => void load().catch(() => {}), 4000);
    return () => clearInterval(t);
  }, [campaigns, load]);

  async function act(c: Campaign, path: string) {
    setBusy(c.id);
    try {
      await api(`/me/campaigns/${c.id}/${path}`, { token, method: "POST", body: "{}" });
      await load();
    } catch {
      await load();
    } finally {
      setBusy(null);
    }
  }

  if (!campaigns) return <p className="est-empty">…</p>;

  const st = (s: PlatformStatus) =>
    ({
      unpublished: a.stUnpublished,
      publishing: a.stPublishing,
      paused: a.stPaused,
      active: a.stActive,
      failed: a.stFailed,
    })[s];

  return (
    <section className="lb-card lb-camps">
      <h2>{a.campaignsTitle}</h2>
      <p className="lb-note">{a.campaignsIntro}</p>
      <p className="lb-platforms">
        {(["meta", "google"] as const).map((p) => (
          <span key={p} className={platforms[p] ? "is-on" : undefined}>
            {p}: {platforms[p] ? "✓" : a.platformOff}
          </span>
        ))}
      </p>

      {campaigns.length === 0 ? (
        <p className="lb-empty">{a.noCampaigns}</p>
      ) : (
        <ul className="lb-camp-list">
          {campaigns.map((c) => {
            const ready = platforms[c.platform] && c.problems.length === 0;
            return (
              <li key={c.id}>
                <div className="lb-camp-head">
                  <strong>
                    {c.platform} · {c.project.name.slice(0, 28)}
                  </strong>
                  <small>
                    {st(c.platform_status)} · {c.budget_monthly_eur} €/M
                  </small>
                </div>

                {c.error && <p className="pp-error">{c.error}</p>}

                {c.problems.length > 0 && (
                  <ul className="lb-problems">
                    {c.problems.slice(0, 4).map((p, i) => (
                      <li key={i}>{p}</li>
                    ))}
                  </ul>
                )}

                <div className="lb-camp-actions">
                  {["unpublished", "failed"].includes(c.platform_status) && (
                    <button type="button" className="btn lb-ghost" disabled={!ready || busy === c.id} onClick={() => act(c, "publish")}>
                      {a.publish}
                    </button>
                  )}
                  {c.platform_status === "paused" && (
                    <button type="button" className="btn btn-primary" disabled={busy === c.id} onClick={() => act(c, "activate")}>
                      {a.activate}
                    </button>
                  )}
                  {c.platform_status === "active" && (
                    <button type="button" className="btn lb-ghost" disabled={busy === c.id} onClick={() => act(c, "pause")}>
                      {a.pauseAd}
                    </button>
                  )}
                </div>
              </li>
            );
          })}
        </ul>
      )}
    </section>
  );
}
