#!/usr/bin/env python3
"""
Xeric reference proxy — a world service of the World Labs Marble shape,
behind Xeric's generic scene envelope.

    WLT_API_KEY=... python3 tools/proxies/marble.py --port 9100 \
        --upstream https://api.worldlabs.ai --model marble-1.0-draft

    XERIC_SCENE_BASE=http://127.0.0.1:9100 XERIC_SCENE_KIND=generic

AN EXAMPLE, NOT A DEPENDENCY. The engine names no vendor and this file is
where one is named, so that when Marble renames a field the fix is here and
engine/scene.php does not grow a branch for it.

THE KEY STAYS HERE. It is read from the environment variable WLT_API_KEY and
from nowhere else — not the request, not a flag — and it is never logged and
redacted in --dry-run. Xeric holds no key for this machine; the proxy on
loopback needs none, so there is nothing in the app to leak.

LOOPBACK ONLY, BY DEFAULT. A paid key listening for the whole network is an
open tab at somebody else's bar. Clients that are not 127.0.0.0/8 or ::1 get
a 403 unless --allow-remote says so in so many words.

A WORLD IS MORE THAN ITS PANORAMA, AND THE PANORAMA IS STILL THE ANSWER.
Every Marble world ships a pano, gaussian splats and a collider. A room is a
single vantage — you are standing in it — so the pano is what this proxy
answers with by default, the rest riding along as `extras`. When the
template's media.scene.kinds puts 'splat' first, it answers the .spz url as
kind 'splat' instead (Xeric fetches it at once; vendor links expire). Asked
only for what it cannot make — a sound, a still, a glb as the scene — it
answers 400 with an `error` and no asset, the engine's plain "cannot".

THE LAST PICTURE IS THE NEXT PROMPT. Marble takes an image to start a world
from, which is exactly what drift wants: when the envelope carries a
reference_image, the world_prompt becomes an image prompt with the text
alongside, and meta.reference_used says so. The seed is passed too; whether
it reproduces a room exactly is unconfirmed, so do not lean on it alone.

QUALITY IS A PRICE. `quality: draft` uses --model (default marble-1.0-draft,
about twenty seconds and twelve cents); `quality: full` uses --full-model when
one is given (marble-1.1, about five minutes and a dollar twenty) and --model
otherwise. Nobody spends the larger number without having typed it.

SLOW IS NOT BROKEN. Generation is an operation to poll. The proxy waits
inline up to --wait seconds (default 20), then answers 202 {status: pending,
job, retry_after_s} and Xeric's reaper asks again with GET /v1/scenes/{job}.

THE UPSTREAM WAS NOT VERIFIED FROM THE SESSION THAT WROTE THIS. Every vendor
field lives in to_upstream / from_upstream / poll_path, each marked "per World
Labs docs as of Nov 2026 - verify". --dry-run prints the request it would send
(key redacted) and answers a 1x1 PNG, so Xeric's path runs without an account.

Standard library only. Python 3.9+.
"""

import argparse, base64, ipaddress, json, os, sys, threading, time
import urllib.error, urllib.parse, urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

VENDOR = "worldlabs-marble"
KEY_ENV = "WLT_API_KEY"
CAN_MAKE = ["pano", "splat"]
PNG_1X1 = base64.b64decode(
    "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==")

ARGS = None
JOBS = {}             # job -> {started, reference_used, seed}, this process only
LOCK = threading.Lock()


# --------------------------------------------------------------------------
# THE VENDOR'S DIALECT. Everything that names a Marble field is in here.
# --------------------------------------------------------------------------

def auth():
    return {"WLT-Api-Key": key()}                                    # per World Labs docs as of Nov 2026 - verify


def to_upstream(envelope):
    """Xeric's envelope -> (method, path, headers, json body, reference_used)."""
    text = envelope.get("prompt", "")
    ref = envelope.get("reference_image") if isinstance(envelope.get("reference_image"), dict) else None
    if ref and ref.get("b64"):
        ext = {"image/png": "png", "image/jpeg": "jpg", "image/webp": "webp"}.get(ref.get("mime"), "png")
        world_prompt = {
            "type": "image",                                          # per World Labs docs as of Nov 2026 - verify
            "image_prompt": {                                         # per World Labs docs as of Nov 2026 - verify
                "source": "data_base64",                              # per World Labs docs as of Nov 2026 - verify
                "data_base64": ref["b64"],                            # per World Labs docs as of Nov 2026 - verify
                "extension": ext,                                     # per World Labs docs as of Nov 2026 - verify
            },
            "text_prompt": text,                                      # per World Labs docs as of Nov 2026 - verify
        }
    else:
        world_prompt = {"type": "text", "text_prompt": text}          # per World Labs docs as of Nov 2026 - verify
    model = ARGS.full_model if envelope.get("quality") == "full" and ARGS.full_model else ARGS.model
    brief = envelope.get("brief") if isinstance(envelope.get("brief"), dict) else {}
    body = {
        "world_prompt": world_prompt,                                 # per World Labs docs as of Nov 2026 - verify
        "model": model,                                               # per World Labs docs as of Nov 2026 - verify
        "display_name": str(brief.get("name") or envelope.get("fingerprint", "")[:12] or "xeric room")[:80],  # per World Labs docs as of Nov 2026 - verify
    }
    if isinstance(envelope.get("seed"), int):
        body["seed"] = envelope["seed"]                               # per World Labs docs as of Nov 2026 - verify (reproducibility unconfirmed)
    return "POST", "/marble/v1/worlds:generate", auth(), body, bool(ref and ref.get("b64"))  # per World Labs docs as of Nov 2026 - verify


def poll_path(vendor_id):
    return "/marble/v1/operations/" + urllib.parse.quote(str(vendor_id), safe="")  # per World Labs docs as of Nov 2026 - verify


def from_upstream(j):
    """An operation (submit or poll) -> {state, vendor_id, world_id, pano_url, spz_url, collider_url, seed, error}."""
    resp = j.get("response") if isinstance(j.get("response"), dict) else {}     # per World Labs docs as of Nov 2026 - verify
    assets = resp.get("assets") if isinstance(resp.get("assets"), dict) else {}  # per World Labs docs as of Nov 2026 - verify
    imagery = assets.get("imagery") or {}                                       # per World Labs docs as of Nov 2026 - verify
    splats = assets.get("splats") or {}                                         # per World Labs docs as of Nov 2026 - verify
    spz = splats.get("spz_urls") or {}                                          # per World Labs docs as of Nov 2026 - verify: a map of resolutions
    mesh = assets.get("mesh") or {}                                             # per World Labs docs as of Nov 2026 - verify
    spz_url = (spz.get("full_res") or next(iter(spz.values()), None)) if isinstance(spz, dict) else None  # per World Labs docs as of Nov 2026 - verify
    err = j.get("error")                                                        # per World Labs docs as of Nov 2026 - verify
    out = {
        "vendor_id": j.get("operation_id") or j.get("name"),                    # per World Labs docs as of Nov 2026 - verify
        "world_id": resp.get("world_id") or resp.get("id"),                     # per World Labs docs as of Nov 2026 - verify
        "pano_url": imagery.get("pano_url"),                                    # per World Labs docs as of Nov 2026 - verify
        "spz_url": spz_url,
        "collider_url": mesh.get("collider_mesh_url"),                          # per World Labs docs as of Nov 2026 - verify
        "seed": resp.get("seed"),                                               # per World Labs docs as of Nov 2026 - verify
        "error": (err.get("message") if isinstance(err, dict) else err) or None,
    }
    if err:
        out["state"] = "failed"
    elif j.get("done") is True:                                                 # per World Labs docs as of Nov 2026 - verify
        out["state"] = "done"
    else:
        out["state"] = "pending"
    return out


# --------------------------------------------------------------------------
# Plumbing. Nothing below names a vendor field.
# --------------------------------------------------------------------------

def key():
    return os.environ.get(KEY_ENV, "")


def log(line):
    sys.stderr.write(time.strftime("%Y-%m-%dT%H:%M:%S ") + "marble " + line + "\n")
    sys.stderr.flush()


def redact(headers):
    return {k: ("<redacted>" if v else "") for k, v in headers.items()}


def shorten(obj):
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


class Upstream(Exception):
    pass


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
    """An asset link, fetched without the key: a signed bucket url, not the API."""
    if urllib.parse.urlparse(url).scheme not in ("http", "https"):
        raise Upstream("upstream named an asset that is not http(s)")
    try:
        with urllib.request.urlopen(url, timeout=ARGS.timeout) as r:
            raw = r.read(ARGS.max_bytes + 1)
    except (urllib.error.URLError, OSError) as e:
        raise Upstream("asset fetch failed: %s" % type(e).__name__)
    if not raw or len(raw) > ARGS.max_bytes:
        raise Upstream("asset empty or larger than --max-bytes")
    return raw


def sniff(raw):
    if raw[:3] == b"\xff\xd8\xff":
        return "image/jpeg"
    if raw[:4] == b"RIFF" and raw[8:12] == b"WEBP":
        return "image/webp"
    return "image/png"


def pick_kind(envelope):
    wanted = envelope.get("kind_wanted") or ["pano", "image"]
    if not isinstance(wanted, list):
        wanted = [wanted]
    for k in wanted:
        if k in CAN_MAKE:
            return k, wanted
    return None, wanted


def finished(kind, u, info):
    out = {"kind": kind}
    extras = []
    if u["collider_url"]:
        extras.append({"role": "collider", "kind": "glb", "mime": "model/gltf-binary", "url": u["collider_url"]})
    if kind == "splat":
        if not u["spz_url"]:
            raise Upstream("upstream finished with no splat")
        out.update(mime="application/octet-stream", url=u["spz_url"])
        if u["pano_url"]:
            extras.append({"role": "pano", "kind": "pano", "url": u["pano_url"]})
    else:
        if not u["pano_url"]:
            raise Upstream("upstream finished with no pano_url")
        if ARGS.pass_url:
            out.update(mime="image/png", url=u["pano_url"])
        else:
            raw = fetch_bytes(u["pano_url"])
            out.update(mime=sniff(raw), b64=base64.b64encode(raw).decode())
        if u["spz_url"]:
            extras.append({"role": "splat", "kind": "splat", "mime": "application/octet-stream", "url": u["spz_url"]})
    out["extras"] = extras
    out["meta"] = {"vendor": VENDOR, "vendor_id": u["world_id"] or u["vendor_id"],
                   "seed_used": u["seed"] if u["seed"] is not None else info["seed"],
                   "elapsed_s": round(time.time() - info["started"], 2),
                   "reference_used": info["reference_used"], "model": info.get("model")}
    return out


def submit(envelope):
    kind, wanted = pick_kind(envelope)
    if kind is None:
        return 400, {"error": "this proxy cannot make any of %s; it makes only %s"
                     % (", ".join(map(str, wanted)), ", ".join(CAN_MAKE))}
    if not isinstance(envelope.get("prompt"), str) or not envelope["prompt"].strip():
        return 400, {"error": "no prompt"}
    started = time.time()
    method, path, headers, body, ref_used = to_upstream(envelope)
    info = {"started": started, "reference_used": ref_used, "seed": body.get("seed"), "model": body.get("model")}

    if ARGS.dry_run:
        print(json.dumps({"dry_run": VENDOR, "method": method, "url": ARGS.upstream.rstrip("/") + path,
                          "headers": redact(headers), "body": shorten(body)}, indent=2), file=sys.stderr)
        return 200, {"kind": kind, "mime": "image/png", "b64": base64.b64encode(PNG_1X1).decode(),
                     "extras": [], "meta": {"vendor": VENDOR, "vendor_id": "dry-run", "seed_used": info["seed"],
                                             "elapsed_s": 0.0, "reference_used": ref_used, "dry_run": True}}
    if not key():
        return 500, {"error": "the proxy has no key: set %s in its environment" % KEY_ENV}

    u = from_upstream(upstream_json(method, path, headers, body))
    if not u["vendor_id"]:
        raise Upstream("upstream accepted the request but named no operation")
    job = kind + "." + str(u["vendor_id"])
    with LOCK:
        JOBS[job] = info
    deadline = started + ARGS.wait
    while u["state"] == "pending" and time.time() < deadline:
        time.sleep(max(0.0, min(ARGS.poll_interval, deadline - time.time())))
        u = from_upstream(upstream_json("GET", poll_path(u["vendor_id"]), auth()))
    return settle(job, kind, u)


def settle(job, kind, u):
    with LOCK:
        info = JOBS.get(job) or {"started": time.time(), "reference_used": False, "seed": None, "model": None}
    if u["state"] == "pending":
        return 202, {"status": "pending", "job": job, "retry_after_s": ARGS.retry_after}
    if u["state"] == "failed":
        return 502, {"error": "upstream failed the job" + (": " + str(u["error"])[:200] if u["error"] else "")}
    out = finished(kind, u, info)
    with LOCK:
        JOBS.pop(job, None)
    return 200, out


def poll(job):
    kind, _, vendor_id = job.partition(".")
    if kind not in CAN_MAKE or not vendor_id:
        return 404, {"error": "no such job"}
    if not key():
        return 500, {"error": "the proxy has no key: set %s in its environment" % KEY_ENV}
    return settle(job, kind, from_upstream(upstream_json("GET", poll_path(vendor_id), auth())))


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
    server_version = "xeric-proxy-marble"

    def log_message(self, *a):
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
            return self.answer(200, {"proxy": "marble", "vendor": VENDOR, "makes": CAN_MAKE,
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
    p = argparse.ArgumentParser(description="Xeric reference proxy: Marble-shaped world service.")
    p.add_argument("--port", type=int, default=9100)
    p.add_argument("--host", default="127.0.0.1")
    p.add_argument("--upstream", default="https://api.worldlabs.ai",
                   help="per World Labs docs as of Nov 2026 - verify")
    p.add_argument("--model", default="marble-1.0-draft", help="for quality draft, and full unless --full-model")
    p.add_argument("--full-model", default="", help="for quality full, e.g. marble-1.1 (costs ten times a draft)")
    p.add_argument("--wait", type=float, default=20.0, help="seconds to wait inline before answering 202")
    p.add_argument("--poll-interval", type=float, default=3.0)
    p.add_argument("--retry-after", type=int, default=30, help="retry_after_s in a 202")
    p.add_argument("--timeout", type=float, default=60.0, help="per upstream call")
    p.add_argument("--max-bytes", type=int, default=128 * 1024 * 1024)
    p.add_argument("--pass-url", action="store_true", help="answer the pano's url instead of fetching to b64")
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
