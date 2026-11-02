#!/usr/bin/env python3
"""
A fake of the three upstreams the reference proxies talk to, for
test_proxies.py and nothing else. It plays each vendor's SHAPE as the
proxies' to_upstream/from_upstream understand it — which is the point: it
proves the plumbing, not the vendor. Whether the vendor still answers like
this is what the "verify" comments in each proxy are for.

    python3 tools/proxies/fake_upstream.py --port 9200 [--key test-key]

  Skybox shape   POST /api/v1/skybox, GET /api/v1/imagine/requests/{id}
                 (x-api-key) — pending on the first poll, complete on the next.
  Marble shape   POST /marble/v1/worlds:generate, GET /marble/v1/operations/{id}
                 (WLT-Api-Key) — not done on the first poll, done on the next.
  Images shape   POST /v1/images/generations (JSON), /v1/images/edits
                 (multipart; every file field is reported) — synchronous, no key.
  Frames shape   POST /v1/frames {frame:{mime,b64}, caption, ...} — the
                 local video model worldmodel.py imagines: answers the frame
                 unchanged but for a 1-pixel magenta border.
  Assets         GET /files/<name> — a 1x1 PNG.
  For the test   GET /_seen — every request it got, bodies shortened, and
                 whether the right key header came (never the key itself).

Standard library only.
"""

import argparse, base64, json, struct, sys, threading, zlib
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

PNG_1X1 = base64.b64decode(
    "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==")
ARGS = None
SEEN, POLLS, LOCK = [], {}, threading.Lock()
COUNTER = [0]


def short(o, keep=False):
    """Bodies as the test needs them: base64 cut down, prompts whole."""
    if isinstance(o, dict):
        return {k: short(v, k in ("prompt", "text_prompt")) for k, v in o.items()}
    if isinstance(o, str) and len(o) > 120 and not keep:
        return o[:40] + "...<%d>" % len(o)
    return o


def form_parts(ctype, raw):
    """multipart/form-data -> [(name, filename or None, bytes)]. Enough for a test."""
    if "boundary=" not in ctype:
        return []
    b = ("--" + ctype.split("boundary=", 1)[1].strip().strip('"')).encode()
    out = []
    for chunk in raw.split(b)[1:]:
        if chunk.startswith(b"--"):
            break
        head, _, body = chunk.lstrip(b"\r\n").partition(b"\r\n\r\n")
        disp = head.decode("latin-1")
        name = disp.split('name="', 1)[1].split('"', 1)[0] if 'name="' in disp else ""
        fname = disp.split('filename="', 1)[1].split('"', 1)[0] if 'filename="' in disp else None
        out.append((name, fname, body[:-2] if body.endswith(b"\r\n") else body))
    return out


def png_read(raw):
    """An 8-bit RGB, filter-0 PNG (what worldmodel.py writes) -> (w, h, bytearray rgb)."""
    if raw[:8] != b"\x89PNG\r\n\x1a\n":
        raise ValueError("not a PNG")
    i, idat, w = 8, b"", None
    while i < len(raw):
        n = struct.unpack(">I", raw[i:i + 4])[0]
        t, d = raw[i + 4:i + 8], raw[i + 8:i + 8 + n]
        if t == b"IHDR":
            w, h, depth, ctype = struct.unpack(">IIBB", d[:10])
            if depth != 8 or ctype != 2:
                raise ValueError("not 8-bit RGB")
        elif t == b"IDAT":
            idat += d
        i += 12 + n
    data, stride, rgb = zlib.decompress(idat), w * 3, bytearray()
    for y in range(h):
        row = data[y * (stride + 1):(y + 1) * (stride + 1)]
        if row[0] != 0:
            raise ValueError("filtered rows are beyond this fake")
        rgb += row[1:]
    return w, h, rgb


def png_write(w, h, rgb):
    def chunk(t, d):
        return struct.pack(">I", len(d)) + t + d + struct.pack(">I", zlib.crc32(t + d) & 0xFFFFFFFF)
    rows = b"".join(b"\x00" + bytes(rgb[y * w * 3:(y + 1) * w * 3]) for y in range(h))
    return (b"\x89PNG\r\n\x1a\n" + chunk(b"IHDR", struct.pack(">IIBBBBB", w, h, 8, 2, 0, 0, 0))
            + chunk(b"IDAT", zlib.compress(rows)) + chunk(b"IEND", b""))


class H(BaseHTTPRequestHandler):
    def log_message(self, *a):
        pass

    def out(self, status, obj, ctype="application/json"):
        data = obj if isinstance(obj, bytes) else json.dumps(obj).encode()
        self.send_response(status)
        self.send_header("Content-Type", ctype)
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def base(self):
        return "http://%s:%d" % self.server.server_address[:2]

    def note(self, body, key_header):
        rec = {"method": self.command, "path": self.path, "body": short(body),
               "key_ok": (self.headers.get(key_header) == ARGS.key) if key_header else None,
               "content_type": self.headers.get("Content-Type", "")}
        with LOCK:
            SEEN.append(rec)
        return rec

    def body(self):
        n = int(self.headers.get("Content-Length") or 0)
        return self.rfile.read(n) if n else b""

    def next_id(self):
        with LOCK:
            COUNTER[0] += 1
            return COUNTER[0]

    def polled(self, vid):
        with LOCK:
            POLLS[vid] = POLLS.get(vid, 0) + 1
            return POLLS[vid]

    def do_POST(self):
        raw = self.body()
        if self.path == "/api/v1/skybox":
            j = json.loads(raw or b"{}")
            if not self.note(j, "x-api-key")["key_ok"]:
                return self.out(401, {"error": "bad key"})
            vid = self.next_id()
            return self.out(200, {"id": vid, "status": "pending", "seed": j.get("seed")})
        if self.path == "/marble/v1/worlds:generate":
            j = json.loads(raw or b"{}")
            if not self.note(j, "WLT-Api-Key")["key_ok"]:
                return self.out(401, {"error": "bad key"})
            return self.out(200, {"operation_id": "op-%d" % self.next_id(), "done": False})
        if self.path == "/v1/images/generations":
            j = json.loads(raw or b"{}")
            self.note(j, None)
            return self.out(200, {"created": 0, "data": [{"b64_json": base64.b64encode(PNG_1X1).decode(),
                                                          "seed": j.get("seed")}]})
        if self.path == "/v1/images/edits":
            ct = self.headers.get("Content-Type", "")
            parts = form_parts(ct, raw)
            files = [{"field": n, "filename": f, "png": v[:8] == PNG_1X1[:8], "bytes": len(v)}
                     for n, f, v in parts if f is not None]
            has_file = any(f["field"] == "image" and f["filename"].startswith("reference.") for f in files)
            self.note({"multipart": ct.startswith("multipart/form-data"), "has_file": has_file,
                       "files": files, "has_prompt": any(n == "prompt" for n, f, _ in parts)}, None)
            if not files:
                return self.out(400, {"error": "no image"})
            return self.out(200, {"created": 0, "data": [{"b64_json": base64.b64encode(PNG_1X1).decode()}]})
        if self.path == "/v1/frames":
            # The Matrix-Game shape, as worldmodel.py imagines it: a proxy frame in, a finished
            # frame out. This "model" hands the frame back with a 1-pixel magenta border, so a
            # test can tell the frame went through it.
            j = json.loads(raw or b"{}")
            fr = j.get("frame") if isinstance(j.get("frame"), dict) else {}
            try:
                w, h, rgb = png_read(base64.b64decode(fr.get("b64", "")))
            except (ValueError, KeyError, zlib.error):
                self.note({"frames": "bad"}, None)
                return self.out(400, {"error": "frame is not a PNG this fake can read"})
            self.note({"frame": [w, h], "caption": bool(j.get("caption")),
                       "first_frame": isinstance(j.get("first_frame"), dict),
                       "memory": len(j.get("memory") or []), "layout_depth": isinstance(j.get("layout_depth"), dict),
                       "index": j.get("index")}, None)
            for x in range(w):
                for y in (0, h - 1):
                    rgb[(y * w + x) * 3:(y * w + x) * 3 + 3] = b"\xff\x00\xff"
            for y in range(h):
                for x in (0, w - 1):
                    rgb[(y * w + x) * 3:(y * w + x) * 3 + 3] = b"\xff\x00\xff"
            return self.out(200, {"frame": {"mime": "image/png", "b64": base64.b64encode(png_write(w, h, rgb)).decode()}})
        self.out(404, {"error": "not found"})

    def do_GET(self):
        p = self.path
        if p == "/_seen":
            with LOCK:
                return self.out(200, list(SEEN))
        if p.startswith("/files/"):
            return self.out(200, PNG_1X1, "image/png")
        if p.startswith("/api/v1/imagine/requests/"):
            if not self.note(None, "x-api-key")["key_ok"]:
                return self.out(401, {"error": "bad key"})
            vid = p.rsplit("/", 1)[1]
            if self.polled("s" + vid) < 2:
                return self.out(200, {"request": {"id": int(vid), "status": "processing"}})
            return self.out(200, {"request": {"id": int(vid), "status": "complete", "seed": 428416240,
                                              "file_url": self.base() + "/files/pano-%s.png" % vid,
                                              "depth_map_url": self.base() + "/files/depth-%s.png" % vid}})
        if p.startswith("/marble/v1/operations/"):
            if not self.note(None, "WLT-Api-Key")["key_ok"]:
                return self.out(401, {"error": "bad key"})
            op = p.rsplit("/", 1)[1]
            if self.polled("m" + op) < 2:
                return self.out(200, {"operation_id": op, "done": False})
            b = self.base()
            return self.out(200, {"operation_id": op, "done": True, "response": {
                "world_id": "world-" + op,
                "assets": {"imagery": {"pano_url": b + "/files/%s-pano.png" % op},
                           "splats": {"spz_urls": {"full_res": b + "/files/%s.spz" % op}},
                           "mesh": {"collider_mesh_url": b + "/files/%s-collider.glb" % op}}}})
        self.out(404, {"error": "not found"})


def main():
    global ARGS
    ap = argparse.ArgumentParser()
    ap.add_argument("--port", type=int, default=9200)
    ap.add_argument("--key", default="test-key")
    ARGS = ap.parse_args()
    srv = ThreadingHTTPServer(("127.0.0.1", ARGS.port), H)
    sys.stderr.write("fake upstream on 127.0.0.1:%d\n" % ARGS.port)
    srv.serve_forever()


if __name__ == "__main__":
    main()
