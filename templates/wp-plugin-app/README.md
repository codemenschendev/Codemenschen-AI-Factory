# WordPress plugin (Sofabuilt template)

- The plugin lives in `plugin/`: one main file `plugin/<slug>.php` with a complete plugin header
  (Plugin Name, Description, Version, Requires at least, Requires PHP, Author, License: GPLv2 or later,
  Text Domain: <slug>, and `Requires Plugins: woocommerce` when it needs WooCommerce), plus
  `readme.txt` in the WordPress.org format, `uninstall.php`, `includes/`, `assets/`, `languages/`.
- The folder name used for checks and the ZIP is the Text Domain (`<slug>`). `plugin/` holds only
  what ships: no hidden files, no Markdown. `Tested up to` in readme.txt is the current WordPress version.
- `npm test` runs `test/run.mjs`: PHP syntax, activation in a real WordPress (SQLite), Plugin Check
  (the tool the WordPress.org review team uses) and one case per automated acceptance criterion in
  `test/cases/<key>.mjs`.
- A case exports `key` and `run()`. Use the helpers in `test/wp.mjs`:
  `needWordPress()` first, then `wp([...])` for WP-CLI and `phpEval("...")` to run PHP inside
  WordPress with the plugin active, for example
  `phpEval("echo do_shortcode('[my_slug_list]');")`. Throw an Error when the result is wrong.
- Where the WordPress sandbox is not available (an old PHP), WordPress checks are skipped there and
  run in the factory's test stage.
