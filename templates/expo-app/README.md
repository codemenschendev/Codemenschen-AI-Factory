# Golden template — Expo customer app

Versioned Expo (React Native + TypeScript) starting point the worker seeds
into every new `expo`-stack project repo (workers/pipeline/src/repo.ts).

Conventions the pipeline relies on:

- `acceptance-criteria.json` — written by the Product Agent
- `test/run.mjs` — dependency-free runner; the Test Agent adds one module per
  automated criterion under `test/cases/` exporting `{ key, run() }`
- `npm test` prints `{passed, failed, criteria_results}` as its last line
- `app.json` PLACEHOLDER bundle ids are replaced at release
- Type A apps use `expo-sqlite` locally; Type B apps add the PocketBase client
  with an env-driven backend URL (MVP 2)

EAS build profiles land when the EAS account exists.
