"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import Link from "next/link";
import { API_BASE, api } from "@/lib/api";
import type { Dict, Locale } from "@/lib/i18n";

interface AdRow {
  id: number;
  kind: "video" | "image";
  name: string;
  status: "queued" | "rendering" | "ready" | "failed";
  error: string | null;
  bytes: number;
  duration_seconds: number | null;
  created_at: string;
  project: { id: string; name: string };
}

interface ProjectRow {
  id: string;
  name: string;
}

const mb = (n: number) => `${(n / 1e6).toFixed(1)} MB`;

/**
 * Hidden preview of the marketing clips. Not linked from the nav yet.
 *
 * A <video src> cannot carry an Authorization header, so a clip is fetched with the bearer token
 * and played from a blob URL. Fine at a few hundred KB; tens of MB would call for a short-lived
 * signed URL instead of holding the file in memory.
 */
export function LabPanel({ locale, d }: { locale: Locale; d: Dict }) {
  // undefined = localStorage not read yet (server render + first paint). Same convention as
  // AccountPanel: never flash the sign-in prompt at a visitor who is already signed in.
  const [token, setToken] = useState<string | null | undefined>(undefined);
  const [ads, setAds] = useState<AdRow[] | null>(null);
  const [projects, setProjects] = useState<ProjectRow[]>([]);
  const [playing, setPlaying] = useState<{ id: number; url: string; kind: string } | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [prompt, setPrompt] = useState("");
  const [projectId, setProjectId] = useState("");
  const [format, setFormat] = useState("vertical");
  const [kind, setKind] = useState<"video" | "image">("video");
  const [background, setBackground] = useState<"auto" | "site" | "photo">("auto");
  const [sending, setSending] = useState(false);
  const playingRef = useRef<{ id: number; url: string; kind: string } | null>(null);

  const l = d.lab;

  useEffect(() => {
    setToken(localStorage.getItem("aifactory-token"));
  }, []);

  const load = useCallback(async () => {
    if (!token) return;
    const r = await api<{ ads: AdRow[] }>("/me/ads", { token });
    setAds(r.ads);
    return r.ads;
  }, [token]);

  useEffect(() => {
    if (!token) return;
    load().catch(() => {
      localStorage.removeItem("aifactory-token");
      setToken(null);
    });
    api<{ projects: ProjectRow[] }>("/me/projects", { token })
      .then((r) => {
        setProjects(r.projects);
        setProjectId((cur) => cur || r.projects[0]?.id || "");
      })
      .catch(() => setProjects([]));
  }, [token, load]);

  // While something is rendering, keep asking: the queue has no way to push to this page.
  useEffect(() => {
    if (!ads?.some((a) => a.status === "queued" || a.status === "rendering")) return;
    const t = setInterval(() => void load().catch(() => {}), 5000);
    return () => clearInterval(t);
  }, [ads, load]);

  // A blob URL stays allocated until it is revoked; drop the previous one on every switch.
  useEffect(() => {
    playingRef.current = playing;
  }, [playing]);
  useEffect(
    () => () => {
      if (playingRef.current) URL.revokeObjectURL(playingRef.current.url);
    },
    [],
  );

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    if (!projectId || prompt.trim().length < 10) return;
    setSending(true);
    setError(null);
    try {
      await api(`/me/projects/${projectId}/ads`, {
        token: token ?? undefined,
        method: "POST",
        body: JSON.stringify({ prompt, kind, format, background, language: locale }),
      });
      setPrompt("");
      await load();
    } catch {
      setError(l.busy);
    } finally {
      setSending(false);
    }
  }

  async function play(v: AdRow) {
    setError(null);
    try {
      const res = await fetch(`${API_BASE}/api/me/ads/${v.id}/download`, {
        headers: { authorization: `Bearer ${token}` },
      });
      if (!res.ok) throw new Error(String(res.status));
      const url = URL.createObjectURL(await res.blob());
      setPlaying((prev) => {
        if (prev) URL.revokeObjectURL(prev.url);
        return { id: v.id, url, kind: v.kind };
      });
    } catch {
      setError(l.failed);
    }
  }

  if (token === undefined) return <p className="est-empty">{l.loading}</p>;

  if (!token) {
    return (
      <p className="est-empty">
        {l.signIn} <Link href={`/${locale}/account`}>{l.goSignIn}</Link>
      </p>
    );
  }

  const label = (v: AdRow) =>
    v.status === "ready"
      ? `${new Date(v.created_at).toLocaleString(locale)} · ${mb(v.bytes)}`
      : v.status === "failed"
        ? `${l.failedState}: ${v.error ?? ""}`
        : v.status === "queued"
          ? l.queued
          : l.rendering;

  return (
    <div>
      <p className="est-empty">{l.intro}</p>

      <form onSubmit={submit} style={{ margin: "24px 0 32px", display: "grid", gap: 12 }}>
        <h2 style={{ margin: 0, fontSize: "1.1rem" }}>{l.createTitle}</h2>
        <label>
          {l.promptLabel}
          <textarea
            value={prompt}
            onChange={(e) => setPrompt(e.target.value)}
            rows={3}
            placeholder={l.promptHint}
            style={{ width: "100%", marginTop: 6 }}
          />
        </label>
        <div style={{ display: "flex", gap: 12, flexWrap: "wrap" }}>
          <label>
            {l.project}{" "}
            <select value={projectId} onChange={(e) => setProjectId(e.target.value)}>
              {projects.map((p) => (
                <option key={p.id} value={p.id}>
                  {p.name.slice(0, 40)}
                </option>
              ))}
            </select>
          </label>
          <label>
            {l.kind}{" "}
            <select value={kind} onChange={(e) => setKind(e.target.value as "video" | "image")}>
              <option value="video">{l.kindVideo}</option>
              <option value="image">{l.kindImage}</option>
            </select>
          </label>
          <label>
            {l.background}{" "}
            <select
              value={background}
              onChange={(e) => setBackground(e.target.value as "auto" | "site" | "photo")}
            >
              <option value="auto">{l.bgAuto}</option>
              <option value="site">{l.bgSite}</option>
              <option value="photo">{l.bgPhoto}</option>
            </select>
          </label>
          <label>
            {l.format}{" "}
            <select value={format} onChange={(e) => setFormat(e.target.value)}>
              <option value="vertical">{l.vertical}</option>
              <option value="square">{l.square}</option>
              <option value="landscape">{l.landscape}</option>
            </select>
          </label>
          <button type="submit" disabled={sending || !projectId || prompt.trim().length < 10}>
            {l.create}
          </button>
        </div>
      </form>

      {playing &&
        (playing.kind === "image" ? (
          <img
            key={playing.id}
            src={playing.url}
            alt=""
            style={{ width: "100%", maxWidth: 360, borderRadius: 12, marginBottom: 24 }}
          />
        ) : (
          <video
            key={playing.id}
            src={playing.url}
            controls
            autoPlay
            style={{ width: "100%", maxWidth: 360, borderRadius: 12, marginBottom: 24 }}
          />
        ))}
      {error && <p className="est-empty">{error}</p>}

      {!ads ? (
        <p className="est-empty">{l.loading}</p>
      ) : ads.length === 0 ? (
        <p className="est-empty">{l.empty}</p>
      ) : (
        <ul style={{ listStyle: "none", padding: 0, margin: 0 }}>
          {ads.map((v) => (
            <li
              key={v.id}
              style={{
                display: "flex",
                alignItems: "center",
                justifyContent: "space-between",
                gap: 16,
                padding: "12px 0",
                borderTop: "1px solid rgba(255,255,255,.12)",
              }}
            >
              <span>
                {v.name}
                <br />
                <small>
                  {v.kind === "image" ? l.kindImage : l.kindVideo} · {v.project.name.slice(0, 30)} ·{" "}
                  {label(v)}
                </small>
              </span>
              {v.status === "ready" && (
                <button type="button" onClick={() => play(v)}>
                  {v.kind === "image" ? l.open : l.play}
                </button>
              )}
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
