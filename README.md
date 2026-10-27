# Self-Hosted Widgets for PHP

Serve RoxyAPI widgets entirely from your own domain with one PHP file: the widget, the city search and every reading load from your site, so visitors never wait on another host.

[Русская версия](https://github.com/RoxyAPI/self-hosted-widgets-php/blob/main/README.ru.md)

![A natal chart drawn by a RoxyAPI widget on a shop page, every file served by the shop itself](https://raw.githubusercontent.com/RoxyAPI/self-hosted-widgets-php/main/docs/images/10-widget-chart.png)

Copy one file, paste one key, paste one snippet. No plugin, no database, no server settings and no build step. New widgets and new widget releases arrive on their own.

## What do I need?

- A website on PHP 7.4 or newer. Any shared hosting or server works.
- A RoxyAPI plan. Plans are on the [pricing page](https://roxyapi.com/pricing).
- A way to put files on the site: the file manager in your hosting panel, or SFTP.

## Set it up in five steps

### 1. Create a publishable key for your site

Open [API keys in your account](https://roxyapi.com/account?tab=keys) and click **New key**. Choose **Publishable** and **Live**. Under **Allowed origins** type your domain, and on a second line the same domain with `www.` in front. Click **Create key** and copy the key that starts with `pk_`.

![The new key dialog with Publishable selected and the site domain in Allowed origins](https://raw.githubusercontent.com/RoxyAPI/self-hosted-widgets-php/main/docs/images/00-new-key.png)

A publishable key only answers for the domains you listed, so it is safe in a file on your site.

### 2. Download roxy.php and paste the key

Open [roxy.php](https://raw.githubusercontent.com/RoxyAPI/self-hosted-widgets-php/main/roxy.php) and save the page as `roxy.php` (Ctrl+S, or Cmd+S on a Mac). Open it in any text editor. On the line that starts with `$ROXY_KEY`, paste your key between the two quotes, then save.

![Pasting the publishable key between the quotes on the ROXY_KEY line and saving](https://raw.githubusercontent.com/RoxyAPI/self-hosted-widgets-php/main/docs/images/03-paste-key.png)

### 3. Upload it to your site root

Open the file manager in your hosting panel and go to the folder that holds your site, the one with `index.php` in it. It is usually called `public_html`, `www`, `htdocs` or `httpdocs`, or carries your domain name. Upload `roxy.php` there, next to `index.php`. Over SFTP it is the same folder.

![Uploading roxy.php next to index.php in a hosting file manager](https://raw.githubusercontent.com/RoxyAPI/self-hosted-widgets-php/main/docs/images/01-upload.png)

That is the whole installation. Nothing to register and nothing to configure on the server.

### 4. Open the check page

In your browser open `https://your-site.com/roxy.php?check=XXXXXX`, with your own domain and, in place of `XXXXXX`, the last six characters of your key. Only someone holding the key sees the report. Every line should be green. The first visit also downloads the widget files into a `roxy` folder next to `roxy.php`.

![The check page with every line green](https://raw.githubusercontent.com/RoxyAPI/self-hosted-widgets-php/main/docs/images/04-check-green-en.png)

A red line says what to fix right under it. If you see a page of code starting with `<?php` instead, read [I see code instead of the check page](#i-see-code-instead-of-the-check-page).

### 5. Paste the snippet into a page

Paste these three lines wherever your site accepts HTML: a page editor in its HTML mode, or the theme template. Step by step guides for popular site builders and shop platforms are in the [RoxyAPI docs](https://roxyapi.com/docs/widgets).

```html
<script src="/roxy/cdn/roxy-ui.js" defer></script>
<script src="/roxy/cdn/locales/ru.js" defer></script>
<roxy-natal-chart submit-url="/roxy.php" hide-sections="hint"></roxy-natal-chart>
```

Open the page. Visitors enter a birth date, a time and a city, pick the city from the list, and the chart appears.

![The city search on the page suggesting matching cities](https://raw.githubusercontent.com/RoxyAPI/self-hosted-widgets-php/main/docs/images/09-widget-city-search.png)

## How do I add more widgets?

Every widget in the [widgets gallery](https://roxyapi.com/widgets) works with this file. Copy its tag, and in that tag replace `publishable-key="pk_live_YOUR_KEY"` with `submit-url="/roxy.php"`. The two `<script>` lines go on a page once, however many widgets it shows. A transits table next to the chart:

```html
<roxy-transits-table submit-url="/roxy.php" hide-sections="hint"></roxy-transits-table>
```

New widgets and new readings need no change to `roxy.php`.

## Which languages are there?

Widgets follow the language of the page. The second script line loads the Russian labels; swap `ru` for `de`, `es`, `fr`, `hi`, `pt` or `tr`, or leave the line out for English. To fix the language whatever the page says, add `lang="ru"` to the widget tag.

## Troubleshooting

### The city search finds nothing

Open the check page and fix any red line. A red **Publishable key works for this site** means the key is incomplete or your domain is missing from its **Allowed origins**: add both your domain and the `www.` version. If every line is green, check that the page and `roxy.php` are on the same domain, and that the paths in the snippet start with `/roxy.php`.

### The widget does not appear at all

Open `https://your-site.com/roxy/cdn/roxy-ui.js` in the browser. If it is not found, open the check page once, which downloads the widget files, and confirm the last line is green.

If the check page stays red on **Widget files ready** while the lines above it are green, put the files there by hand: download `roxyapi-ui-{version}.tgz` from the [latest RoxyAPI UI release](https://github.com/RoxyAPI/ui/releases/latest), unpack it on your computer, and upload `package/dist/cdn` and `package/dist/schemas` into a `roxy` folder next to `roxy.php`, so you have `roxy/cdn/roxy-ui.js` and `roxy/schemas/`.

### The widgets are in English

Keep the `ru.js` line in the snippet and set the page language to Russian, or add `lang="ru"` to the widget tag.

### A red line on the check page

Each red line says what to do under it. **This server reaches roxyapi.com** in red means your hosting blocks outgoing connections; ask hosting support to allow outgoing HTTPS. **Folder for widget files is writable** in red means PHP may not create the `roxy` folder; set the permissions of the site folder to 755 in the file manager.

### A yellow line on the check page

Your widgets work. A yellow PHP line means a newer PHP release with security fixes is out, or your PHP version no longer receives them. Send that line to your hosting support and ask them to update PHP, or to confirm your PHP build already carries the fixes. Many panels also let you switch to the newest PHP version yourself.

### My site lives in a subfolder

Put `roxy.php` in that subfolder and add the folder to every path in the snippet: for a site at `your-site.com/shop/`, write `/shop/roxy/cdn/roxy-ui.js`, `/shop/roxy/cdn/locales/ru.js` and `/shop/roxy.php`.

### I see code instead of the check page

Your server runs only its own PHP entry file and shows other PHP files as text. Ask your hosting support or developer to let `roxy.php` run like `index.php`. On nginx that is one location block beside the one that serves `index.php`:

```nginx
location = /roxy.php {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root/roxy.php;
    fastcgi_pass 127.0.0.1:9000;
}
```

Use the same `fastcgi_pass` value as the `index.php` block, then open the check page again.

## How do updates arrive?

On their own. Every few hours `roxy.php` checks for a new widget release and, when there is one, downloads it into the `roxy` folder. The check page shows the version in use. There is nothing to update by hand.

## How do I remove it?

Delete the snippet from your pages, then delete `roxy.php` and the `roxy` folder from the server.

## Resources

- Every widget, live: [roxyapi.com/widgets](https://roxyapi.com/widgets)
- Widget documentation: [roxyapi.com/docs/widgets](https://roxyapi.com/docs/widgets)
- Component library: [github.com/RoxyAPI/ui](https://github.com/RoxyAPI/ui)
- Plans and keys: [roxyapi.com/pricing](https://roxyapi.com/pricing)
- For AI coding agents: [AGENTS.md](https://github.com/RoxyAPI/self-hosted-widgets-php/blob/main/AGENTS.md)

## License

MIT. See [LICENSE](https://github.com/RoxyAPI/self-hosted-widgets-php/blob/main/LICENSE).
