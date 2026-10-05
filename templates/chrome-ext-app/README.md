# Chrome extension (Sofabuilt template)

- The extension lives in `extension/` and is zipped as it is: `manifest.json` (Manifest V3) at its
  root, `popup.html` + `popup.js`, `options.html` + `options.js`, `background.js` (service worker,
  `"type": "module"`), `lib/` for the logic, `icons/` (16, 48, 128 px PNG, replace the placeholders
  with the product's own), `_locales/en/messages.json` (and `de` when German is in scope).
- Chrome Web Store rules: no remote code (no eval, no new Function, no scripts from other servers),
  only the permissions the features need, a single clear purpose, a description of at most 132
  characters.
- Keep logic in plain ES modules in `extension/lib/` that do not touch the page directly, so tests can
  import them. `npm test` runs `test/run.mjs`: manifest and file checks, store-policy checks, JS
  syntax, then one case per automated acceptance criterion in `test/cases/<key>.mjs`.
- A case exports `key` and `run()`. `import { chrome, loadLib } from "../chrome.mjs"`: `chrome` is an
  in-memory stand-in for the extension APIs (storage, runtime messages, alarms, context menus,
  notifications), `loadLib("lib/x.js")` imports a module from `extension/` with it in place. Throw
  an Error when the result is wrong.
