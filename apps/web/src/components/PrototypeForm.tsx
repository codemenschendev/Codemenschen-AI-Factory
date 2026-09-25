"use client";

import { useEffect, useMemo, useState } from "react";
import { useRouter } from "next/navigation";
import { api } from "@/lib/api";
import { remember } from "@/lib/history";
import { getToken, useToken } from "@/lib/token";
import type { Dict, Locale } from "@/lib/i18n";
import { Icon } from "./LineIcon";

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

export type ProtoKind = "site" | "app" | "ads" | "email" | "campaign";
const KINDS: ProtoKind[] = ["site", "app", "ads", "email", "campaign"];

export function PrototypeForm({
  locale,
  d,
  initialKind = "site",
}: {
  locale: Locale;
  d: Dict;
  /** From `?kind=`: a service card on the home page opens the form on its own kind. */
  initialKind?: ProtoKind;
}) {
  const p = d.proto;
  const router = useRouter();
  const [prompt, setPrompt] = useState("");
  const [kind, setKind] = useState<ProtoKind>(initialKind);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  // Every build needs an e-mail (owner's decision 2026-09-19). A visitor who is not signed in
  // types it here; the build starts when they open the link we send, which also signs them in.
  const token = useToken();
  const [email, setEmail] = useState("");
  const needsEmail = token === null;
  const emailOk = !needsEmail || /.+@.+\..+/.test(email.trim());
  // Between the sentence and the build: a few questions the model thinks would change the
  // result most, each answered with a tap or in the visitor's own words, all of it optional.
  const [step, setStep] = useState<"write" | "asking" | "answer">("write");
  const [questions, setQuestions] = useState<Question[]>([]);
  const [picked, setPicked] = useState<Record<number, string>>({});
  const [own, setOwn] = useState<Record<number, string>>({});
  // The business's own pictures. They go up with the build, never on their own.
  const [files, setFiles] = useState<File[]>([]);
  const [dragging, setDragging] = useState(false);
  const previews = useMemo(() => files.map((f) => URL.createObjectURL(f)), [files]);
  useEffect(() => () => previews.forEach((u) => URL.revokeObjectURL(u)), [previews]);

  useEffect(() => {
    try {
      const draft = JSON.parse(localStorage.getItem(DRAFT) ?? "null");
      if (draft && typeof draft.prompt === "string") {
        // eslint-disable-next-line react-hooks/set-state-in-effect -- localStorage exists only in the browser, after hydration
        setPrompt(draft.prompt);
        if (KINDS.includes(draft.kind)) setKind(draft.kind);
        localStorage.removeItem(DRAFT);
      }
    } catch {}
  }, []);

  function addFiles(list: FileList | null) {
    if (!list) return;
    const ok = Array.from(list).filter((f) => ACCEPT.includes(f.type));
    setError(ok.length < list.length ? p.uploads.wrongType : null);
    const all = [...files, ...ok];
    if (all.length > MAX_UPLOADS) setError(p.uploads.tooMany.replace("{max}", String(MAX_UPLOADS)));
    setFiles(all.slice(0, MAX_UPLOADS));
  }

  /** 429 cap, 403 free prototype used, 422 no e-mail or too long: each says something the visitor can act on. */
  function failed(err: unknown) {
    const status = err && typeof err === "object" && "status" in err ? (err as { status: number }).status : 0;
    const body = err && typeof err === "object" && "body" in err ? (err as { body: { code?: string } | null }).body : null;
    setError(status === 429 ? p.limit : body?.code === "used" ? p.used : body?.code === "email" ? p.email.needed
      : status === 422 ? p.tooLong.replace("{max}", String(MAX_PROMPT)) : p.failed);
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
      if (needsEmail) {
        body.append("email", email.trim());
        body.append("locale", locale);
      }
      for (const f of await Promise.all(files.map(shrink))) body.append("images[]", f);
      // Read here, not in an effect: this only runs in the browser, on a click.
      // Without a token the answer is "waiting": the share page says to open the e-mail and
      // starts showing the build the moment the link is opened, on any device.
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
      <div className="pp-form">
        <div>
          <h2 className="pp-form-title">{q.title}</h2>
          <p className="pp-note">{q.lead}</p>
        </div>
        {questions.map((item, i) => (
          <fieldset key={i} className="pp-field">
            <legend className="pp-label">{item.q}</legend>
            <div className="pp-chips">
              {item.options.map((o) => (
                <button
                  key={o}
                  type="button"
                  className="pp-chip"
                  aria-pressed={picked[i] === o}
                  onClick={() => setPicked((cur) => ({ ...cur, [i]: cur[i] === o ? "" : o }))}
                >
                  {o}
                </button>
              ))}
            </div>
            <input
              type="text"
              className="pp-input"
              value={own[i] ?? ""}
              onChange={(e) => {
                const v = e.target.value.slice(0, 300);
                setOwn((cur) => ({ ...cur, [i]: v }));
              }}
              placeholder={q.own}
              aria-label={`${item.q} ${q.own}`}
            />
          </fieldset>
        ))}
        {error && <p className="pp-error">{error}</p>}
        <div className="pp-actions">
          <button type="button" className="btn btn-primary pp-submit" onClick={() => build(questions)} disabled={busy}>
            {busy ? p.building : q.build}
            {!busy && <Icon name="arrow" className="pp-btn-ico" />}
          </button>
          <button type="button" className="pp-link" onClick={() => build([])} disabled={busy}>{q.skip}</button>
          <button type="button" className="pp-link" onClick={() => setStep("write")} disabled={busy}>{q.back}</button>
        </div>
      </div>
    );
  }

  const waiting = busy || step === "asking";
  return (
    <form onSubmit={next} className="pp-form">
      {/* The choice comes before the sentence on purpose: what gets drawn changes what is worth
          writing, and a visitor who picks "app" describes screens rather than a company. */}
      <fieldset className="pp-field">
        <legend className="pp-label"><span className="pp-step">1</span>{p.kindLabel}</legend>
        <div className="pp-kinds">
          {KINDS.map((k) => (
            <button
              key={k}
              type="button"
              className="pp-kind"
              aria-pressed={kind === k}
              onClick={() => setKind(k)}
            >
              <Icon name={k} className="pp-kind-ico" />
              {p.kinds[k]}
            </button>
          ))}
        </div>
        <div className="pp-kind-hint">
          {/* eslint-disable-next-line @next/next/no-img-element -- the home page's fixed service pictures */}
          <img src={`/home/svc-${kind}.webp`} alt="" width={348} height={178} />
          <p>{p.kindHints[kind]}</p>
        </div>
      </fieldset>

      <label className="pp-field">
        <span className="pp-label"><span className="pp-step">2</span>{p.label}</span>
        <textarea
          className="pp-textarea"
          value={prompt}
          onChange={(e) => setPrompt(e.target.value.slice(0, MAX_PROMPT))}
          rows={prompt.length > 400 ? 10 : 5}
          maxLength={MAX_PROMPT}
          placeholder={p.hints[kind]}
        />
      </label>
      {/* The count only appears once there is something to count against: a visitor typing one
          sentence should not be told about a ceiling they will never reach. */}
      {prompt.length >= MAX_PROMPT / 2 && (
        <p className="pp-note pp-count">
          {prompt.length} / {MAX_PROMPT}
        </p>
      )}
      <div className="pp-field">
        <span className="pp-label"><span className="pp-step">3</span>{p.uploads.label}</span>
        <div className="pp-uploads">
          {previews.map((src, i) => (
            <div key={src} className="pp-thumb">
              {/* eslint-disable-next-line @next/next/no-img-element -- a local blob preview, nothing to optimise */}
              <img src={src} alt="" />
              <button
                type="button"
                aria-label={p.uploads.remove}
                title={p.uploads.remove}
                onClick={() => setFiles(files.filter((_, k) => k !== i))}
              >
                ×
              </button>
            </div>
          ))}
          {files.length < MAX_UPLOADS && (
            <label
              className={`pp-drop${dragging ? " is-over" : ""}`}
              onDragOver={(e) => {
                e.preventDefault();
                setDragging(true);
              }}
              onDragLeave={() => setDragging(false)}
              onDrop={(e) => {
                e.preventDefault();
                setDragging(false);
                addFiles(e.dataTransfer.files);
              }}
            >
              <Icon name="image" className="pp-drop-ico" />
              <span>
                <b>{files.length === 0 ? p.page.drop : p.uploads.add}</b>
                <small>{p.uploads.hint.replace("{max}", String(MAX_UPLOADS))}</small>
              </span>
              <input
                type="file"
                accept={ACCEPT.join(",")}
                multiple
                onChange={(e) => {
                  addFiles(e.target.files);
                  e.target.value = "";
                }}
              />
            </label>
          )}
        </div>
      </div>
      {needsEmail && (
        <label className="pp-field">
          <span className="pp-label"><span className="pp-step">4</span>{p.email.label}</span>
          <input
            type="email"
            className="pp-input pp-email"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            placeholder={p.signIn.email}
            autoComplete="email"
          />
          <span className="pp-note">{p.email.hint}</span>
        </label>
      )}
      {error && <p className="pp-error">{error}</p>}
      <div className="pp-actions">
        <button
          type="submit"
          className="btn btn-primary pp-submit"
          disabled={waiting || prompt.trim().length < 12 || !emailOk}
        >
          {busy ? p.building : step === "asking" ? p.asking : p.next}
          {!waiting && <Icon name="arrow" className="pp-btn-ico" />}
        </button>
        <p className="pp-note">{p.page.free}</p>
      </div>
    </form>
  );
}
