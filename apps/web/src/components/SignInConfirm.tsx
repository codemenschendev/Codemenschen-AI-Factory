"use client";

import { useState } from "react";
import Link from "next/link";
import type { Dict, Locale } from "@/lib/i18n";
import { Icon } from "./LineIcon";

/**
 * The page a sign-in e-mail opens (App\Support\MailLink). Nothing is spent until the button is
 * pressed: it posts the signed address to the API on this same origin (the signature names this
 * host) and hands the new token to the page it names, the way the old redirect did.
 */
export function SignInConfirm({ link, locale, d }: { link: string; locale: Locale; d: Dict }) {
  const t = d.signin;
  const [state, setState] = useState<"idle" | "busy" | "expired" | "failed">("idle");

  async function go() {
    setState("busy");
    try {
      const res = await fetch(`/api/${link}${window.location.search}`, { method: "POST", headers: { accept: "application/json" } });
      if (res.status === 403 || res.status === 410) return setState("expired");
      const body = (await res.json()) as { to?: string; token?: string };
      // Only a path of this site, never a URL.
      if (!res.ok || !body.token || !body.to || !/^\/(de|en)\/[a-z0-9/-]+$/.test(body.to)) return setState("failed");
      window.location.replace(`${body.to}#token=${encodeURIComponent(body.token)}`);
    } catch {
      setState("failed");
    }
  }

  if (state === "expired") {
    return (
      <div className="sh-status sh-status-bad" aria-live="polite">
        <span className="sh-status-ico"><Icon name="clock" /></span>
        <h2>{t.expired}</h2>
        <Link className="btn btn-primary" href={`/${locale}/account`}>{t.again}</Link>
      </div>
    );
  }

  return (
    <div className="sh-status" aria-live="polite">
      <span className="sh-status-ico"><Icon name="user" /></span>
      <h2>{t.title}</h2>
      <p>{t.lede}</p>
      <button type="button" className="btn btn-primary" onClick={go} disabled={state === "busy"}>
        {state === "busy" ? t.busy : t.button}
      </button>
      {state === "failed" && <p role="alert">{t.failed}</p>}
      <p>{t.note}</p>
    </div>
  );
}
