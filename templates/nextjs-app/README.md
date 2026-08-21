# Golden template — Next.js customer web app

Seeded into every new `nextjs`-stack project repo. Same pipeline conventions
as the Expo template: `acceptance-criteria.json` from the Product Agent,
`test/run.mjs` dependency-free runner with one module per automated criterion
under `test/cases/` exporting `{ key, run() }`, and `npm test` printing
`{passed, failed, criteria_results}` as its last line.
