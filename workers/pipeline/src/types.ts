export type Stage = "product" | "uiux" | "coding" | "test" | "fix" | "release" | "assets";

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
    fix_attempt: number;
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
