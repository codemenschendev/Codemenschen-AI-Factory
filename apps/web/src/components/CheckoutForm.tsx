"use client";

import { useEffect, useMemo, useState } from "react";
import Link from "next/link";
import { useSearchParams } from "next/navigation";
import { api, ApiError, type QuoteResponse } from "@/lib/api";
import { Icon } from "./LineIcon";
import { eur, type Dict, type Locale } from "@/lib/i18n";

type Packages = Record<string, boolean>;

export function CheckoutForm({ locale, d }: { locale: Locale; d: Dict }) {
  const params = useSearchParams();
  const [quote, setQuote] = useState<QuoteResponse | null>(null);
  const [failed, setFailed] = useState(false);
  const [packages, setPackages] = useState<Packages>({});
  const [adBudget, setAdBudget] = useState(0);
  const [storeLocales, setStoreLocales] = useState<string[]>([]);
  const [email, setEmail] = useState("");
  const [waiver, setWaiver] = useState(false); // never pre-ticked (FAGG § 18)
  const [terms, setTerms] = useState(false); // a condition of the sale; the API refuses without it
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);

  // Resolve the quote from ?quote=, ?app= (catalog) or the wizard payload.
  useEffect(() => {
    const existing = params.get("quote");
    const app = params.get("app");
    // A website preview, bought as it is.
    const site = params.get("site");
    // Every supported store-listing language is on by default (the customer unticks).
    const load = (q: QuoteResponse) => {
      setQuote(q);
      setStoreLocales(q.store_locales ?? []);
    };
    const run = async () => {
      try {
        if (existing) {
          load(await api<QuoteResponse>(`/quotes/${existing}`));
        } else if (site) {
          load(
            await api<QuoteResponse>("/quotes", {
              method: "POST",
              body: JSON.stringify({ prototype_id: site, locale }),
            }),
          );
        } else if (app) {
          load(
            await api<QuoteResponse>("/quotes", {
              method: "POST",
              body: JSON.stringify({ listing_slug: app, locale }),
            }),
          );
        } else {
          const raw = sessionStorage.getItem("aifactory-custom");
          if (!raw) return setFailed(true);
          const c = JSON.parse(raw);
          load(
            await api<QuoteResponse>("/quotes", {
              method: "POST",
              body: JSON.stringify({
                idea: c.idea,
                audience: c.audience,
                platform: c.platform,
                features: c.features,
                locale,
              }),
            }),
          );
        }
      } catch {
        setFailed(true);
      }
    };
    void run();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const totals = useMemo(() => {
    if (!quote) return null;
    const oneTime =
      quote.price_eur +
      Object.entries(packages)
        .filter(([, on]) => on)
        .reduce((s, [k]) => s + (quote.packages[k] ?? 0), 0);
    // A website's first months of hosting are in the price.
    const months = Math.max(0, 12 - (quote.hosting_free_months ?? 0));
    return { oneTime, firstYear: oneTime + quote.hosting_monthly_eur * months };
  }, [quote, packages]);

  const c = d.checkout;
  const isSite = quote?.kind === "site";

  if (failed)
    return (
      <div className="sh-status">
        <span className="sh-status-ico">
          <Icon name="doc" />
        </span>
        <h2>{c.missingQuote}</h2>
        <Link className="btn btn-primary" href={`/${locale}/create`}>{d.nav.create}</Link>
      </div>
    );
  if (!quote || !totals) return <p className="co-wait">{c.working}</p>;

  const pay = async () => {
    setBusy(true);
    setNotice(null);
    try {
      const res = await api<{ checkout_url?: string }>("/checkout", {
        method: "POST",
        body: JSON.stringify({
          quote_id: quote.id,
          email,
          packages,
          ad_budget_monthly_eur: adBudget,
          store_locales: storeLocales,
          fagg_waiver: waiver,
          terms,
          locale,
        }),
      });
      if (res.checkout_url) window.location.href = res.checkout_url;
    } catch (e) {
      if (e instanceof ApiError && e.status === 503) setNotice(c.staging);
      else setNotice(c.errors.generic);
      setBusy(false);
    }
  };

  const emailOk = /.+@.+\..+/.test(email);
  const monthUnit = locale === "de" ? "Monat" : "month";

  return (
    <div className="wizard-cols">
      <div>
        <section className="co-panel">
          <h2>{c.step1}</h2>
          {isSite && (
            <div className="field">
              <span className="field-label">{c.site.includedTitle}</span>
              <ul className="co-included">
                {c.site.included.map((line) => (
                  <li key={line}>
                    <Icon name="check" className="pp-tick" />
                    {line}
                  </li>
                ))}
              </ul>
            </div>
          )}
          <div className="field">
            <span className="field-label">{c.packagesTitle}</span>
            <div className="choices">
              {Object.entries(quote.packages).map(([key, fee]) => (
                <label className="choice" key={key}>
                  <input
                    type="checkbox"
                    checked={!!packages[key]}
                    onChange={() =>
                      setPackages((p) => ({ ...p, [key]: !p[key] }))
                    }
                  />
                  {c.packages[key] ?? key}
                  <span className="cost">+{eur(fee, locale)}</span>
                </label>
              ))}
            </div>
          </div>

          {(quote.store_locales?.length ?? 0) > 0 && (
            <div className="field">
              <span className="field-label">{c.storeLocalesTitle}</span>
              <div className="choices">
                {quote.store_locales.map((code) => {
                  const on = storeLocales.includes(code);
                  return (
                    <label className="choice" key={code}>
                      <input
                        type="checkbox"
                        checked={on}
                        // at least one language stays selected
                        disabled={on && storeLocales.length === 1}
                        onChange={() =>
                          setStoreLocales((s) =>
                            on ? s.filter((x) => x !== code) : [...s, code],
                          )
                        }
                      />
                      {c.localeNames[code] ?? code.toUpperCase()}
                    </label>
                  );
                })}
              </div>
              <p className="small muted">{c.storeLocalesNote}</p>
            </div>
          )}
        </section>

        <section className="co-panel">
          <h2>{c.step2}</h2>
          <div className="field">
            <label htmlFor="ads">{c.adBudget}</label>
            <div className="choices">
              {quote.ad_budget_options.map((v) => (
                <label className="choice" key={v}>
                  <input
                    type="radio"
                    name="ads"
                    checked={adBudget === v}
                    onChange={() => setAdBudget(v)}
                  />
                  {v === 0 ? c.adNone : `${eur(v, locale)}/${monthUnit}`}
                </label>
              ))}
            </div>
            <p className="small muted">{c.adBudgetNote}</p>
          </div>
        </section>

        <section className="co-panel">
          <h2>{isSite ? c.site.step3 : c.step3}</h2>
          <div className="field">
            <label className="choice" style={{ alignItems: "flex-start" }}>
              <input
                type="checkbox"
                checked={waiver}
                onChange={(e) => setWaiver(e.target.checked)}
                style={{ marginTop: 4 }}
              />
              <span style={{ maxWidth: "56ch" }}>{isSite ? c.site.waiverLabel : c.waiverLabel}</span>
            </label>
            <p className="small muted">{isSite ? c.site.waiverOff : c.waiverOff}</p>
          </div>
        </section>

        <section className="co-panel">
          <h2>{c.step4}</h2>
          <div className="field">
            <label htmlFor="email">{c.email}</label>
            <input
              id="email"
              type="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              className="pp-input"
              autoComplete="email"
            />
          </div>

          <div className="field" style={{ marginBottom: 0 }}>
            <span className="field-label">{c.termsTitle}</span>
            <label className="choice" style={{ alignItems: "flex-start" }}>
              <input
                type="checkbox"
                checked={terms}
                onChange={(e) => setTerms(e.target.checked)}
                style={{ marginTop: 4 }}
              />
              <span style={{ maxWidth: "56ch" }}>{c.termsLabel}</span>
            </label>
            {/* New tab on purpose: reading the terms mid-checkout must not throw away the
                e-mail and the boxes already ticked. */}
            <p className="small muted">
              <Link href={`/${locale}/terms`} target="_blank" rel="noopener">
                {c.termsLink}
              </Link>
              {" · "}
              <Link href={`/${locale}/withdrawal`} target="_blank" rel="noopener">
                {c.withdrawalLink}
              </Link>
            </p>
          </div>
        </section>
      </div>

      <aside className="est-panel">
        <h3>{c.summary}</h3>
        <div className="row">
          <span className="muted">{isSite ? c.site.development : c.development}</span>
          <strong>{eur(quote.price_eur, locale)}</strong>
        </div>
        {isSite && quote.title && <p className="small muted" style={{ margin: "-6px 0 8px" }}>{quote.title}</p>}
        {Object.entries(packages)
          .filter(([, on]) => on)
          .map(([k]) => (
            <div className="row" key={k}>
              <span className="muted">{c.packages[k] ?? k}</span>
              <strong>{eur(quote.packages[k] ?? 0, locale)}</strong>
            </div>
          ))}
        <hr />
        <div className="row">
          <span className="muted">{c.totalToday}</span>
          <strong className="co-total">
            {eur(totals.oneTime, locale)}
          </strong>
        </div>
        {isSite && (
          <div className="row">
            <span className="muted">{c.site.hosting}</span>
            <span className="small" style={{ textAlign: "right" }}>
              {c.site.hostingIncluded.replace("{months}", String(quote.hosting_free_months)).replace("{eur}", eur(quote.hosting_monthly_eur, locale))}
            </span>
          </div>
        )}
        {!isSite && quote.app_type === "B" && (
          <>
            <div className="row">
              <span className="muted">{c.hostingLine}</span>
              <strong>
                {eur(quote.hosting_monthly_eur, locale)}/{monthUnit}
              </strong>
            </div>
            {/* Doc 05: the 12-month total up front is the highest-trust move. */}
            <div className="row">
              <span className="muted">{c.firstYear}</span>
              <strong>{eur(totals.firstYear, locale)}</strong>
            </div>
          </>
        )}
        {adBudget > 0 && (
          <div className="row">
            <span className="muted">{c.adBudget}</span>
            <strong>
              {eur(adBudget, locale)}/{monthUnit}
            </strong>
          </div>
        )}
        <hr />
        <button
          className="btn btn-primary btn-block"
          disabled={!emailOk || !terms || busy}
          onClick={pay}
        >
          {busy ? c.working : c.pay}
        </button>
        {notice && <p className="note">{notice}</p>}
      </aside>
    </div>
  );
}
