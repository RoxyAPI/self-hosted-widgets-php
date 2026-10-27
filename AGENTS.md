# AGENTS.md for Self-Hosted Widgets for PHP

This repo is one file, `roxy.php`, that lets any PHP site serve RoxyAPI widgets (`@roxyapi/ui` web components) from its own domain. Visitors load the widget files, the city search and every reading from the site itself. It holds a publishable key locked to the site in its allowed origins.

## What roxy.php answers

| Request | Answer |
|---|---|
| `GET /roxy.php?check=XXXXXX` (last six characters of the key) | A plain diagnostic page, one green, yellow or red line per requirement; without the matching code once a key is set, only a line asking for it. Also downloads the widget files on first run |
| `POST /roxy.php` with JSON `{ path, method, body, query }` | Every widget `submit-url` request, the city search included. Sent to `https://roxyapi.com/api/v2{path}` with the publishable key and the site as `Origin`; status and JSON body returned unchanged |
| `GET /roxy.php?q=...&limit=8` | A city search sent through `location-url` by older snippets, relayed to `/location/search` the same way |
| anything else | 404 |

The widget files are not served by `roxy.php`. It keeps a `roxy/` folder beside itself holding `dist/cdn/` and `dist/schemas/` of the latest `@roxyapi/ui` release, as they ship, and the web server serves them as static files. Forms read their schemas from beside the script (`/roxy/cdn/roxy-ui.js` reads `/roxy/schemas/`), so the two folders must stay side by side. The release is checked every six hours on API traffic and on the check page; the tarball is verified against the registry sha512 integrity before it replaces the folder.

## The snippet

```html
<script src="/roxy/cdn/roxy-ui.js" defer></script>
<script src="/roxy/cdn/locales/ru.js" defer></script>
<roxy-natal-chart submit-url="/roxy.php" hide-sections="hint"></roxy-natal-chart>
```

Any tag from `https://roxyapi.com/widgets` works: replace `publishable-key` with `submit-url`. Each tag fetches its own default reading; a config attribute (`period`, `mode`) or `data-endpoint` picks another. Tag names, config attributes and `data-endpoint` values come from the package `components-catalog.json` and the live spec at `https://roxyapi.com/api/v2/openapi.json`; never invent one.

## Rules for changes

- `roxy.php` relays any path under `/api/v2/` and holds no list of endpoints or files, so a new endpoint or a new widget release needs no change. Keep it that way.
- Extraction writes only regular `.js` and `.json` files under `cdn/` and `schemas/` whose every name part is letters, digits, `-` or `_` with one extension, so no link, hidden file or second extension (`x.php.js`) a server could run ever lands on the site.
- Keep the generic guards: a fixed API host, a plain path with no traversal, GET or POST only, a body size cap, a same-site check on every relayed request, and redirects followed only on requests that carry no key.
- The key is publishable, never secret: the API itself answers only what a publishable key may read for the allowed origins, so the relay needs no endpoint rules.
- PHP 7.4 through 8.4, curl with a PHP streams fallback, no Composer, no database.
- Never commit a key. The config line ships empty.
- Every change keeps `bash tests/run.sh` green; `LIVE=1 ROXY_TEST_KEY=pk_... bash tests/run.sh` adds the end to end checks.
- Public copy states what the owner gets, in English in `README.md` and Russian in `README.ru.md`, both complete.
