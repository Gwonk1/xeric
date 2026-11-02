#!/usr/bin/env python3
"""
Xeric reference proxy — a LOCAL image server of the OpenAI-images shape
(stable-diffusion.cpp's sd-server, an A1111 or ComfyUI behind an OpenAI shim,
anything that answers POST /v1/images/generations), behind Xeric's generic
scene envelope.

    python3 tools/proxies/images.py --port 9100 --upstream http://127.0.0.1:1234
    python3 tools/proxies/images.py --port 9100 --upstream http://127.0.0.1:1234 \
        --pano --pano-words "equirectangular 360 panorama" --edits

    XERIC_SCENE_BASE=http://127.0.0.1:9100 XERIC_SCENE_KIND=generic

WHY BOTHER, WHEN XERIC ALREADY SPEAKS THIS SHAPE? Xeric's 'image' kind talks
to an images server directly — and gets a still, synchronous, with no
reference picture and no panorama, because /v1/images/generations has
nowhere to put either. This proxy is the difference: with --pano it asks the
same local machine for a 2:1 equirect (it has to have a 360 LoRA loaded;
saying so is the owner's job, which is why it is a flag), and with --edits
it shows the machine the last picture of the room through
/v1/images/edits, which is how a pie case stays a pie case on a model that
honours no seed reliably. The local-first viewport — no per-image cost — is
this file.

NO KEY, BY DEFAULT. A server on this desk needs none. If yours does, put it
in XERIC_PROXY_IMAGES_KEY and it is sent as a bearer token to the upstream
only: never read from the request, never logged, redacted in --dry-run.

LOOPBACK ONLY, BY DEFAULT. A GPU is a resource too. Clients that are not
127.0.0.0/8 or ::1 are refused unless --allow-remote is given.

SYNCHRONOUS, HONESTLY. A local diffusion model takes seconds to a minute and
there is no job to poll, so this proxy never answers 202. It answers the
picture or an error. Asked only for what it cannot make — a sound, a splat,
or a pano without --pano — it answers 400 with an `error` and no asset,
which the engine reads as "this machine does not do that".

THE UPSTREAM SHAPE IS A CONVENTION, NOT A STANDARD. Every field is in
to_upstream / from_upstream below, marked "per OpenAI images docs as of Nov
2026 - verify" (sd-server and the shims follow those docs more or less).
--dry-run prints the request it would send and answers a 1x1 PNG.

Standard library only. Python 3.9+.
"""

import argparse, base64, ipaddress, json, os, sys, time, uuid
import urllib.error, urllib.parse, urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

VENDOR = "openai-images"
KEY_ENV = "XERIC_PROXY_IMAGES_KEY"
PNG_1X1 = base64.b64decode(
    "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==")

ARGS = None


def can_make():
    return ["pano", "image"] if ARGS.pano else ["image"]


# --------------------------------------------------------------------------
# THE UPSTREAM'S DIALECT. Everything that names an images-API field is here.
# --------------------------------------------------------------------------

def to_upstream(envelope, kind):
    """Xeric's envelope -> {method, path, headers, fields, file, reference_used}.
    `file` is (name, mime, bytes) for an edits call, else None (a JSON body)."""
    prompt = envelope.get("prompt", "")
    if kind == "pano" and ARGS.pano_words:
        prompt = ARGS.pano_words.strip() + ", " + prompt
    fields = {
        "prompt": prompt,                                   # per OpenAI images docs as of Nov 2026 - verify
        "n": 1,                                             # per OpenAI images docs as of Nov 2026 - verify
        "response_format": "b64_json",                      # per OpenAI images docs as of Nov 2026 - verify
    }
    size = ARGS.pano_size if kind == "pano" else ARGS.size
    if size:
        fields["size"] = size                               # per OpenAI images docs as of Nov 2026 - verify ("WxH")
    if ARGS.model:
        fields["model"] = ARGS.model                        # per OpenAI images docs as of Nov 2026 - verify
    if isinstance(envelope.get("seed"), int):
        fields["seed"] = envelope["seed"]                   # not OpenAI's; sd-server / shims as of Nov 2026 - verify
    if envelope.get("negative"):
        fields["negative_prompt"] = envelope["negative"]    # not OpenAI's; sd-server / shims as of Nov 2026 - verify
    headers = {"Authorization": "Bearer " + key()} if key() else {}   # per OpenAI images docs as of Nov 2026 - verify
    ref = envelope.get("reference_image") if isinstance(envelope.get("reference_image"), dict) else None
    if ARGS.edits and ref and ref.get("b64"):
        try:
            raw = base64.b64decode(ref["b64"], validate=True)
        except ValueError:
            raw = b""
        if raw:
            mime = ref.get("mime") if ref.get("mime") in ("image/png", "image/jpeg", "image/webp") else "image/png"
            name = "reference." + mime.split("/")[1]
            return {"method": "POST", "path": "/v1/images/edits",  # per OpenAI images docs as of Nov 2026 - verify
                    "headers": headers, "fields": fields,
                    "file": ("image", name, mime, raw),        # field "image", per OpenAI images docs as of Nov 2026 - verify
                    "reference_used": True}
    return {"method": "POST", "path": "/v1/images/generations",   # per OpenAI images docs as of Nov 2026 - verify
            "headers": headers, "fields": fields, "file": None, "reference_used": False}


def from_upstream(j):
    """An images answer -> {b64, url, seed}."""
    d = (j.get("data") or [{}])[0] if isinstance(j.get("data"), list) else {}   # per OpenAI images docs as of Nov 2026 - verify
    return {
        "b64": d.get("b64_json") or None,                   # per OpenAI images docs as of Nov 2026 - verify
        "url": d.get("url") or None,                        # per OpenAI images docs as of Nov 2026 - verify
        "seed": d.get("seed", j.get("seed")),               # not OpenAI's; some local servers echo it - verify
    }


# --------------------------------------------------------------------------
# Plumbing. Nothing below names an upstream field.
# --------------------------------------------------------------------------

def key():
    return os.environ.get(KEY_ENV, "")


def log(line):
    sys.stderr.write(time.strftime("%Y-%m-%dT%H:%M:%S ") + "images " + line + "\n")
    sys.stderr.flush()


def redact(headers):
    return {k: ("<redacted>" if v else "") for k, v in headers.items()}


class _NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


_KEYED = urllib.request.build_opener(_NoRedirect)


class Upstream(Exception):
    pass


def multipart(fields, file):
    boundary = "xeric-" + uuid.uuid4().hex
    out = []
    for k, v in fields.items():
        out.append(("--%s\r\nContent-Disposition: form-data; name=\"%s\"\r\n\r\n%s\r\n"
                    % (boundary, k, v)).encode())
    fname, name, mime, raw = file
    out.append(("--%s\r\nContent-Disposition: form-data; name=\"%s\"; filename=\"%s\"\r\n"
                "Content-Type: %s\r\n\r\n" % (boundary, fname, name, mime)).encode() + raw + b"\r\n")
    out.append(("--%s--\r\n" % boundary).encode())
    return b"".join(out), "multipart/form-data; boundary=" + boundary


def send(req):
    url = ARGS.upstream.rstrip("/") + req["path"]
    h = {"Accept": "application/json", **req["headers"]}
    if req["file"] is not None:
        data, h["Content-Type"] = multipart(req["fields"], req["file"])
    else:
        data, h["Content-Type"] = json.dumps(req["fields"]).encode(), "application/json"
    r = urllib.request.Request(url, data=data, headers=h, method=req["method"])
    try:
        with _KEYED.open(r, timeout=ARGS.timeout) as resp:
            return json.loads(resp.read() or b"{}")
    except urllib.error.HTTPError as e:
        raise Upstream("upstream answered HTTP %d" % e.code)
    except (urllib.error.URLError, OSError, ValueError) as e:
        raise Upstream("upstream unreachable or not JSON: %s" % type(e).__name__)


def fetch_bytes(url):
    if urllib.parse.urlparse(url).scheme not in ("http", "https"):
        raise Upstream("upstream named an image that is not http(s)")
    try:
        with urllib.request.urlopen(url, timeout=ARGS.timeout) as r:
            raw = r.read(ARGS.max_bytes + 1)
    except (urllib.error.URLError, OSError) as e:
        raise Upstream("image fetch failed: %s" % type(e).__name__)
    if not raw or len(raw) > ARGS.max_bytes:
        raise Upstream("image empty or larger than --max-bytes")
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
        if k in can_make():
            return k, wanted
    return None, wanted


def submit(envelope):
    kind, wanted = pick_kind(envelope)
    if kind is None:
        hint = " (a pano needs --pano and a 360 LoRA)" if "pano" in wanted else ""
        return 400, {"error": "this proxy cannot make any of %s; it makes only %s%s"
                     % (", ".join(map(str, wanted)), ", ".join(can_make()), hint)}
    if not isinstance(envelope.get("prompt"), str) or not envelope["prompt"].strip():
        return 400, {"error": "no prompt"}
    started = time.time()
    req = to_upstream(envelope, kind)
    seed_sent = req["fields"].get("seed")
    meta = {"vendor": VENDOR, "seed_used": seed_sent, "reference_used": req["reference_used"]}

    if ARGS.dry_run:
        shown = dict(req["fields"])
        if len(str(shown.get("prompt", ""))) > 200:
            shown["prompt"] = shown["prompt"][:60] + "...<%d chars>" % len(shown["prompt"])
        print(json.dumps({"dry_run": VENDOR, "method": req["method"],
                          "url": ARGS.upstream.rstrip("/") + req["path"], "headers": redact(req["headers"]),
                          "body": shown, "multipart_file": (None if req["file"] is None else
                              {"field": req["file"][0], "name": req["file"][1], "bytes": len(req["file"][3])})},
                         indent=2), file=sys.stderr)
        meta.update(vendor_id="dry-run", elapsed_s=0.0, dry_run=True)
        return 200, {"kind": kind, "mime": "image/png", "b64": base64.b64encode(PNG_1X1).decode(),
                     "extras": [], "meta": meta}

    u = from_upstream(send(req))
    if u["b64"]:
        try:
            raw = base64.b64decode(u["b64"], validate=True)
        except ValueError:
            raise Upstream("upstream answered bad base64")
    elif u["url"]:
        raw = fetch_bytes(u["url"])
    else:
        raise Upstream("upstream answered with no image")
    if not raw:
        raise Upstream("upstream answered an empty image")
    if u["seed"] is not None:
        meta["seed_used"] = u["seed"]
    meta.update(vendor_id=None, elapsed_s=round(time.time() - started, 2))
    return 200, {"kind": kind, "mime": sniff(raw), "b64": base64.b64encode(raw).decode(),
                 "extras": [], "meta": meta}


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
    server_version = "xeric-proxy-images"

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
            return self.answer(200, {"proxy": "images", "vendor": VENDOR, "makes": can_make(),
                                     "dry_run": ARGS.dry_run})
        if path.startswith("/v1/scenes/"):
            # Synchronous: this proxy never issued a job, so there is none to ask after.
            return self.answer(404, {"error": "this proxy is synchronous and has no jobs"})
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
        self.answer(status, obj, " kind=" + obj.get("kind", "-"))


def main(argv=None):
    global ARGS
    p = argparse.ArgumentParser(description="Xeric reference proxy: a local OpenAI-images server.")
    p.add_argument("--port", type=int, default=9100)
    p.add_argument("--host", default="127.0.0.1")
    p.add_argument("--upstream", required=True, help="the images server's base, e.g. http://127.0.0.1:1234")
    p.add_argument("--model", default="", help="sent as `model` when given")
    p.add_argument("--size", default="", help="WxH for a still; omitted means the server's default")
    p.add_argument("--pano", action="store_true", help="the server has a 360 LoRA: offer kind pano")
    p.add_argument("--pano-words", default="equirectangular 360 degree panorama",
                   help="prepended to the prompt for a pano (the LoRA's trigger words)")
    p.add_argument("--pano-size", default="2048x1024", help="a 2:1 size for a pano")
    p.add_argument("--edits", action="store_true", help="send reference_image to /v1/images/edits")
    p.add_argument("--wait", type=float, default=20.0, help="accepted for symmetry; this proxy is synchronous")
    p.add_argument("--timeout", type=float, default=300.0, help="per upstream call")
    p.add_argument("--max-bytes", type=int, default=128 * 1024 * 1024)
    p.add_argument("--dry-run", action="store_true", help="print the upstream request, answer a 1x1 PNG")
    p.add_argument("--allow-remote", action="store_true", help="answer clients that are not loopback")
    ARGS = p.parse_args(argv)
    srv = ThreadingHTTPServer((ARGS.host, ARGS.port), Handler)
    log("listening on %s:%d upstream=%s makes=%s%s" % (ARGS.host, ARGS.port, ARGS.upstream,
        ",".join(can_make()), " DRY-RUN" if ARGS.dry_run else ""))
    try:
        srv.serve_forever()
    except KeyboardInterrupt:
        pass


if __name__ == "__main__":
    main()
