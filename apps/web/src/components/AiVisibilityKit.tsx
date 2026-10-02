"use client";

import { useState } from "react";
import type { Dict } from "@/lib/i18n";

export interface AiVisibility {
  llms_txt: string;
  json_ld: Record<string, unknown>;
  faq: { locale: string; q: string; a: string }[];
  ai_prompts: { locale: string; prompt: string }[];
  directories: { name: string; url?: string; category?: string; tagline: string; description: string }[];
}

/**
 * "KI-Marketing" of the App-Marketing package (2026-10-02): what makes the app readable for ChatGPT
 * and other AI assistants, written by the marketing agent with the ads plan. Everything is ready to
 * copy: the two files for the website, the FAQ for the landing page, the directory listings and the
 * prompts to test each month whether assistants name the app.
 */
export function AiVisibilityKit({ kit, t, locale }: { kit: AiVisibility; t: Dict["project"]["ai"]; locale: string }) {
  const [copied, setCopied] = useState<string | null>(null);
  const copy = async (key: string, text: string) => {
    try {
      await navigator.clipboard.writeText(text);
      setCopied(key);
      setTimeout(() => setCopied((k) => (k === key ? null : k)), 1500);
    } catch {
      /* clipboard blocked: the text is on screen to select */
    }
  };
  const btn = (key: string, text: string) => (
    <button type="button" className="btn btn-ghost btn-sm" onClick={() => copy(key, text)}>
      {copied === key ? t.copied : t.copy}
    </button>
  );
  const jsonLd = `<script type="application/ld+json">\n${JSON.stringify({ "@context": "https://schema.org", ...kit.json_ld }, null, 2)}\n</script>`;
  const faq = kit.faq.filter((f) => f.locale === locale).length ? kit.faq.filter((f) => f.locale === locale) : kit.faq;
  const faqText = faq.map((f) => `${f.q}\n${f.a}`).join("\n\n");
  const prompts = kit.ai_prompts.filter((p) => p.locale === locale).length ? kit.ai_prompts.filter((p) => p.locale === locale) : kit.ai_prompts;

  return (
    <div className="card">
      <h3 style={{ margin: 0 }}>{t.title}</h3>
      <p className="muted small" style={{ margin: 0 }}>{t.lede}</p>

      <h4>{t.filesTitle}</h4>
      <p className="muted small">{t.llmsHint}</p>
      <pre className="kit-pre">{kit.llms_txt}</pre>
      <div className="kit-actions">{btn("llms", kit.llms_txt)}</div>
      <p className="muted small">{t.jsonLdHint}</p>
      <pre className="kit-pre">{jsonLd}</pre>
      <div className="kit-actions">{btn("ld", jsonLd)}</div>

      <h4>{t.faqTitle}</h4>
      <p className="muted small">{t.faqHint}</p>
      <dl className="kit-faq">
        {faq.map((f) => (
          <div key={f.q}>
            <dt>{f.q}</dt>
            <dd>{f.a}</dd>
          </div>
        ))}
      </dl>
      <div className="kit-actions">{btn("faq", faqText)}</div>

      <h4>{t.dirTitle}</h4>
      <p className="muted small">{t.dirHint}</p>
      <div className="kit-dirs">
        {kit.directories.map((dir) => (
          <div className="kit-dir" key={dir.name}>
            <b>{dir.url ? <a href={dir.url} target="_blank" rel="noopener noreferrer">{dir.name}</a> : dir.name}</b>
            {dir.category && <span className="muted small"> · {dir.category}</span>}
            <p style={{ margin: "6px 0 2px" }}><strong>{dir.tagline}</strong></p>
            <p className="muted small" style={{ margin: 0, whiteSpace: "pre-wrap" }}>{dir.description}</p>
            <div className="kit-actions">{btn(`dir-${dir.name}`, `${dir.tagline}\n\n${dir.description}`)}</div>
          </div>
        ))}
      </div>

      {prompts.length > 0 && (
        <>
          <h4>{t.promptsTitle}</h4>
          <p className="muted small">{t.promptsHint}</p>
          <ul className="kit-prompts">
            {prompts.map((p) => (
              <li key={p.prompt}>{p.prompt}</li>
            ))}
          </ul>
        </>
      )}
      <p className="muted small" style={{ marginBottom: 0 }}>{t.note}</p>
    </div>
  );
}
