"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { API_BASE, api } from "@/lib/api";
import type { Dict, Locale } from "@/lib/i18n";

interface VideoRow {
  id: number;
  name: string;
  bytes: number;
  duration_seconds: number | null;
  created_at: string;
  project: { id: string; name: string };
}

const mb = (n: number) => `${(n / 1e6).toFixed(1)} MB`;

/**
 * Hidden preview of the marketing clips rendered on the server. Not linked from the nav yet.
 *
 * A <video src> cannot carry an Authorization header, so a clip is fetched with the bearer
 * token and played from a blob URL. Fine for clips of a few hundred KB; if these ever grow to
 * tens of MB this should become a short-lived signed URL instead of holding the file in memory.
 */
export function LabPanel({ locale, d }: { locale: Locale; d: Dict }) {
  // undefined = localStorage not read yet (server render + first paint). Same convention as
  // AccountPanel: never flash the sign-in prompt at a visitor who is already signed in.
  const [token, setToken] = useState<string | null | undefined>(undefined);
  const [videos, setVideos] = useState<VideoRow[] | null>(null);
  const [playing, setPlaying] = useState<{ id: number; url: string } | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    setToken(localStorage.getItem("aifactory-token"));
  }, []);

  useEffect(() => {
    if (!token) return;
    api<{ videos: VideoRow[] }>("/me/videos", { token })
      .then((r) => setVideos(r.videos))
      .catch(() => {
        localStorage.removeItem("aifactory-token");
        setToken(null);
      });
  }, [token]);

  // A blob URL stays allocated until it is revoked, so drop the previous one on every switch.
  useEffect(() => () => {
    if (playing) URL.revokeObjectURL(playing.url);
  }, [playing]);

  const l = d.lab;

  async function play(v: VideoRow) {
    setError(null);
    try {
      const res = await fetch(`${API_BASE}/api/me/videos/${v.id}/download`, {
        headers: { authorization: `Bearer ${token}` },
      });
      if (!res.ok) throw new Error(String(res.status));
      const url = URL.createObjectURL(await res.blob());
      setPlaying((prev) => {
        if (prev) URL.revokeObjectURL(prev.url);
        return { id: v.id, url };
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

  if (!videos) return <p className="est-empty">{l.loading}</p>;
  if (videos.length === 0) return <p className="est-empty">{l.empty}</p>;

  return (
    <div>
      <p className="est-empty">{l.intro}</p>
      {playing && (
        <video
          key={playing.id}
          src={playing.url}
          controls
          autoPlay
          style={{ width: "100%", maxWidth: 360, borderRadius: 12, marginBottom: 24 }}
        />
      )}
      {error && <p className="est-empty">{error}</p>}
      <ul style={{ listStyle: "none", padding: 0, margin: 0 }}>
        {videos.map((v) => (
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
                {v.project.name} · {new Date(v.created_at).toLocaleString(locale)} · {mb(v.bytes)}
                {v.duration_seconds ? ` · ${v.duration_seconds}s` : ""}
              </small>
            </span>
            <button type="button" onClick={() => play(v)}>
              {l.play}
            </button>
          </li>
        ))}
      </ul>
    </div>
  );
}
