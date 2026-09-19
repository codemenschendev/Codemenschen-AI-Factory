"use client";

import { useEffect, useMemo, useState } from "react";
import { useRouter } from "next/navigation";
import { api } from "@/lib/api";
import { remember } from "@/lib/history";
import { getToken } from "@/lib/token";
import type { Dict, Locale } from "@/lib/i18n";

/**
 * The public, anonymous prompt box: this is the lead magnet. The sentence, and any pictures of
 * the business, are followed by a few questions the model picked for this sentence; then the
 * prototype is created and the visitor goes to its share page, which polls while it builds.
 *
 * What it built is remembered in this browser, so the page offers the visitor's own prototypes
 * instead of an empty box on every visit. Nothing about that leaves the machine.
 *
 * A token is sent only if the browser already has one, and only so that an operator testing the
 * funnel is not stopped by the daily cap meant for anonymous visitors. A customer's token changes
 * nothing: the API caps everyone who is not an admin.
 */
/** Mirrors PrototypeController::MAX_PROMPT. The API is the one that refuses; this only warns first. */
const MAX_PROMPT = 4000;
const DRAFT = "aifactory-proto-draft";
/** Mirrors PrototypeController::MAX_UPLOADS and the mimes it takes. */
const MAX_UPLOADS = 4;
const ACCEPT = ["image/jpeg", "image/png", "image/webp"];

type Question = { q: string; options: string[] };

/**
 * A phone photo is four to eight megabytes and the page needs a fraction of that. Anything
 * larger than 1.5 MB is redrawn at 2000px on its long side before it goes up; a PNG stays a
 * PNG, so a logo keeps its transparency. The original is sent when the browser cannot redraw it.
 */
async function shrink(file: File): Promise<File> {
  if (file.size < 1_500_000) return file;
  try {
    const bmp = await createImageBitmap(file);
    const scale = Math.min(1, 2000 / Math.max(bmp.width, bmp.height));
    const canvas = document.createElement("canvas");
    canvas.width = Math.round(bmp.width * scale);
    canvas.height = Math.round(bmp.height * scale);
    canvas.getContext("2d")?.drawImage(bmp, 0, 0, canvas.width, canvas.height);
    const type = file.type === "image/png" ? "image/png" : "image/jpeg";
    const blob = await new Promise<Blob | null>((ok) => canvas.toBlob(ok, type, 0.88));
    if (!blob || blob.size >= file.size) return file;
    return new File([blob], file.name.replace(/\.\w+$/, type === "image/png" ? ".png" : ".jpg"), { type });
  } catch {
    return file;
  }
}

export function PrototypeForm({ locale, d }: { locale: Locale; d: Dict }) {
  const p = d.proto;
  const router = useRouter();
  const [prompt, setPrompt] = useState("");
  const [kind, setKind] = useState<"site" | "app" | "ads" | "email">("site");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  // Ads, and any prototype after the first, ask for an e-mail. The text typed so far is kept
  // in this browser and comes back when the sign-in link returns the visitor to the form.
  const [signIn, setSignIn] = useState<"ads" | "again" | null>(null);
  const [email, setEmail] = useState("");
  const [sent, setSent] = useState(false);
  // Between the sentence and the build: a few questions the model thinks would change the
  // result most, each answered with a tap or in the visitor's own words, all of it optional.
  const [step, setStep] = useState<"write" | "asking" | "answer">("write");
  const [questions, setQuestions] = useState<Question[]>([]);
  const [picked, setPicked] = useState<Record<number, string>>({});
  const [own, setOwn] = useState<Record<number, string>>({});
  // The business's own pictures. They go up with the build, never on their own.
  const [files, setFiles] = useState<File[]>([]);
  const previews = useMemo(() => files.map((f) => URL.createObjectURL(f)), [files]);
  useEffect(() => () => previews.forEach((u) => URL.revokeObjectURL(u)), [previews]);

  useEffect(() => {
    try {
      const draft = JSON.parse(localStorage.getItem(DRAFT) ?? "null");
      if (draft && typeof draft.prompt === "string") {
        // eslint-disable-next-line react-hooks/set-state-in-effect -- localStorage exists only in the browser, after hydration
        setPrompt(draft.prompt);
        if (["site", "app", "ads", "email"].includes(draft.kind)) setKind(draft.kind);
        localStorage.removeItem(DRAFT);
      }
    } catch {}
  }, []);

  async function sendLink(e: React.SyntheticEvent) {
    e.preventDefault();
    try {
      localStorage.setItem(DRAFT, JSON.stringify({ prompt, kind }));
      localStorage.setItem("aifactory-next", `/${locale}/prototype`);
    } catch {}
    try {
      await api("/auth/magic-link", { method: "POST", body: JSON.stringify({ email, locale, join: true }) });
      setSent(true);
    } catch {
      setError(p.failed);
    }
  }

  function addFiles(list: FileList | null) {
    if (!list) return;
    const ok = Array.from(list).filter((f) => ACCEPT.includes(f.type));
    setError(ok.length < list.length ? p.uploads.wrongType : null);
    const all = [...files, ...ok];
    if (all.length > MAX_UPLOADS) setError(p.uploads.tooMany.replace("{max}", String(MAX_UPLOADS)));
    setFiles(all.slice(0, MAX_UPLOADS));
  }

  /** 401 sign_in, 429 cap, 422 too long: each says something the visitor can act on. */
  function failed(err: unknown) {
    const status = err && typeof err === "object" && "status" in err ? (err as { status: number }).status : 0;
    const body = err && typeof err === "object" && "body" in err ? (err as { body: { code?: string; reason?: string } | null }).body : null;
    if (status === 401 && body?.code === "sign_in") {
      setSignIn(body.reason === "ads" ? "ads" : "again");
    } else {
      setError(status === 429 ? p.limit : status === 422 ? p.tooLong.replace("{max}", String(MAX_PROMPT)) : p.failed);
    }
    setStep("write");
    setBusy(false);
  }

  async function next(e: React.FormEvent) {
    e.preventDefault();
    if (prompt.trim().length < 12) return;
    setError(null);
    setStep("asking");
    try {
      const r = await api<{ questions: Question[] }>("/prototypes/questions", {
        method: "POST",
        token: getToken() ?? undefined,
        body: JSON.stringify({ prompt, kind, locale }),
      });
      if (r.questions.length > 0) {
        setQuestions(r.questions);
        setPicked({});
        setOwn({});
        setStep("answer");
        return;
      }
    } catch (err) {
      const status = err && typeof err === "object" && "status" in err ? (err as { status: number }).status : 0;
      // Only a refusal stops here. A question step that failed is skipped, not a failed build.
      if (status === 401 || status === 422) return failed(err);
    }
    await build([]);
  }

  async function build(qs: Question[]) {
    setBusy(true);
    setError(null);
    const details = qs
      .map((q, i) => [q.q, (own[i] ?? "").trim() || picked[i] || ""] as const)
      .filter(([, a]) => a !== "")
      .map(([q, a]) => `${q} ${a}`)
      .join("\n");
    try {
      const body = new FormData();
      body.append("prompt", prompt);
      body.append("kind", kind);
      if (details) body.append("details", details);
      for (const f of await Promise.all(files.map(shrink))) body.append("images[]", f);
      // Read here, not in an effect: this only runs in the browser, on a click.
      const r = await api<{ id: string }>("/prototypes", { method: "POST", token: getToken() ?? undefined, body });
      // Written before the redirect, so a visitor who never comes back to this tab still finds
      // the prototype in the list next time. The title is filled in by the share page.
      remember({ id: r.id, kind, prompt: prompt.trim() });
      router.push(`/${locale}/p/${r.id}`);
    } catch (err) {
      failed(err);
    }
  }

  if (step === "answer") {
    const q = p.questions;
    return (
      <div style={{ display: "grid", gap: 20, maxWidth: 640 }}>
        <div>
          <h2 style={{ margin: "0 0 4px" }}>{q.title}</h2>
          <p className="small muted" style={{ margin: 0 }}>{q.lead}</p>
        </div>
        {questions.map((item, i) => (
          <fieldset key={i} style={{ border: 0, padding: 0, margin: 0, display: "grid", gap: 8 }}>
            <legend style={{ padding: 0, marginBottom: 4, fontWeight: 600 }}>{item.q}</legend>
            <div style={{ display: "flex", flexWrap: "wrap", gap: 8 }}>
              {item.options.map((o) => (
                <button
                  key={o}
                  type="button"
                  className="tab"
                  aria-pressed={picked[i] === o}
                  onClick={() => setPicked((cur) => ({ ...cur, [i]: cur[i] === o ? "" : o }))}
                  style={{ borderColor: picked[i] === o ? "currentColor" : undefined, fontWeight: picked[i] === o ? 600 : undefined }}
                >
                  {o}
                </button>
              ))}
            </div>
            <input
              type="text"
              value={own[i] ?? ""}
              onChange={(e) => {
                const v = e.target.value.slice(0, 300);
                setOwn((cur) => ({ ...cur, [i]: v }));
              }}
              placeholder={q.own}
              aria-label={`${item.q} ${q.own}`}
              style={{ padding: 10, fontSize: "1rem" }}
            />
          </fieldset>
        ))}
        {error && <p className="est-empty">{error}</p>}
        <div style={{ display: "flex", flexWrap: "wrap", gap: 8, alignItems: "center" }}>
          <button type="button" onClick={() => build(questions)} disabled={busy}>
            {busy ? p.building : q.build}
          </button>
          <button type="button" className="tab" onClick={() => build([])} disabled={busy}>{q.skip}</button>
          <button type="button" className="tab" onClick={() => setStep("write")} disabled={busy}>{q.back}</button>
        </div>
      </div>
    );
  }

  return (
    <form onSubmit={next} style={{ display: "grid", gap: 16, maxWidth: 640 }}>
      {/* The choice comes before the sentence on purpose: what gets drawn changes what is worth
          writing, and a visitor who picks "app" describes screens rather than a company. */}
      <fieldset style={{ border: 0, padding: 0, margin: 0, display: "grid", gap: 8 }}>
        <legend style={{ padding: 0, marginBottom: 4 }}>{p.kindLabel}</legend>
        <div style={{ display: "flex", flexWrap: "wrap", gap: 8 }}>
          {(["site", "app", "ads", "email"] as const).map((k) => (
            <button
              key={k}
              type="button"
              className="tab"
              aria-pressed={kind === k}
              onClick={() => setKind(k)}
              style={{
                borderColor: kind === k ? "currentColor" : undefined,
                fontWeight: kind === k ? 600 : undefined,
              }}
            >
              {p.kinds[k]}
            </button>
          ))}
        </div>
        <p className="small muted" style={{ margin: 0 }}>{p.kindHints[kind]}</p>
      </fieldset>

      <label>
        {p.label}
        <textarea
          value={prompt}
          onChange={(e) => setPrompt(e.target.value.slice(0, MAX_PROMPT))}
          rows={prompt.length > 400 ? 10 : 4}
          maxLength={MAX_PROMPT}
          placeholder={p.hints[kind]}
          style={{ width: "100%", marginTop: 8, fontSize: "1rem", padding: 12 }}
        />
      </label>
      {/* The count only appears once there is something to count against: a visitor typing one
          sentence should not be told about a ceiling they will never reach. */}
      {prompt.length >= MAX_PROMPT / 2 && (
        <p className="small muted" style={{ margin: "-8px 0 0", textAlign: "right" }}>
          {prompt.length} / {MAX_PROMPT}
        </p>
      )}
      <div style={{ display: "grid", gap: 8 }}>
        <span>{p.uploads.label}</span>
        <p className="small muted" style={{ margin: 0 }}>{p.uploads.hint.replace("{max}", String(MAX_UPLOADS))}</p>
        <div style={{ display: "flex", flexWrap: "wrap", gap: 8, alignItems: "center" }}>
          {previews.map((src, i) => (
            <div key={src} style={{ position: "relative", width: 72, height: 72 }}>
              {/* eslint-disable-next-line @next/next/no-img-element -- a local blob preview, nothing to optimise */}
              <img src={src} alt="" style={{ width: 72, height: 72, objectFit: "cover", borderRadius: 8, display: "block" }} />
              <button
                type="button"
                aria-label={p.uploads.remove}
                title={p.uploads.remove}
                onClick={() => setFiles(files.filter((_, k) => k !== i))}
                style={{ position: "absolute", top: -8, right: -8, width: 24, height: 24, padding: 0, borderRadius: 12, lineHeight: "20px" }}
              >
                ×
              </button>
            </div>
          ))}
          {files.length < MAX_UPLOADS && (
            <label className="tab" style={{ cursor: "pointer", position: "relative" }}>
              {p.uploads.add}
              <input
                type="file"
                accept={ACCEPT.join(",")}
                multiple
                onChange={(e) => {
                  addFiles(e.target.files);
                  e.target.value = "";
                }}
                style={{ position: "absolute", width: 1, height: 1, opacity: 0 }}
              />
            </label>
          )}
        </div>
      </div>
      {error && <p className="est-empty">{error}</p>}
      {signIn === null ? (
        <button type="submit" disabled={busy || step === "asking" || prompt.trim().length < 12} style={{ justifySelf: "start" }}>
          {busy ? p.building : step === "asking" ? p.asking : p.next}
        </button>
      ) : sent ? (
        <p>{p.signIn.sent}</p>
      ) : (
        <div style={{ display: "grid", gap: 8 }}>
          <p style={{ margin: 0 }}>{p.signIn[signIn]}</p>
          <div style={{ display: "flex", flexWrap: "wrap", gap: 8 }}>
            <input
              type="email"
              required
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              placeholder={p.signIn.email}
              aria-label={p.signIn.email}
              style={{ flex: "1 1 220px", padding: 10, fontSize: "1rem" }}
            />
            <button type="button" onClick={sendLink} disabled={!/.+@.+\..+/.test(email)}>
              {p.signIn.send}
            </button>
          </div>
        </div>
      )}
    </form>
  );
}
