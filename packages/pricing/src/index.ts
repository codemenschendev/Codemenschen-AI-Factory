export {
  FEATURES,
  PACKAGE_PRICES,
  PRICE_MIN,
  DELIVERY_DAYS_LO,
  DELIVERY_DAYS_HI,
  HOSTING_MONTHLY,
  SITE_PRICE_EUR,
  SITE_HOSTING_MONTHLY_EUR,
  SITE_HOSTING_FREE_MONTHS,
  estimate,
  classifyAppType,
  quoteTotals,
} from "./estimate.ts";
export type {
  Audience,
  Platform,
  FeatureKey,
  AppType,
  EstimateInput,
  Estimate,
  PackageSelection,
  QuoteTotals,
} from "./estimate.ts";
