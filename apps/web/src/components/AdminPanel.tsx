"use client";

import { AnalyticsPanel } from "@/components/AnalyticsPanel";
import { useCallback, useEffect, useState } from "react";
import { api, ApiError } from "@/lib/api";
import { ChatShots, type ChatMessage } from "@/components/ChangeChat";
import { setToken, useToken } from "@/lib/token";
import { LibraryPanel } from "./LibraryPanel";
import { ReferencePanel } from "./ReferencePanel";
import { KeywordsPanel } from "./KeywordsPanel";
import { AdminSignIn } from "./AdminSignIn";
import { OpsIcon } from "./OpsIcon";
import { ClientAdsPanel } from "./ClientAdsPanel";
import { OwnCampaignsPanel } from "./OwnCampaignsPanel";
import { ConversionsPanel } from "./ConversionsPanel";
import { AuditPanel } from "./AuditPanel";
import { TwoFactorGate } from "./TwoFactorGate";
import { TwoFactorPanel } from "./TwoFactorPanel";
import type { Dict, Locale } from "@/lib/i18n";

interface Overview {
  me?: string;
  projects: { total: number; by_status: Record<string, number> };
  runs: { queued: number; running: number; failed_24h: number };
  ads: Record<string, number>;
  campaigns: Record<string, number>;
  /** Names of empty env keys only, never values: the tile says what is missing, not what is set. */
  connections: Record<
    string,
    {
      /** Switched off by the owner: nothing publishes there and the daily check skips it. */
      paused?: boolean;
      configured: boolean;
      missing: string[];
      verified?: { ok: boolean; at: string; account: string | null; detail: string | null } | null;
    }
  >;
  customers: number;
  /** Stripe sandbox or live, switched here; live_missing names empty .env keys, never values. */
  payments: { mode: "sandbox" | "live"; sandbox_configured: boolean; live_missing: string[] };
  /** Who makes the ad prototype: Claude's page with Codex scenes, Claude alone, or Codex alone. */
  ads_mode: "hybrid" | "claude" | "codex";
  /** Who writes the site, app and e-mail prototypes. */
  prototype_writer: "claude" | "codex";
  /** The spend guard: the kill switch, its two limits, and what runs now. */
  ads_guard: { killed: boolean; max_campaign_eur: number; max_daily_total_eur: number; running_daily_eur: number; running: number };
  /** Layout packs for the free prototypes: on or off, per kind, with a control group. */
  layouts: {
    enabled: boolean;
    kinds: LayoutKind[];
    share: number;
    off: string[];
    packs: { slug: string; kind: LayoutKind; industries: string[]; source: string; note: string; bytes: number }[];
    by_kind: Record<LayoutKind, number>;
  };
  revenue: {
    paid_orders: number;
    test_orders: number;
    paid_eur: number;
    hosting_monthly_eur: number;
    ad_budget_monthly_eur: number;
  };
  attention: AttentionItem[];
}

type LayoutKind = "site" | "app" | "ads";

const LAYOUT_KINDS: LayoutKind[] = ["site", "app", "ads"];

interface AttentionItem {
  kind: "project_failed" | "run_failed" | "run_stalled" | "ad_failed" | "prototype_qa";
  at: string;
  project: { id: string; name: string; status: string } | null;
  customer: string | null;
  stage?: string;
  ad?: { id: number; kind: string; name: string };
  prototype?: { id: string; title: string | null };
  detail: string;
}

interface ProjectRow {
  id: string;
  name: string;
  status: string;
  stack: string | null;
  customer: string | null;
  created_at: string;
  order: { status: string | null; total_one_time_eur: number };
  counts: { ads: number; campaigns: number; change_requests: number };
}

interface AdRow {
  id: number;
  kind: string;
  name: string;
  status: string;
  error: string | null;
  bytes: number;
  created_at: string;
  project: { id: string; name: string } | null;
  customer: string | null;
}

interface CustomerRow {
  id: number;
  email: string;
  name: string | null;
  is_admin: boolean;
  projects: number;
  orders: number;
  paid_eur: number;
}

/** The detail endpoint answers with more than the list, and with the customer as an object. */
interface ProjectDetail {
  id: string;
  name: string;
  status: string;
  customer: { email: string | null; name: string | null } | null;
  failed_reason: string | null;
  care_status: string;
  runs: {
    id: string;
    stage: string;
    attempt: number;
    status: string;
    error: string | null;
    started_at: string | null;
    finished_at: string | null;
  }[];
  ads: { id: number; kind: string; name: string; status: string; error: string | null }[];
  campaigns: { id: number; platform: string; status: string; platform_status: string }[];
  events: { type: string; actor: string; created_at: string }[];
}

interface PrototypeRow {
  id: string;
  kind: "site" | "app" | "ads" | "email" | "campaign";
  status: string;
  stage: string | null;
  title: string | null;
  prompt: string;
  error: string | null;
  ip: string | null;
  project_id: string | null;
  qa_ok: boolean | null;
  repairs: number;
  seconds: number | null;
  created_at: string;
}

type Tab = "overview" | "analytics" | "projects" | "ownAds" | "clientAds" | "conversions" | "ads" | "keywords" | "prototypes" | "customers" | "library" | "references" | "twoFactor" | "audit";

const dt = (s: string, locale: Locale) => new Date(s).toLocaleString(locale);

/**
 * The operator's own page: the whole factory on one screen, and the few buttons that get a stuck
 * project moving again.
 *
 * It reads /admin/*, which is closed to everyone but an admin. The panel does not decide that: a
 * customer who guesses this URL gets a page that answers 403 to every request, which is what the
 * "no access" state below is showing.
 */
export function AdminPanel({ locale, d }: { locale: Locale; d: Dict }) {
  const a = d.admin;
  const token = useToken();
  // Which token was refused, not just that one was: signing in again as an admin must not carry
  // the previous account's "no access" along with it.
  const [deniedFor, setDeniedFor] = useState<string | null>(null);
  const denied = Boolean(token) && deniedFor === token;
  // Where this token stands with the second factor. For an admin who switched it on, nothing in
  // /admin answers until it has passed; for everyone else "passed" is true from the start.
  const [twoFactor, setTwoFactor] = useState<{ for: string; enabled: boolean; passed: boolean } | null>(null);
  const passed = Boolean(token) && twoFactor?.for === token && twoFactor?.passed === true;
  const [tab, setTab] = useState<Tab>("overview");
  const [keywordsFor, setKeywordsFor] = useState<number | null>(null);
  // "auto" until somebody presses the menu button: the width decides until then.
  const [side, setSide] = useState<"auto" | "full" | "rail">("auto");
  const [drawer, setDrawer] = useState(false);
  const [overview, setOverview] = useState<Overview | null>(null);
  const [projects, setProjects] = useState<ProjectRow[]>([]);
  const [statuses, setStatuses] = useState<string[]>([]);
  const [stages, setStages] = useState<string[]>([]);
  const [ads, setAds] = useState<AdRow[]>([]);
  const [customers, setCustomers] = useState<CustomerRow[]>([]);
  const [prototypes, setPrototypes] = useState<PrototypeRow[]>([]);
  const [detail, setDetail] = useState<ProjectDetail | null>(null);
  const [chat, setChat] = useState<{ messages: ChatMessage[]; assistant_paused: boolean } | null>(null);
  const [chatReply, setChatReply] = useState("");
  const [q, setQ] = useState("");
  const [statusFilter, setStatusFilter] = useState("");
  const [busy, setBusy] = useState(false);
  const [note, setNote] = useState<string | null>(null);

  /** Every call goes through here so a 403 turns into the "no access" state instead of a blank page. */
  const call = useCallback(
    async <T,>(path: string, init?: RequestInit): Promise<T | null> => {
      if (!token) return null;
      try {
        return await api<T>(path, { ...init, token });
      } catch (e) {
        const factor = e instanceof ApiError ? (e.body as { two_factor?: "setup" | "verify" } | null)?.two_factor : undefined;
        if (factor) setTwoFactor({ for: token, enabled: factor === "verify", passed: false });
        else if (e instanceof ApiError && e.status === 403) setDeniedFor(token);
        // Expired or revoked: the sign-in form, not a screen of red notes.
        else if (e instanceof ApiError && e.status === 401) setToken(null);
        else if (e instanceof ApiError) {
          const body = e.body as { message?: string } | null;
          setNote(body?.message ?? `HTTP ${e.status}`);
        }
        return null;
      }
    },
    [token],
  );

  const loadOverview = useCallback(async () => {
    const r = await call<Overview>("/admin/overview");
    if (r) setOverview(r);
  }, [call]);

  const loadProjects = useCallback(async () => {
    const params = new URLSearchParams();
    if (q.trim()) params.set("q", q.trim());
    if (statusFilter) params.set("status", statusFilter);
    const r = await call<{ projects: ProjectRow[]; statuses: string[]; stages: string[] }>(
      `/admin/projects${params.size ? `?${params}` : ""}`,
    );
    if (r) {
      setProjects(r.projects);
      setStatuses(r.statuses);
      setStages(r.stages);
    }
  }, [call, q, statusFilter]);

  // The console's own sign-in link lands here with the token in the hash. Store it and take it
  // off the URL, so it is not left in the address bar or the browser history.
  useEffect(() => {
    const fromHash = new URLSearchParams(window.location.hash.slice(1)).get("token");
    if (fromHash) {
      setToken(fromHash);
      history.replaceState(null, "", window.location.pathname);
    }
  }, []);

  // First the second factor, then the factory. A customer's token is refused here already and
  // lands on the "no access" state.
  useEffect(() => {
    if (!token) return;
    let alive = true;
    void (async () => {
      try {
        const r = await api<{ enabled: boolean; passed: boolean }>("/admin/2fa", { token });
        if (alive) setTwoFactor({ for: token, enabled: r.enabled, passed: r.passed });
      } catch (e) {
        if (!alive) return;
        if (e instanceof ApiError && e.status === 401) setToken(null);
        else setDeniedFor(token);
      }
    })();
    return () => {
      alive = false;
    };
  }, [token]);

  useEffect(() => {
    if (!passed) return;
    // eslint-disable-next-line react-hooks/set-state-in-effect -- the state is set after an await inside the loader, not in the effect body
    void loadOverview();
    void loadProjects();
  }, [passed, loadOverview, loadProjects]);

  const loadAds = useCallback(async () => {
    const r = await call<{ ads: AdRow[] }>("/admin/ads");
    if (r) setAds(r.ads);
  }, [call]);

  /** Tab data is fetched on the press, not in an effect: the press is the thing that asks for it. */
  async function selectTab(t: Tab) {
    setTab(t);
    setNote(null);
    if (t === "ads") await loadAds();
    if (t === "customers") {
      const r = await call<{ customers: CustomerRow[] }>("/admin/customers");
      if (r) setCustomers(r.customers);
    }
    if (t === "prototypes") {
      const r = await call<{ prototypes: PrototypeRow[] }>("/admin/prototypes");
      if (r) setPrototypes(r.prototypes);
    }
  }

  async function openProject(id: string) {
    setNote(null);
    const [r, thread] = await Promise.all([
      call<ProjectDetail>(`/admin/projects/${id}`),
      call<{ messages: ChatMessage[]; assistant_paused: boolean }>(`/admin/projects/${id}/messages`),
    ]);
    if (r) {
      setDetail(r);
      setChat(thread);
      setTab("projects");
    }
  }

  async function replyInChat(projectId: string) {
    if (!chatReply.trim()) return;
    setBusy(true);
    const r = await call<{ messages: ChatMessage[] }>(`/admin/projects/${projectId}/messages`, {
      method: "POST",
      body: JSON.stringify({ body: chatReply }),
    });
    if (r) {
      setChat((c) => ({ messages: r.messages, assistant_paused: c?.assistant_paused ?? false }));
      setChatReply("");
    }
    setBusy(false);
  }

  /** Live asks for the word LIVE, typed: from that moment checkouts charge real cards. */
  async function switchPayments(mode: "sandbox" | "live") {
    let confirm: string | null = null;
    if (mode === "live") {
      confirm = window.prompt(a.paymentsConfirm);
      if (confirm !== "LIVE") return;
    }
    setBusy(true);
    const r = await call<Overview["payments"]>("/admin/payments/mode", { method: "POST", body: JSON.stringify({ mode, confirm }) });
    if (r) setOverview((o) => (o ? { ...o, payments: r } : o));
    setBusy(false);
  }

  /**
   * Every layout setting is saved here rather than deployed, because the question they answer is
   * whether the packs make the pages better, and that needs them turned on and off in one week.
   */
  async function saveAdsMode(mode: Overview["ads_mode"]) {
    const r = await call<{ ads_mode: Overview["ads_mode"] }>("/admin/ads-mode", { method: "POST", body: JSON.stringify({ mode }) });
    if (r) setOverview((o) => (o ? { ...o, ads_mode: r.ads_mode } : o));
  }

  async function saveWriter(writer: Overview["prototype_writer"]) {
    const r = await call<{ prototype_writer: Overview["prototype_writer"] }>("/admin/prototype-writer", { method: "POST", body: JSON.stringify({ writer }) });
    if (r) setOverview((o) => (o ? { ...o, prototype_writer: r.prototype_writer } : o));
  }

  async function setKill(on: boolean) {
    if (on && !window.confirm(a.guardKillConfirm)) return;
    setBusy(true);
    const r = await call<{ limits: Omit<Overview["ads_guard"], "running"> }>("/admin/ads/kill", { method: "POST", body: JSON.stringify({ on }) });
    if (r) setOverview((o) => (o ? { ...o, ads_guard: { ...o.ads_guard, ...r.limits, running: on ? 0 : o.ads_guard.running } } : o));
    setBusy(false);
  }

  async function setPlatformPaused(platform: string, paused: boolean) {
    setBusy(true);
    const r = await call<{ paused: string[] }>("/admin/ads/platform", { method: "POST", body: JSON.stringify({ platform, paused }) });
    if (r) {
      setOverview((o) =>
        o ? { ...o, connections: Object.fromEntries(Object.entries(o.connections).map(([k, c]) => [k, { ...c, paused: r.paused.includes(k) }])) } : o,
      );
    }
    setBusy(false);
  }

  // Appwerk's own account numbers. Loaded when the ads area is looked at, not with the overview:
  // they change once a year and an overview that waits on them helps nobody.
  const [adNumbers, setAdNumbers] = useState<Record<string, { value: string; source: string }> | null>(null);

  useEffect(() => {
    let alive = true;
    void (async () => {
      const r = await call<{ settings: Record<string, { value: string; source: string }> }>("/admin/ads/settings");
      if (alive && r) setAdNumbers(r.settings);
    })();

    return () => {
      alive = false;
    };
  }, [call]);

  async function saveAdNumbers(values: Record<string, string>) {
    const r = await call<{ settings: Record<string, { value: string; source: string }> }>("/admin/ads/settings", {
      method: "POST",
      body: JSON.stringify(values),
    });
    if (r) setAdNumbers(r.settings);
  }

  // The OpenAI key paid renders are billed to. The API never sends the key back, only whether
  // one is set and its last four characters.
  type ImageKey = { set: boolean; hint: string | null; by: string | null; at: string | null };
  const [imageKey, setImageKey] = useState<ImageKey | null>(null);

  useEffect(() => {
    let alive = true;
    void (async () => {
      const r = await call<{ key: ImageKey }>("/admin/image-key");
      if (alive && r) setImageKey(r.key);
    })();

    return () => {
      alive = false;
    };
  }, [call]);

  async function saveImageKey(form: HTMLFormElement) {
    const input = form.elements.namedItem("api_key") as HTMLInputElement;
    setBusy(true);
    const r = await call<{ key: ImageKey }>("/admin/image-key", { method: "POST", body: JSON.stringify({ api_key: input.value.trim() }) });
    if (r) {
      setImageKey(r.key);
      input.value = "";
    }
    setBusy(false);
  }

  async function removeImageKey() {
    if (!window.confirm(a.imageKeyRemoveConfirm)) return;
    setBusy(true);
    const r = await call<{ key: ImageKey }>("/admin/image-key", { method: "DELETE" });
    if (r) setImageKey(r.key);
    setBusy(false);
  }

  async function saveGuardLimits(max_campaign_eur: number, max_daily_total_eur: number) {
    setBusy(true);
    const r = await call<{ limits: Omit<Overview["ads_guard"], "running"> }>("/admin/ads/limits", { method: "POST", body: JSON.stringify({ max_campaign_eur, max_daily_total_eur }) });
    if (r) setOverview((o) => (o ? { ...o, ads_guard: { ...o.ads_guard, ...r.limits } } : o));
    setBusy(false);
  }

  async function saveLayouts(patch: Partial<Pick<Overview["layouts"], "enabled" | "kinds" | "share" | "off">>) {
    setBusy(true);
    const r = await call<Overview["layouts"]>("/admin/layouts", { method: "POST", body: JSON.stringify(patch) });
    if (r) setOverview((o) => (o ? { ...o, layouts: r } : o));
    setBusy(false);
  }

  async function setAssistantPaused(projectId: string, paused: boolean) {
    setBusy(true);
    const r = await call<{ assistant_paused: boolean }>(`/admin/projects/${projectId}/assistant`, {
      method: "POST",
      body: JSON.stringify({ paused }),
    });
    if (r) setChat((c) => (c ? { ...c, assistant_paused: r.assistant_paused } : c));
    setBusy(false);
  }

  async function runStage(projectId: string, stage: string) {
    setBusy(true);
    setNote(null);
    const r = await call<{ stage: string }>(`/admin/projects/${projectId}/stage`, {
      method: "POST",
      body: JSON.stringify({ stage }),
    });
    if (r) setNote(`${a.stageQueued}: ${r.stage}`);
    setBusy(false);
    await loadOverview();
    if (detail?.id === projectId) await openProject(projectId);
  }

  async function forceStatus(projectId: string, status: string) {
    setBusy(true);
    setNote(null);
    const r = await call<{ status: string }>(`/admin/projects/${projectId}/status`, {
      method: "POST",
      body: JSON.stringify({ status }),
    });
    if (r) setNote(`${a.statusForced}: ${r.status}`);
    setBusy(false);
    await Promise.all([loadOverview(), loadProjects()]);
    if (detail?.id === projectId) await openProject(projectId);
  }

  async function rerenderAd(adId: number) {
    setBusy(true);
    setNote(null);
    const r = await call<{ status: string }>(`/admin/ads/${adId}/rerender`, { method: "POST" });
    if (r) setNote(a.adQueued);
    setBusy(false);
    await loadOverview();
    if (tab === "ads") await loadAds();
  }

  // The menu in groups, the way an operator thinks about the work rather than in the order the
  // screens were built. Adding a screen is one entry in one group.
  const GROUPS: { label: string; tabs: Tab[] }[] = [
    { label: a.groupWork, tabs: ["overview", "projects", "customers", "prototypes"] },
    { label: a.groupAds, tabs: ["ownAds", "clientAds", "keywords", "conversions", "ads"] },
    { label: a.groupInsight, tabs: ["analytics"] },
    { label: a.groupContent, tabs: ["library", "references"] },
    { label: a.groupSecurity, tabs: ["twoFactor", "audit"] },
  ];

  // The console's own frame. Before a token is known there is nothing to navigate to, so the
  // sidebar stays away rather than offering nine dead links.
  const bare = (body: React.ReactNode) => (
    <div className="ops-main" style={{ paddingTop: 40 }}>{body}</div>
  );

  if (token === undefined) return bare(<p className="est-empty">{a.loading}</p>);
  if (!token || denied) return <AdminSignIn locale={locale} d={d} denied={denied} />;
  if (twoFactor?.for !== token) return bare(<p className="est-empty">{a.loading}</p>);
  if (!twoFactor.passed) {
    return <TwoFactorGate token={token} d={d} onPassed={() => setTwoFactor({ ...twoFactor, passed: true })} />;
  }

  /**
   * One button, three jobs. On a phone the menu is a drawer and the button opens it. Anywhere
   * else it switches between the full menu and the icon rail. Until it is pressed the width
   * decides (full on a wide screen, rail on a narrow one), so nothing has to be remembered.
   */
  const toggleSide = () => {
    if (window.matchMedia("(max-width: 639px)").matches) {
      setDrawer((o) => !o);
      return;
    }
    const nowFull = side === "full" || (side === "auto" && window.matchMedia("(min-width: 1024px)").matches);
    setSide(nowFull ? "rail" : "full");
  };

  const me = overview?.me ?? "";

  return (
    <div className="ops" data-side={side} data-drawer={drawer ? "open" : "closed"}>
      <nav className="ops-side" aria-label={a.title}>
        <div className="ops-brand">
          <span className="ops-logo" aria-hidden="true">A</span>
          <span className="ops-brand-text">
            <strong>Appwerk</strong>
            <span>{a.consoleName}</span>
          </span>
        </div>

        <div className="ops-groups" role="tablist" aria-orientation="vertical">
          {GROUPS.map((g) => (
            <div className="ops-group" key={g.label}>
              <p className="ops-group-label">{g.label}</p>
              {g.tabs.map((t) => (
                <button
                  key={t}
                  className="ops-nav"
                  role="tab"
                  aria-selected={tab === t}
                  title={a.tabs[t]}
                  onClick={() => {
                    setDrawer(false);
                    void selectTab(t);
                  }}
                >
                  <OpsIcon name={t} />
                  <span className="ops-nav-label">{a.tabs[t]}</span>
                </button>
              ))}
            </div>
          ))}
        </div>

        <div className="ops-user">
          {/* The storefront's switch lived in its header, which the console no longer has. The
              token is kept per browser, not per language, so switching keeps you signed in. */}
          <a className="ops-nav" href={`/${locale === "de" ? "en" : "de"}/admin`} title={a.otherLanguage} lang={locale === "de" ? "en" : "de"}>
            <OpsIcon name="language" />
            <span className="ops-nav-label">{a.otherLanguage}</span>
          </a>
          <a className="ops-nav" href={`/${locale}`} title={a.backToSite}>
            <OpsIcon name="site" />
            <span className="ops-nav-label">{a.backToSite}</span>
          </a>
          <div className="ops-me">
            <span className="ops-avatar" aria-hidden="true">{(me[0] ?? "?").toUpperCase()}</span>
            <span className="ops-me-mail" title={me}>{me}</span>
            <button className="ops-icon-btn" onClick={() => setToken(null)} title={a.signOut} aria-label={a.signOut}>
              <OpsIcon name="signout" />
            </button>
          </div>
        </div>
      </nav>

      {drawer && <button className="ops-scrim" aria-label={a.closeMenu} onClick={() => setDrawer(false)} />}

      <main className="ops-main">
      <header className="ops-top">
        <button className="ops-icon-btn" onClick={toggleSide} aria-label={a.toggleMenu} title={a.toggleMenu}>
          <OpsIcon name="panel" />
        </button>
        <span className="ops-crumb-sep" aria-hidden="true" />
        <nav className="ops-crumbs" aria-label="Breadcrumb">
          <span className="muted">{a.consoleName}</span>
          <span className="ops-crumb-arrow" aria-hidden="true">›</span>
          <span>{a.tabs[tab]}</span>
        </nav>
      </header>
      <h1>{a.tabs[tab]}</h1>
      {note && <p className="note">{note}</p>}

      {tab === "analytics" && token && <AnalyticsPanel token={token} locale={locale} d={d} />}
      {tab === "library" && token && <LibraryPanel token={token} locale={locale} d={d} />}
      {tab === "references" && token && <ReferencePanel token={token} locale={locale} d={d} />}
      {tab === "keywords" && token && <KeywordsPanel token={token} d={d} openId={keywordsFor} />}
      {tab === "conversions" && token && <ConversionsPanel token={token} locale={locale} d={d} />}
      {tab === "twoFactor" && token && <TwoFactorPanel token={token} d={d} />}
      {tab === "audit" && token && <AuditPanel token={token} locale={locale} d={d} />}
      {tab === "ownAds" && token && (
        <OwnCampaignsPanel
          token={token}
          locale={locale}
          d={d}
          onKeywords={(id) => {
            setKeywordsFor(id);
            setTab("keywords");
          }}
        />
      )}
      {tab === "clientAds" && token && <ClientAdsPanel token={token} locale={locale} d={d} />}

      {tab === "overview" && overview && (
        <>
          <div className="grid" style={{ marginBottom: 26 }}>
            <div className="card">
              <span className="cat">{a.projectsTile}</span>
              <strong className="num" style={{ fontSize: 28 }}>
                {overview.projects.total}
              </strong>
              <div className="small muted">
                {Object.entries(overview.projects.by_status).map(([s, n]) => (
                  <div key={s}>
                    {s} · {n}
                  </div>
                ))}
              </div>
            </div>
            <div className="card">
              <span className="cat">{a.runsTile}</span>
              <div className="small">
                <div>
                  {a.queued}: <span className="num">{overview.runs.queued}</span>
                </div>
                <div>
                  {a.running}: <span className="num">{overview.runs.running}</span>
                </div>
                <div>
                  {a.failed24h}: <span className="num">{overview.runs.failed_24h}</span>
                </div>
              </div>
            </div>
            <div className="card">
              <span className="cat">{a.connectionsTile}</span>
              <div className="small" style={{ marginTop: 8, display: "grid", gap: 6 }}>
                {Object.entries(overview.connections ?? {}).map(([platform, c]) => (
                  <div key={platform}>
                    <strong>{platform === "meta" ? "Meta (Facebook, Instagram)" : "Google Ads"}</strong>:{" "}
                    {c.paused
                      ? a.platformPaused
                      : !c.configured
                      ? a.notConnected
                      : !c.verified
                        ? a.connectionUnchecked
                        : c.verified.ok
                          ? a.connected
                          : a.connectionFailed}
                    <button
                      className="btn btn-ghost btn-sm"
                      style={{ marginLeft: 8 }}
                      disabled={busy}
                      onClick={() => void setPlatformPaused(platform, !c.paused)}
                    >
                      {c.paused ? a.platformResume : a.platformPause}
                    </button>
                    {!c.paused && c.configured && c.verified && (
                      <div className="muted" style={{ fontSize: 12 }}>
                        {a.connectionCheckedAt} {dt(c.verified.at, locale)}
                        {c.verified.ok ? (c.verified.account ? `: ${c.verified.account}` : "") : `: ${c.verified.detail ?? ""}`}
                      </div>
                    )}
                    {!c.paused && c.missing.length > 0 && (
                      <div className="muted" style={{ fontSize: 12 }}>
                        {a.missing}: {c.missing.join(", ")}
                      </div>
                    )}
                  </div>
                ))}
              </div>
              <div className="small muted" style={{ marginTop: 8 }}>{a.connectionsHint}</div>
            </div>
            <div className="card">
              <span className="cat">{a.adsTile}</span>
              <div className="small">
                {Object.entries(overview.ads).map(([s, n]) => (
                  <div key={s}>
                    {s} · {n}
                  </div>
                ))}
                {Object.keys(overview.ads).length === 0 && <span className="muted">{a.empty}</span>}
              </div>
            </div>
            <div className="card">
              <span className="cat">{a.revenueTile}</span>
              <div className="small">
                <div>
                  {a.paidOrders}: <span className="num">{overview.revenue.paid_orders}</span>
                </div>
                <div>
                  {a.paidEur}: <span className="num">{overview.revenue.paid_eur} €</span>
                </div>
                <div className="muted">
                  {a.testOrders}: <span className="num">{overview.revenue.test_orders}</span>
                </div>
                <div>
                  {a.hosting}: <span className="num">{overview.revenue.hosting_monthly_eur} €</span>
                </div>
                <div>
                  {a.customersTile}: <span className="num">{overview.customers}</span>
                </div>
              </div>
            </div>
          </div>

          <div className="card" style={{ marginBottom: 26, borderColor: overview.payments.mode === "live" ? "var(--valid)" : undefined }}>
            <span className="cat">{a.paymentsTile}</span>
            <strong style={{ fontSize: 22 }}>{overview.payments.mode === "live" ? a.paymentsLive : a.paymentsSandbox}</strong>
            <p className="small muted" style={{ margin: "4px 0 10px" }}>
              {overview.payments.mode === "live" ? a.paymentsLiveHint : a.paymentsSandboxHint}
            </p>
            {overview.payments.mode === "sandbox" && overview.payments.live_missing.length > 0 && (
              <p className="small" style={{ margin: "0 0 10px" }}>
                {a.missing}: {overview.payments.live_missing.join(", ")}
              </p>
            )}
            <div>
              {overview.payments.mode === "sandbox" ? (
                <button
                  className="btn btn-primary btn-sm"
                  disabled={busy || overview.payments.live_missing.length > 0}
                  onClick={() => void switchPayments("live")}
                >
                  {a.paymentsGoLive}
                </button>
              ) : (
                <button className="btn btn-ghost btn-sm" disabled={busy} onClick={() => void switchPayments("sandbox")}>
                  {a.paymentsBackToSandbox}
                </button>
              )}
            </div>
          </div>

          <div className="card" style={{ marginBottom: 26, borderColor: overview.ads_guard.killed ? "#c0392b" : undefined }}>
            <span className="cat">{a.guardTile}</span>
            <strong style={{ fontSize: 22 }}>
              {overview.ads_guard.killed
                ? a.guardKilled
                : a.guardRunning.replace("{n}", String(overview.ads_guard.running)).replace("{eur}", overview.ads_guard.running_daily_eur.toFixed(2))}
            </strong>
            <p className="small muted" style={{ margin: "4px 0 10px" }}>{a.guardHint}</p>
            <form
              style={{ display: "flex", flexWrap: "wrap", gap: 8, alignItems: "center", marginBottom: 10 }}
              onSubmit={(e) => {
                e.preventDefault();
                const f = new FormData(e.currentTarget);
                void saveGuardLimits(Number(f.get("c")), Number(f.get("d")));
              }}
            >
              <label className="small">
                {a.guardMaxCampaign}{" "}
                <input name="c" type="number" min={20} max={10000} defaultValue={overview.ads_guard.max_campaign_eur} style={{ width: 90 }} /> €
              </label>
              <label className="small">
                {a.guardMaxDaily}{" "}
                <input name="d" type="number" min={5} max={5000} defaultValue={overview.ads_guard.max_daily_total_eur} style={{ width: 90 }} /> €
              </label>
              <button className="btn btn-ghost btn-sm" disabled={busy}>{a.guardSave}</button>
            </form>
            <button
              className={overview.ads_guard.killed ? "btn btn-ghost btn-sm" : "btn btn-primary btn-sm"}
              style={overview.ads_guard.killed ? undefined : { background: "#c0392b", borderColor: "#c0392b" }}
              disabled={busy}
              onClick={() => void setKill(!overview.ads_guard.killed)}
            >
              {overview.ads_guard.killed ? a.guardUnkill : a.guardKill}
            </button>
          </div>

          {adNumbers && (
            <div className="card" style={{ marginBottom: 26 }}>
              <span className="cat">{a.adsSettings}</span>
              <p className="small muted" style={{ margin: "4px 0 10px" }}>{a.adsSettingsHint}</p>
              <form
                onSubmit={(e) => {
                  e.preventDefault();
                  const f = new FormData(e.currentTarget);
                  void saveAdNumbers(Object.fromEntries([...f.entries()].map(([k, v]) => [k, String(v)])));
                }}
              >
                {(
                  [
                    ["meta_business_id", a.metaBusiness],
                    ["meta_ad_account_id", a.metaAccount],
                    ["meta_page_id", a.metaPage],
                    ["google_manager_id", a.googleManager],
                    ["google_customer_id", a.googleCustomer],
                  ] as const
                ).map(([key, label]) => (
                  <label key={key} className="small" style={{ display: "flex", flexWrap: "wrap", gap: "4px 8px", alignItems: "center", marginBottom: 8 }}>
                    <span style={{ flex: "1 0 190px" }}>{label}</span>
                    <input name={key} defaultValue={adNumbers[key]?.value ?? ""} style={{ width: 190, maxWidth: "100%" }} />
                    <span className="muted">
                      {adNumbers[key]?.source === "panel"
                        ? a.fromPanel
                        : adNumbers[key]?.source === "env"
                          ? a.fromEnv
                          : a.notSet}
                    </span>
                  </label>
                ))}
                <button className="btn btn-ghost btn-sm" disabled={busy} style={{ marginTop: 6 }}>{a.guardSave}</button>
              </form>
            </div>
          )}

          {imageKey && (
            <div className="card" style={{ marginBottom: 26 }}>
              <span className="cat">{a.imageKeyTile}</span>
              <strong style={{ fontSize: 22 }}>{imageKey.set ? `${a.imageKeySet} ${imageKey.hint ?? ""}` : a.imageKeyNone}</strong>
              {imageKey.set && imageKey.by && (
                <p className="small muted" style={{ margin: "2px 0 0" }}>
                  {a.imageKeyBy} {imageKey.by}
                  {imageKey.at && ` · ${new Date(imageKey.at).toLocaleString(locale)}`}
                </p>
              )}
              <p className="small muted" style={{ margin: "4px 0 10px" }}>{a.imageKeyHint}</p>
              <form
                style={{ display: "flex", flexWrap: "wrap", gap: 8, alignItems: "center" }}
                onSubmit={(e) => {
                  e.preventDefault();
                  void saveImageKey(e.currentTarget);
                }}
              >
                <input
                  name="api_key"
                  type="password"
                  autoComplete="off"
                  required
                  minLength={20}
                  placeholder={a.imageKeyPlaceholder}
                  style={{ width: 280, maxWidth: "100%" }}
                />
                <button className="btn btn-primary btn-sm" disabled={busy}>
                  {imageKey.set ? a.imageKeyReplace : a.imageKeySave}
                </button>
                {imageKey.set && (
                  <button type="button" className="btn btn-ghost btn-sm" disabled={busy} onClick={() => void removeImageKey()}>
                    {a.imageKeyRemove}
                  </button>
                )}
              </form>
            </div>
          )}

          <div className="card" style={{ marginBottom: 26 }}>
            <span className="cat">{a.adsModeTile}</span>
            <strong style={{ fontSize: 22 }}>{a.adsModes[overview.ads_mode]}</strong>
            <p className="small muted" style={{ margin: "4px 0 10px" }}>{a.adsModeHints[overview.ads_mode]}</p>
            <div style={{ display: "flex", flexWrap: "wrap", gap: 8 }}>
              {(["hybrid", "claude", "codex"] as const).map((m) => (
                <button
                  key={m}
                  className={overview.ads_mode === m ? "btn btn-primary btn-sm" : "btn btn-ghost btn-sm"}
                  disabled={busy || overview.ads_mode === m}
                  onClick={() => void saveAdsMode(m)}
                >
                  {a.adsModes[m]}
                </button>
              ))}
            </div>
          </div>

          <div className="card" style={{ marginBottom: 26 }}>
            <span className="cat">{a.writerTile}</span>
            <strong style={{ fontSize: 22 }}>{a.writers[overview.prototype_writer]}</strong>
            <p className="small muted" style={{ margin: "4px 0 10px" }}>{a.writerHints[overview.prototype_writer]}</p>
            <div style={{ display: "flex", flexWrap: "wrap", gap: 8 }}>
              {(["claude", "codex"] as const).map((w) => (
                <button
                  key={w}
                  className={overview.prototype_writer === w ? "btn btn-primary btn-sm" : "btn btn-ghost btn-sm"}
                  disabled={busy || overview.prototype_writer === w}
                  onClick={() => void saveWriter(w)}
                >
                  {a.writers[w]}
                </button>
              ))}
            </div>
          </div>

          <div className="card" style={{ marginBottom: 26 }}>
            <span className="cat">{a.layoutsTile}</span>
            <strong style={{ fontSize: 22 }}>{overview.layouts.enabled ? a.layoutsOn : a.layoutsOff}</strong>
            <p className="small muted" style={{ margin: "4px 0 10px" }}>{a.layoutsHint}</p>

            {overview.layouts.packs.length === 0 ? (
              <p className="small" style={{ margin: "0 0 10px" }}>{a.layoutsNoPacks}</p>
            ) : (
              <div className="tbl-wrap" style={{ marginBottom: 10 }}>
                <table>
                  <tbody>
                    {overview.layouts.packs.map((p) => {
                      const held = overview.layouts.off.includes(p.slug);
                      return (
                        <tr key={p.slug}>
                          <td>
                            <strong>{p.slug}</strong>
                            <div className="small muted">
                              {p.kind} · {p.source} · {Math.round(p.bytes / 1024)} KB
                              {p.industries.length > 0 && ` · ${p.industries.join(", ")}`}
                            </div>
                          </td>
                          <td style={{ textAlign: "right" }}>
                            <button
                              className="btn btn-ghost btn-sm"
                              disabled={busy}
                              onClick={() =>
                                void saveLayouts({
                                  off: held
                                    ? overview.layouts.off.filter((s) => s !== p.slug)
                                    : [...overview.layouts.off, p.slug],
                                })
                              }
                            >
                              {held ? a.layoutsUse : a.layoutsHold}
                            </button>
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            )}

            <div style={{ display: "flex", gap: 14, flexWrap: "wrap", alignItems: "center", marginBottom: 10 }}>
              {LAYOUT_KINDS.map((k) => (
                <label key={k} className="small" style={{ display: "flex", gap: 6, alignItems: "center" }}>
                  <input
                    type="checkbox"
                    checked={overview.layouts.kinds.includes(k)}
                    disabled={busy}
                    onChange={(e) =>
                      void saveLayouts({
                        kinds: e.target.checked
                          ? [...overview.layouts.kinds, k]
                          : overview.layouts.kinds.filter((x) => x !== k),
                      })
                    }
                  />
                  {k} <span className="muted">({overview.layouts.by_kind[k] ?? 0})</span>
                </label>
              ))}
            </div>

            <label className="small" style={{ display: "block", marginBottom: 10 }}>
              {a.layoutsShare}: <span className="num">{overview.layouts.share}%</span>
              <input
                type="range"
                min={0}
                max={100}
                step={10}
                defaultValue={overview.layouts.share}
                disabled={busy}
                style={{ display: "block", width: "100%", maxWidth: 320 }}
                onMouseUp={(e) => void saveLayouts({ share: Number((e.target as HTMLInputElement).value) })}
                onTouchEnd={(e) => void saveLayouts({ share: Number((e.target as HTMLInputElement).value) })}
              />
              <span className="muted">{a.layoutsShareHint}</span>
            </label>

            <button
              className={overview.layouts.enabled ? "btn btn-ghost btn-sm" : "btn btn-primary btn-sm"}
              disabled={busy || (!overview.layouts.enabled && overview.layouts.packs.length === 0)}
              onClick={() => void saveLayouts({ enabled: !overview.layouts.enabled })}
            >
              {overview.layouts.enabled ? a.layoutsTurnOff : a.layoutsTurnOn}
            </button>
          </div>

          <h2 style={{ fontSize: "1.1rem" }}>{a.attention}</h2>
          {overview.attention.length === 0 && <p className="est-empty">{a.allQuiet}</p>}
          <div className="tbl-wrap">
            <table>
              <tbody>
                {overview.attention.map((item, i) => (
                  <tr key={`${item.kind}-${i}`}>
                    <td>{a.kinds[item.kind]}</td>
                    <td>
                      {item.project ? (
                        <button className="tab" onClick={() => void openProject(item.project!.id)}>
                          {item.project.name}
                        </button>
                      ) : item.prototype ? (
                        <a href={`/${locale}/p/${item.prototype.id}`} target="_blank" rel="noopener">
                          {item.prototype.title ?? item.prototype.id.slice(0, 8)}
                        </a>
                      ) : (
                        "—"
                      )}
                      <div className="small muted">
                        {item.customer ?? ""} · {dt(item.at, locale)}
                      </div>
                    </td>
                    <td className="small muted" style={{ maxWidth: 420 }}>
                      {item.stage ? `${item.stage}: ` : ""}
                      {item.detail}
                    </td>
                    <td>
                      {item.stage && item.project && (
                        <button
                          className="btn btn-ghost"
                          disabled={busy}
                          onClick={() => void runStage(item.project!.id, item.stage!)}
                        >
                          {a.retryStage}
                        </button>
                      )}
                      {item.ad && (
                        <button className="btn btn-ghost" disabled={busy} onClick={() => void rerenderAd(item.ad!.id)}>
                          {a.rerender}
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </>
      )}

      {tab === "projects" && (
        <>
          <div style={{ display: "flex", gap: 12, flexWrap: "wrap", marginBottom: 16 }}>
            <input
              value={q}
              onChange={(e) => setQ(e.target.value)}
              placeholder={a.searchHint}
              style={{ minWidth: 240 }}
            />
            <select value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)}>
              <option value="">{a.allStatuses}</option>
              {statuses.map((s) => (
                <option key={s} value={s}>
                  {s}
                </option>
              ))}
            </select>
            <button className="btn btn-ghost" onClick={() => void loadProjects()}>
              {a.search}
            </button>
          </div>

          <div className="tbl-wrap" style={{ marginBottom: 24 }}>
            <table>
              <thead>
                <tr>
                  <th>{a.project}</th>
                  <th>{a.status}</th>
                  <th>{a.customer}</th>
                  <th>{a.order}</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {projects.map((p) => (
                  <tr key={p.id}>
                    <td>
                      {p.name}
                      <div className="small muted">{dt(p.created_at, locale)}</div>
                    </td>
                    <td>
                      <span className="badge">{p.status}</span>
                    </td>
                    <td className="small">{p.customer}</td>
                    <td className="small num">
                      {p.order.total_one_time_eur} € · {p.order.status}
                    </td>
                    <td>
                      <button className="btn btn-ghost" onClick={() => void openProject(p.id)}>
                        {a.open}
                      </button>
                    </td>
                  </tr>
                ))}
                {projects.length === 0 && (
                  <tr>
                    <td colSpan={5} className="muted">
                      {a.empty}
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>

          {detail && (
            <div className="card" style={{ gap: 14 }}>
              <div className="detail-head">
                <h2 style={{ margin: 0, fontSize: "1.15rem" }}>{detail.name}</h2>
                <span className="badge">{detail.status}</span>
                <span className="small muted">{detail.customer?.email}</span>
              </div>
              {detail.failed_reason && <p className="note">{detail.failed_reason}</p>}

              <div style={{ display: "flex", gap: 12, flexWrap: "wrap", alignItems: "center" }}>
                <label className="small">
                  {a.runStage}{" "}
                  <select
                    defaultValue=""
                    disabled={busy}
                    onChange={(e) => {
                      if (e.target.value) void runStage(detail.id, e.target.value);
                      e.target.value = "";
                    }}
                  >
                    <option value="">…</option>
                    {stages.map((s) => (
                      <option key={s} value={s}>
                        {s}
                      </option>
                    ))}
                  </select>
                </label>
                <label className="small">
                  {a.forceStatus}{" "}
                  <select
                    defaultValue=""
                    disabled={busy}
                    onChange={(e) => {
                      if (e.target.value) void forceStatus(detail.id, e.target.value);
                      e.target.value = "";
                    }}
                  >
                    <option value="">…</option>
                    {statuses.map((s) => (
                      <option key={s} value={s}>
                        {s}
                      </option>
                    ))}
                  </select>
                </label>
              </div>

              <h3 style={{ margin: "8px 0 0", fontSize: "1rem" }}>{a.runs}</h3>
              <div className="tbl-wrap">
                <table>
                  <tbody>
                    {detail.runs.map((r) => (
                      <tr key={r.id}>
                        <td>{r.stage}</td>
                        <td className="small">
                          {r.status} · #{r.attempt}
                        </td>
                        <td className="small muted">{r.error ?? ""}</td>
                        <td className="small muted">{r.finished_at ? dt(r.finished_at, locale) : ""}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>

              {detail.ads.length > 0 && (
                <>
                  <h3 style={{ margin: "8px 0 0", fontSize: "1rem" }}>{a.adsTile}</h3>
                  <div className="tbl-wrap">
                    <table>
                      <tbody>
                        {detail.ads.map((ad) => (
                          <tr key={ad.id}>
                            <td>{ad.name}</td>
                            <td className="small">
                              {ad.kind} · {ad.status}
                            </td>
                            <td className="small muted">{ad.error ?? ""}</td>
                            <td>
                              <button className="btn btn-ghost" disabled={busy} onClick={() => void rerenderAd(ad.id)}>
                                {a.rerender}
                              </button>
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                </>
              )}

              {chat && (
                <>
                  <div className="detail-head" style={{ marginTop: 8 }}>
                    <h3 style={{ margin: 0, fontSize: "1rem" }}>{a.chat}</h3>
                    <button
                      className="lang-toggle"
                      disabled={busy}
                      onClick={() => void setAssistantPaused(detail.id, !chat.assistant_paused)}
                    >
                      {chat.assistant_paused ? a.chatResume : a.chatPause}
                    </button>
                  </div>
                  {chat.assistant_paused && <p className="note" style={{ margin: 0 }}>{a.chatPaused}</p>}
                  <div className="small" style={{ maxHeight: 360, overflowY: "auto", display: "grid", gap: 8 }}>
                    {chat.messages.length === 0 && <span className="muted">{a.chatEmpty}</span>}
                    {chat.messages.map((m) => (
                      <div key={m.id} style={{ borderLeft: `3px solid ${m.role === "customer" ? "var(--accent)" : m.role === "operator" ? "var(--valid)" : "var(--border)"}`, paddingLeft: 10 }}>
                        <span className="muted">
                          {dt(m.created_at, locale)} · {m.role}
                          {m.meta.type ? ` · ${m.meta.type}` : ""}
                        </span>
                        <div style={{ whiteSpace: "pre-wrap" }}>{m.body}</div>
                        {!!m.meta.images && token && (
                          <ChatShots path={`/admin/projects/${detail.id}/messages/${m.id}`} count={m.meta.images} token={token} label="Screenshot" />
                        )}
                        {m.meta.card && (
                          <ol style={{ margin: "4px 0 0", paddingLeft: 18 }}>
                            {m.meta.card.items.map((i, n) => <li key={n}>{i.text}</li>)}
                          </ol>
                        )}
                        {m.meta.items && (
                          <ul style={{ margin: "4px 0 0", paddingLeft: 18 }}>
                            {m.meta.items.map((i, n) => <li key={n}>{i.done ? "✓" : "○"} {i.text}{i.note ? ` (${i.note})` : ""}</li>)}
                          </ul>
                        )}
                        {m.meta.reason && <div className="muted">{m.meta.reason}</div>}
                      </div>
                    ))}
                  </div>
                  <textarea
                    value={chatReply}
                    onChange={(e) => setChatReply(e.target.value)}
                    placeholder={a.chatReply}
                    rows={2}
                    style={{ width: "100%", padding: 10, font: "inherit", border: "1px solid var(--border)", borderRadius: "var(--radius)" }}
                  />
                  <div>
                    <button className="btn btn-primary" disabled={busy || !chatReply.trim()} onClick={() => void replyInChat(detail.id)}>
                      {a.chatSend}
                    </button>
                  </div>
                </>
              )}

              <h3 style={{ margin: "8px 0 0", fontSize: "1rem" }}>{a.events}</h3>
              <div className="small muted" style={{ maxHeight: 240, overflowY: "auto" }}>
                {detail.events.map((e, i) => (
                  <div key={i}>
                    {dt(e.created_at, locale)} · {e.type} · {e.actor}
                  </div>
                ))}
              </div>
            </div>
          )}
        </>
      )}

      {tab === "ads" && (
        <div className="tbl-wrap">
          <table>
            <thead>
              <tr>
                <th>{a.ad}</th>
                <th>{a.status}</th>
                <th>{a.project}</th>
                <th>{a.customer}</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {ads.map((ad) => (
                <tr key={ad.id}>
                  <td>
                    {ad.name}
                    <div className="small muted">
                      {ad.kind} · {dt(ad.created_at, locale)}
                    </div>
                  </td>
                  <td className="small">
                    {ad.status}
                    {ad.error && <div className="muted">{ad.error}</div>}
                  </td>
                  <td className="small">
                    {ad.project ? (
                      <button className="tab" onClick={() => void openProject(ad.project!.id)}>
                        {ad.project.name}
                      </button>
                    ) : (
                      "—"
                    )}
                  </td>
                  <td className="small">{ad.customer}</td>
                  <td>
                    <button className="btn btn-ghost" disabled={busy} onClick={() => void rerenderAd(ad.id)}>
                      {a.rerender}
                    </button>
                  </td>
                </tr>
              ))}
              {ads.length === 0 && (
                <tr>
                  <td colSpan={5} className="muted">
                    {a.empty}
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      )}

      {tab === "prototypes" && (
        <div className="tbl-wrap">
          {prototypes.length === 0 && <p className="est-empty">{a.protoEmpty}</p>}
          {prototypes.length > 0 && (
            <table>
              <thead>
                <tr>
                  <th>{a.protoWhen}</th>
                  <th>{a.protoKind}</th>
                  <th>{a.protoStatus}</th>
                  <th>{a.protoPrompt}</th>
                  <th>{a.protoTook}</th>
                  <th>{a.protoQa}</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                {prototypes.map((p) => (
                  <tr key={p.id}>
                    <td style={{ whiteSpace: "nowrap" }}>
                      {dt(p.created_at, locale)}
                      {p.ip && (
                        <span className="small muted" style={{ display: "block" }}>{p.ip}</span>
                      )}
                    </td>
                    <td>{d.proto.kinds[p.kind]}</td>
                    <td>
                      <span className={`badge badge-${p.status}`}>{p.status === "expired" ? a.protoExpired : p.status}</span>
                      {p.status === "building" && p.stage && (
                        <span className="small muted" style={{ display: "block" }}>{p.stage}</span>
                      )}
                      {p.error && (
                        <span className="small" style={{ display: "block", color: "var(--danger, #b00020)" }}>{p.error}</span>
                      )}
                    </td>
                    <td style={{ maxWidth: 420 }}>
                      {p.title && <strong style={{ display: "block" }}>{p.title}</strong>}
                      <span className="small" title={p.prompt}>
                        {p.prompt.length > 220 ? p.prompt.slice(0, 217).trimEnd() + "…" : p.prompt}
                      </span>
                    </td>
                    <td className="num" style={{ whiteSpace: "nowrap" }}>
                      {p.seconds !== null ? `${Math.floor(p.seconds / 60)}:${String(p.seconds % 60).padStart(2, "0")}` : ""}
                    </td>
                    <td style={{ whiteSpace: "nowrap" }}>
                      {p.qa_ok === true && "✓"}
                      {p.qa_ok === false && "✗"}
                      {p.repairs > 0 && (
                        <span className="small muted" style={{ marginLeft: 6 }}>{a.protoRepairs.replace("{n}", String(p.repairs))}</span>
                      )}
                    </td>
                    <td>
                      {(p.status === "ready" || p.status === "building") && (
                        <a href={`/${locale}/p/${p.id}`} target="_blank" rel="noreferrer">{a.protoOpen}</a>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      )}

      {tab === "customers" && (
        <div className="tbl-wrap">
          <table>
            <thead>
              <tr>
                <th>{a.customer}</th>
                <th>{a.projectsTile}</th>
                <th>{a.orders}</th>
                <th>{a.paidEur}</th>
              </tr>
            </thead>
            <tbody>
              {customers.map((c) => (
                <tr key={c.id}>
                  <td>
                    {c.email}
                    {c.is_admin && <span className="badge badge-type" style={{ marginLeft: 8 }}>admin</span>}
                  </td>
                  <td className="num">{c.projects}</td>
                  <td className="num">{c.orders}</td>
                  <td className="num">{c.paid_eur} €</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      </main>
    </div>
  );
}
