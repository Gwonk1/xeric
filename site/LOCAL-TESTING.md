# Local testing

The site is static — two hand-written pages, `index.html` and `404.html`,
each self-contained with no build step, no dependencies and no external
assets. Preview it with PHP's built-in server, pointed at this directory.

## Quick preview

Run from the repository root (the `-t` path is relative to your working
directory, so this fails from inside `site/`):

```sh
php -S 127.0.0.1:8100 -t site/
```

Then open <http://localhost:8100/> in a browser. `Ctrl-C` stops the server.

This is the recommended way to preview on a normal dev machine — plain
`localhost`, plain HTTP, no gotchas.

### A note on `404.html`

PHP's built-in server has no router script here, so it won't invoke
`404.html` automatically for a made-up path — with no router, any
extensionless URL that doesn't match a real file silently falls back to
`index.html` instead of 404ing (only paths with a file extension, like
`/foo.txt`, get a real 404). To eyeball the 404 page itself, just load it
directly: <http://localhost:8100/404.html>. Wiring `404.html` up as the
*actual* error page for missing routes is a job for whatever real server
this ends up deployed behind (e.g. `ErrorDocument 404 /404.html` on
Apache, `error_page 404 /404.html;` on nginx) — out of scope for this
static-file preview.

## Optional: a fake local hostname (`dev.xeric.dev`)

If you want the address bar to show something closer to the real domain,
you can point a hostname at your own machine with an `/etc/hosts` entry:

```sh
echo "127.0.0.1  dev.xeric.dev" | sudo tee -a /etc/hosts
php -S dev.xeric.dev:8100 -t site/
```

**This will not work over plain HTTP in Chrome (or any modern Chromium
browser).** The `.dev` TLD is on the [HSTS preload
list](https://hstspreload.org/) baked into the browser itself — Chrome
force-upgrades *any* `.dev` hostname to HTTPS before it even checks DNS,
regardless of what's in `/etc/hosts` or whether the domain is real. A
plain `php -S` server only speaks HTTP, so the request just fails
(`ERR_SSL_PROTOCOL_ERROR` or similar). This isn't specific to this
project — it's true of every `.dev` name on every machine.

Workarounds, in order of how much they're worth the trouble:

- **Don't bother — use `http://localhost:8100`.** Simplest, always works,
  is what the "Quick preview" section above already gives you.
- Firefox does not enforce the `.dev` HSTS preload as strictly for
  arbitrary local names, so `http://dev.xeric.dev:8100` may work there —
  but don't rely on cross-browser behavior for anything real.
- If you actually need HTTPS locally (e.g. to test service-worker or
  cookie-scoped behavior), generate a local cert with `mkcert` and run
  `php -S` behind something that terminates TLS, or use a tool like
  `caddy` configured for local HTTPS. Overkill for a static landing page.

For this project, plain `localhost` is enough — the site has no
JavaScript and no cookie or origin-sensitive behavior to test.
