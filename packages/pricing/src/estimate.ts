/**
 * Deterministic pricing engine — authoritative, server-side.
 *
 * Ported 1:1 from the appwerk prototype (appwerk/site/create.html:189-204),
 * extended with the AI-Factory app-type classification (Type A local /
 * Type B connected) and package pricing. All monetary values in EUR.
 */

export type Audience = "consumer" | "b2b" | "both";
export type Platform = "web" | "mobile" | "both";

/**
 * Feature catalog. Price sheet lowered 2026-08-27: the factory competes with
 * AI-builder subscriptions, so an add-on is priced like a few hours, not a
 * sprint. Mirror of Estimator::FEATURES (PHP) — keep in sync.
 */
export const FEATURES = {
  auth: { cost: 40, label: "User accounts / login", needsBackend: true },
  pay: { cost: 60, label: "Payments", needsBackend: true },
  dash: { cost: 40, label: "Dashboard / statistics", needsBackend: false },
  ai: { cost: 80, label: "AI features", needsBackend: true },
  notif: { cost: 30, label: "Push notifications", needsBackend: true },
  api: { cost: 40, label: "External integrations / API", needsBackend: true },
  offline: { cost: 30, label: "Offline capability", needsBackend: false },
  i18n: { cost: 20, label: "Multi-language", needsBackend: false },
} as const;

/** Development price clamp (EUR), mirror of Estimator::PRICE_MIN/MAX. */
export const PRICE_MIN = 149;
export const PRICE_MAX = 1500;

export type FeatureKey = keyof typeof FEATURES;

/** Type A = local-only, no monthly fee. Type B = hosted backend, monthly subscription. */
export type AppType = "A" | "B";

export interface EstimateInput {
  audience: Audience;
  platform: Platform;
  features: FeatureKey[];
}

export interface Estimate {
  devLo: number;
  devHi: number;
  marketingLo: number;
  marketingHi: number;
  /** One-time development price presented at checkout (clamped PRICE_MIN–PRICE_MAX). */
  price: number;
  retainerPctLabel: string;
  retainerLo: number;
  retainerHi: number;
  weeksLo: number;
  weeksHi: number;
  appType: AppType;
  /** Which selected features forced Type B (empty for Type A). */
  backendFeatures: FeatureKey[];
}

const rnd = (n: number, s: number): number => Math.round(n / s) * s;

export function classifyAppType(features: FeatureKey[]): {
  appType: AppType;
  backendFeatures: FeatureKey[];
} {
  const backendFeatures = features.filter((f) => FEATURES[f].needsBackend);
  return { appType: backendFeatures.length > 0 ? "B" : "A", backendFeatures };
}

export function estimate(input: EstimateInput): Estimate {
  const { audience, platform, features } = input;

  const base = platform === "mobile" ? 300 : platform === "both" ? 450 : 200;
  let dev = base + features.reduce((s, f) => s + FEATURES[f].cost, 0);
  if (audience === "b2b") dev *= 1.15;
  if (audience === "both") dev *= 1.1;

  const devLo = rnd(dev * 0.85, 50);
  const devHi = rnd(dev * 1.3, 50);

  // Suggested launch ad budget — informational, never part of the price.
  const marketingLo = audience === "consumer" ? 200 : 300;
  const marketingHi = audience === "consumer" ? 500 : 800;

  const price = Math.min(PRICE_MAX, Math.max(PRICE_MIN, rnd(dev * 1.2, 50)));

  const nf = features.length;
  const retainerPctLabel = nf <= 2 ? "5–6%" : nf <= 5 ? "6–8%" : "8–10%";
  const retainerLo = rnd(price * (nf <= 2 ? 0.05 : nf <= 5 ? 0.06 : 0.08), 5);
  const retainerHi = rnd(price * (nf <= 2 ? 0.06 : nf <= 5 ? 0.08 : 0.1), 5);

  const weeksLo = 3 + nf;
  const weeksHi = 6 + nf + (platform === "both" ? 2 : 0);

  return {
    devLo,
    devHi,
    marketingLo,
    marketingHi,
    price,
    retainerPctLabel,
    retainerLo,
    retainerHi,
    weeksLo,
    weeksHi,
    ...classifyAppType(features),
  };
}

/** Optional packages, per PLAN.md §8. Ad budget is pass-through, never a fee. */
export interface PackageSelection {
  storePublishing: boolean;
  transferAssist: boolean;
  marketingLaunch: boolean;
  adBudgetMonthly: 0 | 300 | 500 | 1000 | 2000;
}

export const PACKAGE_PRICES = {
  storePublishing: 79,
  transferAssist: 49,
  marketingLaunch: 129,
} as const;

/** One paid change-request round (mirror of Estimator::REVISION_PRICE_EUR). */
export const REVISION_PRICE_EUR = 39;

/** Monthly hosting & maintenance for Type B apps. Bands pending Patrick's final call. */
export const HOSTING_MONTHLY: Record<AppType, number> = {
  A: 0,
  B: 19,
};

export interface QuoteTotals {
  oneTime: number;
  monthlyHosting: number;
  adBudgetMonthly: number;
  firstYearTotal: number;
}

export function quoteTotals(est: Estimate, pkg: PackageSelection): QuoteTotals {
  const oneTime =
    est.price +
    (pkg.storePublishing ? PACKAGE_PRICES.storePublishing : 0) +
    (pkg.transferAssist ? PACKAGE_PRICES.transferAssist : 0) +
    (pkg.marketingLaunch ? PACKAGE_PRICES.marketingLaunch : 0);
  const monthlyHosting = HOSTING_MONTHLY[est.appType];
  return {
    oneTime,
    monthlyHosting,
    adBudgetMonthly: pkg.adBudgetMonthly,
    // Honest total-cost math per appwerk doc 05: fees only, ad budget shown separately.
    firstYearTotal: oneTime + monthlyHosting * 12,
  };
}
