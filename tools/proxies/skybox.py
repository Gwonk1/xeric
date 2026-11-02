#!/usr/bin/env python3
"""
Xeric reference proxy — a 360-degree panorama service of the Blockade Labs
Skybox shape, behind Xeric's generic scene envelope.

    SKYBOX_API_KEY=... python3 tools/proxies/skybox.py --port 9100 \
        --upstream https://backend.blockadelabs.com --style 67

    XERIC_SCENE_BASE=http://127.0.0.1:9100 XERIC_SCENE_KIND=generic

AN EXAMPLE, NOT A DEPENDENCY. Nothing in the engine knows this file exists.
It is here so the vendor's dialect lives in one small file somebody else can
keep up to date, and so engine/scene.php never grows a branch per vendor. If
the vendor changes a field name, this file breaks and the engine does not.

THE KEY STAYS HERE. It is read from the environment variable SKYBOX_API_KEY
and nowhere else: never from the request, never from a flag, never logged,
redacted in --dry-run. Xeric holds no key for this machine at all, because
the proxy on loopback is the machine Xeric talks to and it needs none. A key
that never reaches the app cannot leak out of the app.

LOOPBACK ONLY, BY DEFAULT. A proxy holding a paid key and listening for
anybody on the network is an open credit card. It refuses every client that
is not 127.0.0.0/8 or ::1 unless --allow-remote says otherwise in so many
words (and --host has to be widened as well for anybody else to reach it).

A PANORAMA AND NOTHING ELSE. Skybox makes equirects, so kind_wanted is read
in order and 'pano' is the only kind this proxy will answer with; asked for
anything else first it still answers pano if pano is on the list, and asked
only for what it cannot make (a sound, a splat) it answers 400 with an
`error` and no asset — the plain "cannot" the engine reads as "this machine
does not do that" rather than as a try that might go better next time.

SEED, YES; REFERENCE PICTURE, SORT OF. Skybox honours `seed`, which is how the
Bluebird stays the Bluebird. It does not take a picture to condition on; what
it takes is `remix_imagine_id`, the id of an earlier generation. So this proxy
remembers, in memory, which upstream id drew which fingerprint, and when a
request's reference_fingerprint is one it remembers it remixes that render.
The picture itself is not sent. meta.reference_used says which happened. A
restart forgets, and the room is drawn from the seed alone — honest, if less
steady.

SLOW IS NOT BROKEN. Skybox is asynchronous. The proxy waits inline for up to
--wait seconds (default 20) and then answers 202 {status: pending, job,
retry_after_s}; Xeric's reaper asks again later with GET /v1/scenes/{job}.

THE UPSTREAM WAS NOT VERIFIED FROM THE SESSION THAT WROTE THIS. Every vendor
field is in to_upstream / from_upstream / poll_path below, each marked
"per Blockade Labs docs as of Nov 2026 - verify". --dry-run prints the
request it would send (key redacted) and answers a 1x1 PNG, so Xeric's whole
path can be exercised without an account.

Standard library only. Python 3.9+.
"""

import argparse, base64, ipaddress, json, os, sys, threading, time
import urllib.error, urllib.parse, urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

VENDOR = "blockadelabs-skybox"
KEY_ENV = "SKYBOX_API_KEY"
CAN_MAKE = ["pano"]
PNG_1X1 = base64.b64decode(
    "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==")

ARGS = None
REMIX = {}            # fingerprint -> upstream imagine id, this process only
JOBS = {}             # job -> {started, reference_used, fingerprint, seed}
LOCK = threading.Lock()


# --------------------------------------------------------------------------
# THE VENDOR'S DIALECT. Everything that names a Skybox field is in here.
# --------------------------------------------------------------------------

def to_upstream(envelope, remix_id=None):
    """Xeric's envelope -> (method, path, headers, json body) for a new skybox."""
    body = {
        "prompt": envelope.get("prompt", ""),            # per Blockade Labs docs as of Nov 2026 - verify
        "skybox_style_id": int(ARGS.style),              # per Blockade Labs docs as of Nov 2026 - verify
    }
    if envelope.get("negative"):
        body["negative_text"] = envelope["negative"]     # per Blockade Labs docs as of Nov 2026 - verify
    if isinstance(envelope.get("seed"), int):
        body["seed"] = envelope["seed"]                  # per Blockade Labs docs as of Nov 2026 - verify
    if remix_id is not None:
        body["remix_imagine_id"] = remix_id              # per Blockade Labs docs as of Nov 2026 - verify
    headers = {"x-api-key": key()}                       # per Blockade Labs docs as of Nov 2026 - verify
    return "POST", "/api/v1/skybox", headers, body       # per Blockade Labs docs as of Nov 2026 - verify


def poll_path(vendor_id):
    """Where a submitted skybox is asked after."""
    return "/api/v1/imagine/requests/" + urllib.parse.quote(str(vendor_id), safe="")  # per Blockade Labs docs as of Nov 2026 - verify


def from_upstream(j):
    """An upstream answer (submit or poll) -> {state, vendor_id, file_url, depth_url, seed, error}.
    state is 'done', 'pending' or 'failed'."""
    r = j.get("request") if isinstance(j.get("request"), dict) else j   # per Blockade Labs docs as of Nov 2026 - verify: poll wraps in "request"
    status = str(r.get("status", "")).lower()                           # per Blockade Labs docs as of Nov 2026 - verify
    out = {
        "vendor_id": r.get("id"),                                       # per Blockade Labs docs as of Nov 2026 - verify
        "file_url": r.get("file_url") or None,                          # per Blockade Labs docs as of Nov 2026 - verify
        "depth_url": r.get("depth_map_url") or None,                    # per Blockade Labs docs as of Nov 2026 - verify
        "seed": r.get("seed"),                                          # per Blockade Labs docs as of Nov 2026 - verify
        "error": r.get("error_message") or None,                        # per Blockade Labs docs as of Nov 2026 - verify
    }
    if status == "complete" and out["file_url"]:                       # per Blockade Labs docs as of Nov 2026 - verify
        out["state"] = "done"
    elif status in ("error", "abort", "failed"):                        # per Blockade Labs docs as of Nov 2026 - verify
        out["state"] = "failed"
    else:
        out["state"] = "pending"                                        # pending, dispatched, processing
    return out


# --------------------------------------------------------------------------
# Plumbing. Nothing below names a vendor field.
# --------------------------------------------------------------------------

def key():
    return os.environ.get(KEY_ENV, "")


def log(line):
    sys.stderr.write(time.strftime("%Y-%m-%dT%H:%M:%S ") + "skybox " + line + "\n")
    sys.stderr.flush()


def redact(headers):
    return {k: ("<redacted>" if v else "") for k, v in headers.items()}


def shorten(obj):
    """A request body fit to print: long strings (base64) cut down."""
    if isinstance(obj, dict):
        return {k: shorten(v) for k, v in obj.items()}
    if isinstance(obj, list):
        return [shorten(v) for v in obj]
    if isinstance(obj, str) and len(obj) > 200:
        return obj[:60] + "...<%d chars>" % len(obj)
    return obj


class _NoRedirect(urllib.request.HTTPRedirectHandler):
    # The key goes to the address the owner typed, and nowhere a Location names.
    def redirect_request(self, *a, **k):
        return None


_KEYED = urllib.request.build_opener(_NoRedirect)


def upstream_json(method, path, headers, body=None):
    url = ARGS.upstream.rstrip("/") + path
    data = json.dumps(body).encode() if body is not None else None
    h = {"Accept": "application/json", **headers}
    if data is not None:
        h["Content-Type"] = "application/json"
    req = urllib.request.Request(url, data=data, headers=h, method=method)
    try:
        with _KEYED.open(req, timeout=ARGS.timeout) as r:
            return json.loads(r.read() or b"{}")
    except urllib.error.HTTPError as e:
        raise Upstream("upstream answered HTTP %d" % e.code)
    except (urllib.error.URLError, OSError, ValueError) as e:
        raise Upstream("upstream unreachable or not JSON: %s" % type(e).__name__)


def fetch_bytes(url):
    """An asset link, fetched without the key (it is a CDN, not the vendor's API)."""
    if urllib.parse.urlparse(url).scheme not in ("http", "https"):
        raise Upstream("upstream named an asset that is not http(s)")
    try:
        with urllib.request.urlopen(url, timeout=ARGS.timeout) as r:
            return r.read(ARGS.max_bytes + 1)
    except (urllib.error.URLError, OSError) as e:
        raise Upstream("asset fetch failed: %s" % type(e).__name__)


class Upstream(Exception):
    pass


def pick_kind(envelope):
    wanted = envelope.get("kind_wanted") or ["pano", "image"]
    if not isinstance(wanted, list):
        wanted = [wanted]
    for k in wanted:
        if k in CAN_MAKE:
            return k, wanted
    return None, wanted


def finished(job, u, seed_sent, started, ref_used):
    if not u["file_url"]:
        raise Upstream("upstream finished with no file_url")
    out = {"kind": "pano", "mime": "image/png"}
    if ARGS.pass_url:
        out["url"] = u["file_url"]
    else:
        raw = fetch_bytes(u["file_url"])
        if len(raw) > ARGS.max_bytes or not raw:
            raise Upstream("asset empty or larger than --max-bytes")
        out["mime"] = sniff(raw)
        out["b64"] = base64.b64encode(raw).decode()
    out["extras"] = [{"role": "depth", "url": u["depth_url"]}] if u["depth_url"] else []
    out["meta"] = {"vendor": VENDOR, "vendor_id": u["vendor_id"],
                   "seed_used": u["seed"] if u["seed"] is not None else seed_sent,
                   "elapsed_s": round(time.time() - started, 2), "reference_used": ref_used}
    return out


def sniff(raw):
    if raw[:8] == b"\x89PNG\r\n\x1a\n":
        return "image/png"
    if raw[:3] == b"\xff\xd8\xff":
        return "image/jpeg"
    if raw[:4] == b"RIFF" and raw[8:12] == b"WEBP":
        return "image/webp"
    return "image/png"


def submit(envelope):
    """POST /v1/scenes -> (status, answer)."""
    kind, wanted = pick_kind(envelope)
    if kind is None:
        return 400, {"error": "this proxy cannot make any of %s; it makes only %s"
                     % (", ".join(map(str, wanted)), ", ".join(CAN_MAKE))}
    if not isinstance(envelope.get("prompt"), str) or not envelope["prompt"].strip():
        return 400, {"error": "no prompt"}
    started = time.time()
    fp_ref = envelope.get("reference_fingerprint") or ""
    with LOCK:
        remix_id = REMIX.get(fp_ref) if fp_ref and envelope.get("reference_image") else None
    ref_used = "remix" if remix_id is not None else False
    method, path, headers, body = to_upstream(envelope, remix_id)
    seed_sent = body.get("seed")

    if ARGS.dry_run:
        print(json.dumps({"dry_run": VENDOR, "method": method, "url": ARGS.upstream.rstrip("/") + path,
                          "headers": redact(headers), "body": shorten(body)}, indent=2), file=sys.stderr)
        return 200, {"kind": kind, "mime": "image/png", "b64": base64.b64encode(PNG_1X1).decode(),
                     "extras": [], "meta": {"vendor": VENDOR, "vendor_id": "dry-run", "seed_used": seed_sent,
                                             "elapsed_s": 0.0, "reference_used": ref_used, "dry_run": True}}
    if not key():
        return 500, {"error": "the proxy has no key: set %s in its environment" % KEY_ENV}

    u = from_upstream(upstream_json(method, path, headers, body))
    if u["vendor_id"] is None:
        raise Upstream("upstream accepted the request but named no id")
    job = "pano." + str(u["vendor_id"])
    with LOCK:
        JOBS[job] = {"started": started, "reference_used": ref_used,
                     "fingerprint": envelope.get("fingerprint") or "", "seed": seed_sent}
    deadline = started + ARGS.wait
    while u["state"] == "pending" and time.time() < deadline:
        time.sleep(max(0.0, min(ARGS.poll_interval, deadline - time.time())))
        u = from_upstream(upstream_json("GET", poll_path(u["vendor_id"]), {"x-api-key": key()}))
    return settle(job, u)


def settle(job, u):
    with LOCK:
        info = JOBS.get(job, {"started": time.time(), "reference_used": False, "fingerprint": "", "seed": None})
    if u["state"] == "pending":
        return 202, {"status": "pending", "job": job, "retry_after_s": ARGS.retry_after}
    if u["state"] == "failed":
        return 502, {"error": "upstream failed the job" + (": " + str(u["error"])[:200] if u["error"] else "")}
    out = finished(job, u, info["seed"], info["started"], info["reference_used"])
    with LOCK:
        if info["fingerprint"]:
            REMIX[info["fingerprint"]] = u["vendor_id"]
        JOBS.pop(job, None)
    return 200, out


def poll(job):
    """GET /v1/scenes/{job} -> one cheap upstream poll."""
    kind, _, vendor_id = job.partition(".")
    if kind != "pano" or not vendor_id:
        return 404, {"error": "no such job"}
    if not key():
        return 500, {"error": "the proxy has no key: set %s in its environment" % KEY_ENV}
    return settle(job, from_upstream(upstream_json("GET", poll_path(vendor_id), {"x-api-key": key()})))


def client_allowed(host, allow_remote):
    if allow_remote:
        return True
    try:
        ip = ipaddress.ip_address(host.split("%")[0])
    except ValueError:
        return False
    if getattr(ip, "ipv4_mapped", None):
        ip = ip.ipv4_mapped
    return ip.is_loopback


class Handler(BaseHTTPRequestHandler):
    server_version = "xeric-proxy-skybox"

    def log_message(self, *a):        # one line per request is ours, below
        pass

    def answer(self, status, obj, note=""):
        data = json.dumps(obj).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)
        log("%s %s %s %d %.2fs%s" % (self.client_address[0], self.command, self.path.split("?")[0],
                                     status, time.time() - self._t, note))

    def guard(self):
        self._t = time.time()
        if client_allowed(self.client_address[0], ARGS.allow_remote):
            return True
        self.answer(403, {"error": "this proxy answers loopback clients only (--allow-remote to lift)"})
        return False

    def do_GET(self):
        if not self.guard():
            return
        path = urllib.parse.urlparse(self.path).path
        if path in ("/", ""):
            return self.answer(200, {"proxy": "skybox", "vendor": VENDOR, "makes": CAN_MAKE,
                                     "dry_run": ARGS.dry_run})
        if path.startswith("/v1/scenes/"):
            job = urllib.parse.unquote(path[len("/v1/scenes/"):])
            try:
                status, obj = poll(job)
            except Upstream as e:
                status, obj = 502, {"error": str(e)}
            return self.answer(status, obj, " job=" + job[:80])
        self.answer(404, {"error": "not found"})

    def do_POST(self):
        if not self.guard():
            return
        if urllib.parse.urlparse(self.path).path != "/v1/scenes":
            return self.answer(404, {"error": "not found"})
        try:
            n = int(self.headers.get("Content-Length") or 0)
        except ValueError:
            n = -1
        if n <= 0 or n > ARGS.max_bytes:
            return self.answer(413 if n > 0 else 400, {"error": "a JSON body of sensible size is required"})
        try:
            envelope = json.loads(self.rfile.read(n))
            if not isinstance(envelope, dict):
                raise ValueError
        except ValueError:
            return self.answer(400, {"error": "the body is not a JSON object"})
        try:
            status, obj = submit(envelope)
        except Upstream as e:
            status, obj = 502, {"error": str(e)}
        self.answer(status, obj, (" job=" + obj["job"][:80]) if "job" in obj else (" kind=" + obj.get("kind", "-")))


def main(argv=None):
    global ARGS
    p = argparse.ArgumentParser(description="Xeric reference proxy: Skybox-shaped panorama service.")
    p.add_argument("--port", type=int, default=9100)
    p.add_argument("--host", default="127.0.0.1")
    p.add_argument("--upstream", default="https://backend.blockadelabs.com",
                   help="per Blockade Labs docs as of Nov 2026 - verify")
    p.add_argument("--style", default="67", help="skybox_style_id (per Blockade Labs docs - verify)")
    p.add_argument("--wait", type=float, default=20.0, help="seconds to wait inline before answering 202")
    p.add_argument("--poll-interval", type=float, default=3.0)
    p.add_argument("--retry-after", type=int, default=20, help="retry_after_s in a 202")
    p.add_argument("--timeout", type=float, default=60.0, help="per upstream call")
    p.add_argument("--max-bytes", type=int, default=128 * 1024 * 1024)
    p.add_argument("--pass-url", action="store_true", help="answer the vendor's url instead of fetching to b64")
    p.add_argument("--dry-run", action="store_true", help="print the upstream request, answer a 1x1 PNG")
    p.add_argument("--allow-remote", action="store_true", help="answer clients that are not loopback")
    ARGS = p.parse_args(argv)
    srv = ThreadingHTTPServer((ARGS.host, ARGS.port), Handler)
    log("listening on %s:%d upstream=%s%s key=%s" % (ARGS.host, ARGS.port, ARGS.upstream,
        " DRY-RUN" if ARGS.dry_run else "", "set" if key() else "UNSET (%s)" % KEY_ENV))
    try:
        srv.serve_forever()
    except KeyboardInterrupt:
        pass


if __name__ == "__main__":
    main()
