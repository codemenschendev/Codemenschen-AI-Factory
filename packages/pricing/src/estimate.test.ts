import { test } from "node:test";
import assert from "node:assert/strict";
import { estimate, quoteTotals, classifyAppType } from "./estimate.ts";

test("web consumer app with no features matches prototype floor", () => {
  const e = estimate({ audience: "consumer", platform: "web", features: [] });
  // dev = 200; price = clamp(round(200 * 1.2, 50)) = 250
  assert.equal(e.devLo, 150); // round(170, 50)
  assert.equal(e.devHi, 250); // round(260, 50)
  assert.equal(e.price, 250);
  assert.equal(e.retainerPctLabel, "5–6%");
  assert.equal(e.weeksLo, 3);
  assert.equal(e.weeksHi, 6);
  assert.equal(e.appType, "A");
});

test("mobile b2b app with many features stays under the 1500 cap", () => {
  const e = estimate({
    audience: "b2b",
    platform: "both",
    features: ["auth", "pay", "dash", "ai", "notif", "api"],
  });
  // dev = (450 + 290) * 1.15 = 851 → * 1.2 = 1021 → 1000
  assert.equal(e.price, 1000);
  assert.equal(e.retainerPctLabel, "8–10%");
  assert.equal(e.weeksHi, 6 + 6 + 2);
  assert.equal(e.appType, "B");
});

test("Type classification: offline/i18n/dash alone stay local (Type A)", () => {
  assert.equal(classifyAppType(["offline", "i18n", "dash"]).appType, "A");
  assert.equal(classifyAppType(["auth"]).appType, "B");
  assert.deepEqual(classifyAppType(["auth", "offline"]).backendFeatures, ["auth"]);
});

test("quote totals keep ad budget out of fees (doc 05 rule)", () => {
  const e = estimate({ audience: "consumer", platform: "mobile", features: ["auth"] });
  const t = quoteTotals(e, {
    storePublishing: true,
    transferAssist: false,
    marketingLaunch: true,
    adBudgetMonthly: 500,
  });
  assert.equal(t.oneTime, e.price + 79 + 129);
  assert.equal(t.monthlyHosting, 19); // Type B
  assert.equal(t.firstYearTotal, t.oneTime + 19 * 12);
  assert.equal(t.adBudgetMonthly, 500); // separate, never inside firstYearTotal
});
