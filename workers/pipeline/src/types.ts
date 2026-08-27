export type Stage =
  | "product"
  | "uiux"
  | "coding"
  | "test"
  | "fix"
  | "revise"
  | "release"
  | "build"
  | "assets"
  | "marketing";

export interface StageJob {
  run_id: string;
  project_id: string;
  stage: Stage;
  attempt: number;
  callback_url: string;
  callback_token: string;
  context: {
    name: string;
    stack: "expo" | "nextjs";
    idea: string | null;
    listing_slug: string | null;
    audience: string | null;
    platform: string | null;
    features: string[] | null;
    app_type: "A" | "B";
    /** Store-listing languages ordered at checkout, e.g. ["de", "en"]. */
    store_locales: string[];
    fix_attempt: number;
    /** Change-request round (REVIEW → revise), 0 while the first build is in progress. */
    revision_round: number;
    /** The customer's change request being worked on by the revise stage, else null. */
    change_request: string | null;
    criteria: { key: string; criterion: string; kind: string; status: string }[];
    last_test_report: Record<string, unknown> | null;
  };
}

export interface StageResult {
  status: "succeeded" | "failed";
  output: Record<string, unknown>;
  error?: string;
  tokens_in: number;
  tokens_out: number;
}
