#!/usr/bin/env python3
"""
The reference proxies, tested against a fake of each upstream.

    python3 -I tools/proxies/test_proxies.py      exit 0 on pass

What it proves is the PLUMBING — the envelope in, the vendor's shape out and
back, the 202 path, the plain "cannot", the loopback guard, the key that is
used and never printed. It cannot prove the vendor still answers in the
shape fake_upstream.py plays; that is what each proxy's "verify" comments
and --dry-run are for.
"""

import base64, importlib.util, json, os, shutil, socket, subprocess, sys, tempfile, time
import urllib.error, urllib.request

sys.dont_write_bytecode = True   # importing the proxies below must leave no __pycache__ in the tree
HERE = os.path.dirname(os.path.abspath(__file__))
KEY = "sk-test-NEVER-PRINT-ME-4242"
FAILED = 0
PROCS = []
LOGDIR = tempfile.mkdtemp(prefix="xeric-proxies-")


def ok(name, cond, detail=""):
    global FAILED
    if cond:
        print("ok   - " + name)
    else:
        FAILED += 1
        print("FAIL - " + name + (" (%s)" % detail if detail else ""))


def free_port():
    s = socket.socket()
    s.bind(("127.0.0.1", 0))
    p = s.getsockname()[1]
    s.close()
    return p


def start(script, *args, env_key=None, name=None):
    port = free_port()
    env = {k: v for k, v in os.environ.items() if k not in ("SKYBOX_API_KEY", "WLT_API_KEY", "XERIC_PROXY_IMAGES_KEY")}
    if env_key:
        env[env_key] = KEY
    errpath = os.path.join(LOGDIR, (name or script) + ".log")
    err = open(errpath, "wb")
    p = subprocess.Popen([sys.executable, "-I", os.path.join(HERE, script), "--port", str(port), *args],
                         stdout=err, stderr=err, env=env)
    PROCS.append(p)
    for _ in range(100):
        try:
            socket.create_connection(("127.0.0.1", port), timeout=0.2).close()
            break
        except OSError:
            time.sleep(0.05)
    return port, errpath


def call(port, method, path, body=None, host="127.0.0.1"):
    data = json.dumps(body).encode() if body is not None else None
    req = urllib.request.Request("http://%s:%d%s" % (host, port, path), data=data, method=method,
                                 headers={"Content-Type": "application/json"} if data else {})
    try:
        with urllib.request.urlopen(req, timeout=30) as r:
            return r.status, json.loads(r.read())
    except urllib.error.HTTPError as e:
        return e.code, json.loads(e.read() or b"{}")


def is_png(b64):
    try:
        raw = base64.b64decode(b64, validate=True)
    except Exception:
        return False
    return raw[:8] == b"\x89PNG\r\n\x1a\n" and raw[12:16] == b"IHDR"


def seen(fake):
    return call(fake, "GET", "/_seen")[1]


def envelope(**over):
    e = {"channel": "look", "kind_wanted": ["pano", "image"],
         "prompt": "inside the Bluebird Diner, morning light. a wholesome everyday scene, nothing suggestive.",
         "brief": {"key": "bluebird", "name": "the Bluebird Diner"},
         "seed": 428416240, "fingerprint": "41048312ac3f57afb7223c08319e0f07e12433f0",
         "quality": "draft", "timeout_s": 300, "size": [4096, 2048]}
    e.update(over)
    return e


REF = {"mime": "image/png", "b64": base64.b64encode(base64.b64decode(
    "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==")).decode()}
LAYOUT = {"version": 1, "units": "m",     # engine/layout.php's shape, first person
          "room": {"width": 8, "depth": 10, "height": 3, "x0": -4, "x1": 4, "z0": -1, "z1": 9},
          "vantage": {"mode": "first_person", "x": 0, "y": 1.6, "z": 0, "yaw": 0, "projection": "equirectangular"},
          "objects": [
              {"id": "player:you", "label": "you", "kind": "player", "bearing": 0, "distance": 0,
               "x": 0, "z": 0, "y": 0, "w": 0.5, "d": 0.45, "h": 1.7, "seed": 1},
              {"id": "counter", "label": "the counter", "kind": "counter", "bearing": 0, "distance": 3.0,
               "x": 0.0, "z": 3.0, "w": 3.0, "d": 0.8, "h": 1.1, "seed": 11},
              {"id": "pie_case", "label": "pie case", "kind": "case", "bearing": 40, "distance": 2.5,
               "x": 1.6, "z": 1.9, "w": 0.8, "d": 0.6, "h": 1.5, "seed": 12},
              {"id": "person:dot", "label": "Dot Vance", "kind": "person", "bearing": 239, "distance": 2.2,
               "x": -1.886, "z": -1.133, "y": 0, "w": 0.5, "d": 0.45, "h": 1.7, "seed": 660118203}]}
DEPTH = {"mime": "image/png", "b64": REF["b64"]}
SEG = {"mime": "image/png", "b64": REF["b64"]}
SOUND = {"channel": "sound", "kind_wanted": ["audio"], "prompt": "the sound of the Bluebird Diner",
         "seed": 1375495960, "fingerprint": "sound:abc", "quality": "full", "timeout_s": 300}


def load(n):
    spec = importlib.util.spec_from_file_location("proxy_" + n, os.path.join(HERE, n + ".py"))
    m = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(m)
    return m


def get_bytes(port, path):
    with urllib.request.urlopen("http://127.0.0.1:%d%s" % (port, path), timeout=30) as r:
        return r.headers.get("Content-Type", ""), r.read()


def mjpeg(url, want=None, timeout=20):
    """Read a multipart/x-mixed-replace stream -> (content type, [part bytes], closed cleanly)."""
    r = urllib.request.urlopen(url, timeout=timeout)
    ctype = r.headers.get("Content-Type", "")
    b = ("--" + ctype.split("boundary=", 1)[-1]).encode()
    parts, closed = [], False
    while want is None or len(parts) < want:
        line = r.readline()
        if not line:
            break
        line = line.strip()
        if line == b + b"--":
            closed = True
            break
        if line != b:
            continue
        hdr = {}
        while True:
            l = r.readline().strip()
            if not l:
                break
            k, _, v = l.partition(b":")
            hdr[k.strip().lower()] = v.strip()
        parts.append(r.read(int(hdr.get(b"content-length", b"0"))))
        r.readline()
    r.close()
    return ctype, parts, closed


def jpeg_markers(raw):
    """The marker segments of a JPEG up to its scan, or None if it is not one."""
    if raw[:2] != b"\xff\xd8" or raw[-2:] != b"\xff\xd9":
        return None
    i, out = 2, []
    while i + 4 <= len(raw):
        if raw[i] != 0xFF:
            return None
        m = raw[i + 1]
        out.append(m)
        if m == 0xDA:
            return out
        i += 2 + int.from_bytes(raw[i + 2:i + 4], "big")
    return None


def shape(name, st, a, kind, vendor):
    ok(name + ": 200 with kind " + kind, st == 200 and a.get("kind") == kind, "%s %s" % (st, json.dumps(a)[:200]))
    ok(name + ": mime image/png and b64 decodes to a PNG", a.get("mime") == "image/png" and is_png(a.get("b64", "")))
    ok(name + ": exactly one of b64|url", ("b64" in a) != ("url" in a))
    m = a.get("meta", {})
    ok(name + ": meta names vendor, vendor_id, seed_used, elapsed_s",
       m.get("vendor") == vendor and "vendor_id" in m and "seed_used" in m and "elapsed_s" in m, json.dumps(m))


def main():
    fake, _ = start("fake_upstream.py", "--key", KEY, name="fake")
    up = "http://127.0.0.1:%d" % fake

    # ------------------------------------------------------------------
    print("# skybox")
    sky0, sky0log = start("skybox.py", "--upstream", up, "--wait", "0", "--style", "67",
                          env_key="SKYBOX_API_KEY", name="skybox-wait0")
    st, a = call(sky0, "POST", "/v1/scenes", envelope())
    ok("skybox, --wait 0: 202 pending with a job and retry_after_s",
       st == 202 and a.get("status") == "pending" and a.get("job", "").startswith("pano.")
       and isinstance(a.get("retry_after_s"), int), json.dumps(a))
    job = a.get("job", "x")
    st, a = call(sky0, "GET", "/v1/scenes/" + job)
    ok("skybox: first poll still pending, same job", st == 202 and a.get("job") == job, json.dumps(a))
    st, a = call(sky0, "GET", "/v1/scenes/" + job)
    shape("skybox: second poll", st, a, "pano", "blockadelabs-skybox")
    ok("skybox: depth map rides as an extra", any(x.get("role") == "depth" for x in a.get("extras", [])))
    ok("skybox: the seed used is the vendor's", a.get("meta", {}).get("seed_used") == 428416240)
    ok("skybox: no reference was used the first time", a.get("meta", {}).get("reference_used") is False)
    sub = [r for r in seen(fake) if r["path"] == "/api/v1/skybox"]
    ok("skybox: upstream got prompt, style id, seed and the key in x-api-key",
       sub and sub[-1]["key_ok"] and sub[-1]["body"].get("skybox_style_id") == 67
       and sub[-1]["body"].get("seed") == 428416240 and sub[-1]["body"].get("prompt"))

    sky5, _ = start("skybox.py", "--upstream", up, "--wait", "5", "--poll-interval", "0.1",
                    env_key="SKYBOX_API_KEY", name="skybox-wait5")
    st, a = call(sky5, "POST", "/v1/scenes", envelope())
    shape("skybox, --wait 5: finished inline", st, a, "pano", "blockadelabs-skybox")
    # The second look at the same room: the reference rides along; skybox remixes the remembered id.
    st, a = call(sky5, "POST", "/v1/scenes", envelope(fingerprint="later-look", reference_image=REF,
                                                      reference_fingerprint="41048312ac3f57afb7223c08319e0f07e12433f0"))
    sub = [r for r in seen(fake) if r["path"] == "/api/v1/skybox"]
    ok("skybox: a remembered reference becomes remix_imagine_id", st == 200
       and "remix_imagine_id" in sub[-1]["body"] and a["meta"].get("reference_used") == "remix", json.dumps(a)[:200])

    call(sky5, "POST", "/v1/scenes", envelope(fingerprint="laid-out", layout=LAYOUT))
    sub = [r for r in seen(fake) if r["path"] == "/api/v1/skybox"]
    ok("skybox: the layout's labels lead the prompt, nearest first, the rating clause still last",
       sub[-1]["body"]["prompt"].startswith("nearest first: Dot Vance, pie case, the counter. inside the Bluebird")
       and sub[-1]["body"]["prompt"].endswith("nothing suggestive."), sub[-1]["body"]["prompt"][:120])

    st, a = call(sky0, "POST", "/v1/scenes", SOUND)
    ok("skybox: audio-only kind_wanted is a 400 {error} with no asset",
       st == 400 and "error" in a and "audio" in a["error"] and not ({"b64", "url", "job"} & set(a)), json.dumps(a))
    st, a = call(sky0, "POST", "/v1/scenes", envelope(kind_wanted=["image"]))
    ok("skybox: a still alone is a cannot too", st == 400 and "error" in a)

    nokey, _ = start("skybox.py", "--upstream", up, name="skybox-nokey")
    st, a = call(nokey, "POST", "/v1/scenes", envelope())
    ok("skybox: without its env key it says so and calls nobody", st == 500 and "SKYBOX_API_KEY" in a.get("error", ""))

    dry, drylog = start("skybox.py", "--upstream", up, "--dry-run", env_key="SKYBOX_API_KEY", name="skybox-dry")
    before = len(seen(fake))
    st, a = call(dry, "POST", "/v1/scenes", envelope())
    shape("skybox --dry-run", st, a, "pano", "blockadelabs-skybox")
    time.sleep(0.2)
    log = open(drylog).read()
    ok("skybox --dry-run: printed the upstream request, key redacted, upstream not called",
       "/api/v1/skybox" in log and '"x-api-key": "<redacted>"' in log and len(seen(fake)) == before,
       log[-300:])

    # ------------------------------------------------------------------
    print("\n# marble")
    mar, _ = start("marble.py", "--upstream", up, "--wait", "0", env_key="WLT_API_KEY", name="marble")
    st, a = call(mar, "POST", "/v1/scenes", envelope())
    ok("marble, --wait 0: 202 pending", st == 202 and a.get("job", "").startswith("pano."), json.dumps(a))
    job = a.get("job", "x")
    st, a = call(mar, "GET", "/v1/scenes/" + job)
    ok("marble: first poll pending", st == 202 and a.get("job") == job)
    st, a = call(mar, "GET", "/v1/scenes/" + job)
    shape("marble: pano by default", st, a, "pano", "worldlabs-marble")
    ex = {x.get("role"): x for x in a.get("extras", [])}
    ok("marble: collider glb and splat ride as extras",
       ex.get("collider", {}).get("url", "").endswith(".glb") and ex.get("splat", {}).get("url", "").endswith(".spz"))
    gen = [r for r in seen(fake) if r["path"] == "/marble/v1/worlds:generate"]
    ok("marble: text world_prompt, draft model, seed, key in WLT-Api-Key",
       gen[-1]["key_ok"] and gen[-1]["body"]["world_prompt"]["type"] == "text"
       and gen[-1]["body"]["model"] == "marble-1.0-draft" and gen[-1]["body"].get("seed") == 428416240)

    st, a = call(mar, "POST", "/v1/scenes", envelope(kind_wanted=["splat", "pano"], reference_image=REF,
                                                     reference_fingerprint="f0"))
    job = a.get("job", "x")
    ok("marble: splat first is a splat job", st == 202 and job.startswith("splat."), json.dumps(a))
    call(mar, "GET", "/v1/scenes/" + job)
    st, a = call(mar, "GET", "/v1/scenes/" + job)
    ok("marble: kind splat, the .spz as a url", st == 200 and a.get("kind") == "splat"
       and a.get("url", "").endswith(".spz") and "b64" not in a, json.dumps(a)[:200])
    gen = [r for r in seen(fake) if r["path"] == "/marble/v1/worlds:generate"]
    ok("marble: the reference became an image world_prompt, and meta says so",
       gen[-1]["body"]["world_prompt"]["type"] == "image" and a.get("meta", {}).get("reference_used") is True)
    st, a = call(mar, "POST", "/v1/scenes", envelope(layout=LAYOUT))
    gen = [r for r in seen(fake) if r["path"] == "/marble/v1/worlds:generate"]
    tp = gen[-1]["body"]["world_prompt"].get("text_prompt", "")
    ok("marble: the layout's labels ride in the text prompt, nearest first, with bearings",
       st == 202 and tp.index("Dot Vance at 239 deg") < tp.index("pie case at 40 deg") < tp.index("the counter at 0 deg")
       < tp.index("inside the Bluebird") and tp.endswith("nothing suggestive.") and "you at" not in tp, tp[:200])
    pano = {"mime": "image/png", "b64": base64.b64encode(load("worldmodel").png_encode(8, 4, bytearray(96))).decode()}
    marp, _ = start("marble.py", "--upstream", up, "--wait", "0", "--pano-input", env_key="WLT_API_KEY", name="marble-pano")
    call(marp, "POST", "/v1/scenes", envelope(reference_image=pano, reference_fingerprint="f1", layout=LAYOUT))
    wp = [r for r in seen(fake) if r["path"] == "/marble/v1/worlds:generate"][-1]["body"]["world_prompt"]
    ok("marble --pano-input: a 2:1 reference goes as a panorama prompt, the layout still in its text",
       wp.get("type") == "panorama" and "panorama_prompt" in wp and "pie case" in wp.get("text_prompt", ""), json.dumps(wp)[:200])
    call(marp, "POST", "/v1/scenes", envelope(reference_image=REF, reference_fingerprint="f1"))
    wp = [r for r in seen(fake) if r["path"] == "/marble/v1/worlds:generate"][-1]["body"]["world_prompt"]
    ok("marble --pano-input: a reference that is not 2:1 is still an image prompt", wp.get("type") == "image")
    call(mar, "POST", "/v1/scenes", envelope(reference_image=pano, reference_fingerprint="f1"))
    wp = [r for r in seen(fake) if r["path"] == "/marble/v1/worlds:generate"][-1]["body"]["world_prompt"]
    ok("marble without --pano-input: a pano reference is an image prompt", wp.get("type") == "image")

    st, a = call(mar, "POST", "/v1/scenes", SOUND)
    ok("marble: audio-only is a 400 cannot", st == 400 and "error" in a and not ({"b64", "url", "job"} & set(a)))

    # ------------------------------------------------------------------
    print("\n# images")
    img, imglog = start("images.py", "--upstream", up, "--pano", "--pano-words", "equirect 360 lora",
                        "--edits", name="images")
    st, a = call(img, "POST", "/v1/scenes", envelope())
    shape("images --pano: synchronous pano", st, a, "pano", "openai-images")
    g = [r for r in seen(fake) if r["path"] == "/v1/images/generations"][-1]["body"]
    ok("images: pano words prepended, 2:1 size, seed passed, b64_json asked",
       g["prompt"].startswith("equirect 360 lora, ") and g["size"] == "2048x1024"
       and g["seed"] == 428416240 and g["response_format"] == "b64_json", json.dumps(g))
    st, a = call(img, "POST", "/v1/scenes", envelope(kind_wanted=["image"], reference_image=REF))
    shape("images --edits: still with a reference", st, a, "image", "openai-images")
    e = [r for r in seen(fake) if r["path"] == "/v1/images/edits"]
    ok("images --edits: the reference went as a multipart file", e and e[-1]["body"]["has_file"]
       and a["meta"]["reference_used"] is True)
    st, a = call(img, "POST", "/v1/scenes", SOUND)
    ok("images: audio-only is a 400 cannot", st == 400 and "error" in a and not ({"b64", "url", "job"} & set(a)))

    imc, _ = start("images.py", "--upstream", up, "--edits", "--control", name="images-control")
    st, a = call(imc, "POST", "/v1/scenes", envelope(kind_wanted=["image"], reference_image=REF, layout=LAYOUT,
                                                     layout_depth=DEPTH, layout_seg=SEG))
    e = [r for r in seen(fake) if r["path"] == "/v1/images/edits"][-1]["body"]
    ok("images --control: the reference as `image`, then layout_depth as a second file `control`",
       st == 200 and [(f["field"], f["filename"]) for f in e["files"]]
       == [("image", "reference.png"), ("control", "layout-depth.png")] and all(f["png"] for f in e["files"])
       and a["meta"].get("control_used") == "depth" and a["meta"].get("reference_used") is True, json.dumps(e))
    ims, _ = start("images.py", "--upstream", up, "--control=seg", "--control-field", "control_image",
                   name="images-seg")
    st, a = call(ims, "POST", "/v1/scenes", envelope(kind_wanted=["image"], layout_depth=DEPTH, layout_seg=SEG))
    e = [r for r in seen(fake) if r["path"] == "/v1/images/edits"][-1]["body"]
    ok("images --control=seg --control-field control_image: layout_seg alone, in the named field",
       st == 200 and [(f["field"], f["filename"]) for f in e["files"]] == [("control_image", "layout-seg.png")]
       and a["meta"].get("control_used") == "seg", json.dumps(e))
    n = len([r for r in seen(fake) if r["path"] == "/v1/images/edits"])
    st, a = call(ims, "POST", "/v1/scenes", envelope(kind_wanted=["image"]))
    ok("images --control with no map in the envelope: a plain generation",
       st == 200 and len([r for r in seen(fake) if r["path"] == "/v1/images/edits"]) == n
       and a["meta"].get("control_used") is False)

    plain, _ = start("images.py", "--upstream", up, name="images-plain")
    st, a = call(plain, "POST", "/v1/scenes", envelope())
    ok("images without --pano: kind_wanted [pano, image] falls to image", st == 200 and a.get("kind") == "image")
    st, a = call(plain, "POST", "/v1/scenes", envelope(kind_wanted=["pano"]))
    ok("images without --pano: pano alone is a cannot that says why", st == 400 and "--pano" in a.get("error", ""))

    # ------------------------------------------------------------------
    print("\n# worldmodel")
    wm = load("worldmodel")
    W, H = 320, 180

    def frame(port, sid):
        ct, raw = get_bytes(port, "/v1/scenes/%s/frame" % sid)
        return ct, raw, (wm.png_decode(raw) if raw[:4] == b"\x89PNG" else None)

    def live(port, sid):
        return call(port, "GET", "/v1/scenes/" + sid)[1].get("meta", {}).get("live", {})

    def wait_for(port, sid, cond, timeout=10):
        end = time.time() + timeout
        while time.time() < end:
            lv = live(port, sid)
            if cond(lv):
                return lv
            time.sleep(0.05)
        return live(port, sid)

    def mean(rgb, ch):
        return sum(rgb[ch::3]) / (len(rgb) / 3)

    def column(rgb, c):
        return bytes(rgb[(r * W + c) * 3 + i] for r in range(H) for i in range(3))

    def changed_at(a, b, bearing):
        c = wm.bearing_to_column(bearing, W)
        return any(column(a, (c + d) % W) != column(b, (c + d) % W) for d in (-1, 0, 1))

    wmp, wmlog = start("worldmodel.py", "--fps", "10", name="worldmodel")
    st, a = call(wmp, "GET", "/")
    ok("worldmodel: the up probe says it makes stream", st == 200 and a.get("makes") == ["stream"])
    st, a = call(wmp, "POST", "/v1/scenes", envelope())
    ok("worldmodel: kind_wanted without stream is a 400 cannot", st == 400 and "stream" in a.get("error", "")
       and not ({"b64", "url", "session"} & set(a)), json.dumps(a))
    st, a = call(wmp, "POST", "/v1/scenes", SOUND)
    ok("worldmodel: a sound is a 400 cannot", st == 400)
    st, a = call(wmp, "POST", "/v1/scenes", envelope(kind_wanted=["stream"], protocols=["hls"]))
    ok("worldmodel: a page that plays no mjpeg is a 400 cannot", st == 400 and "mjpeg" in a.get("error", ""))
    brief = {"key": "bluebird", "name": "the Bluebird Diner", "light": "morning light", "weather": "Bright and sharp",
             "who": [{"handle": "theo", "name": "Theo Marsh", "bearing": 100}]}
    t0 = time.time()
    st, a = call(wmp, "POST", "/v1/scenes", envelope(channel="stream", kind_wanted=["stream"], layout=LAYOUT, brief=brief,
                                                     protocols=["hls", "webrtc-whep", "mjpeg"], max_seconds=600,
                                                     events=["weather", "arrival", "departure", "light"]))
    ses = a.get("session", {})
    sid = ses.get("id", "x")
    ok("worldmodel: kind stream, a session with an id, an mjpeg url on the machine's own origin, expires_at, max_seconds",
       st == 200 and a.get("kind") == "stream" and ses.get("protocol") == "mjpeg"
       and ses.get("url") == "http://127.0.0.1:%d/s/%s.mjpeg" % (wmp, sid) and ses.get("max_seconds") == 600
       and abs(ses.get("expires_at", 0) - (t0 + 600)) < 5 and not ({"b64", "url"} & set(a)), json.dumps(a))
    m = a.get("meta", {})
    ok("worldmodel: meta names vendor (the blockout), vendor_id, seed_used, elapsed_s, layout_used",
       m.get("vendor") == "xeric-blockout" and m.get("vendor_id") == sid and m.get("seed_used") == 428416240
       and "elapsed_s" in m and m.get("layout_used") is True, json.dumps(m))
    ct, parts, _ = mjpeg(ses.get("url", "http://127.0.0.1:1/"), want=3)
    ok("worldmodel: the stream is multipart/x-mixed-replace and yields 3 parts",
       ct.startswith("multipart/x-mixed-replace") and len(parts) == 3, ct)
    mk = [jpeg_markers(p) for p in parts]
    ok("worldmodel: every part is a baseline JPEG (SOI, DQT, SOF0, DHT x4, SOS ... EOI)",
       all(x and 0xDB in x and 0xC0 in x and x.count(0xC4) == 4 and x[-1] == 0xDA for x in mk), str(mk))

    ct, raw, (fw, fh, f0) = frame(wmp, sid)
    ok("worldmodel: the latest frame, lossless, is a %dx%d PNG of the blockout" % (W, H),
       ct == "image/png" and (fw, fh) == (W, H) and len(set(f0)) > 6)
    v = live(wmp, sid).get("version", 0)
    st, e = call(wmp, "POST", "/v1/scenes/%s/events" % sid, {"type": "arrival", "text": "a stranger comes in out of the cold",
                                                              "at": 1793621700})
    ok("worldmodel: an unknown arrival comes in at the door, behind you (180)",
       st == 200 and e.get("bearing") == 180 and e.get("matched") is None, json.dumps(e))
    wait_for(wmp, sid, lambda lv: lv.get("version", 0) > v)
    _, _, (_, _, f1) = frame(wmp, sid)
    ok("worldmodel: the arrival is a new box: the frame changed at 180's column, not at 90's",
       changed_at(f0, f1, 180) and not changed_at(f0, f1, 90))
    v = live(wmp, sid).get("version", 0)
    st, e = call(wmp, "POST", "/v1/scenes/%s/events" % sid, {"type": "arrival", "text": "Theo comes in, late for school",
                                                              "at": 1793621760})
    ok("worldmodel: a named arrival is matched to the person and their bearing", st == 200 and e.get("bearing") == 100
       and e.get("matched") == "Theo Marsh", json.dumps(e))
    wait_for(wmp, sid, lambda lv: lv.get("version", 0) > v)
    _, _, (_, _, f2) = frame(wmp, sid)
    ok("worldmodel: ...and drawn there", changed_at(f1, f2, 100) and not changed_at(f1, f2, 180))
    v = live(wmp, sid).get("version", 0)
    st, e = call(wmp, "POST", "/v1/scenes/%s/events" % sid, {"type": "departure", "text": "Theo heads off to school"})
    wait_for(wmp, sid, lambda lv: lv.get("version", 0) > v)
    _, _, (_, _, f2b) = frame(wmp, sid)
    ok("worldmodel: a departure takes that figure away again", st == 200 and e.get("removed") == "theo"
       and column(f2b, wm.bearing_to_column(100, W)) == column(f1, wm.bearing_to_column(100, W)), json.dumps(e))
    st, e = call(wmp, "POST", "/v1/scenes/%s/events" % sid, {"type": "arrival", "text": "Dot is back from the walk-in"})
    f2 = f2b
    ok("worldmodel: somebody already drawn is not drawn twice", st == 200 and e.get("already_here") is True)
    v = live(wmp, sid).get("version", 0)
    st, e = call(wmp, "POST", "/v1/scenes/%s/events" % sid, {"type": "weather", "text": "rain, coming down hard", "at": 1})
    wait_for(wmp, sid, lambda lv: lv.get("version", 0) > v)
    _, _, (_, _, f3) = frame(wmp, sid)
    ok("worldmodel: a weather event tints the frame (rain: bluer)",
       st == 200 and mean(f3, 2) / mean(f3, 0) > mean(f2, 2) / mean(f2, 0) + 0.05,
       "%.3f -> %.3f" % (mean(f2, 2) / mean(f2, 0), mean(f3, 2) / mean(f3, 0)))
    v = live(wmp, sid).get("version", 0)
    st, e = call(wmp, "POST", "/v1/scenes/%s/events" % sid, {"type": "light", "text": "the lights go out", "at": 2})
    wait_for(wmp, sid, lambda lv: lv.get("version", 0) > v)
    _, _, (_, _, f4) = frame(wmp, sid)
    ok("worldmodel: a light event dims it", st == 200 and sum(mean(f4, i) for i in range(3)) < 0.5 * sum(mean(f3, i) for i in range(3)))
    st, e = call(wmp, "POST", "/v1/scenes/%s/events" % sid, {"type": "wish", "text": "make it a castle"})
    ok("worldmodel: an event that is not weather, light or arrival is a 400", st == 400)
    st, e = call(wmp, "POST", "/v1/scenes/nosuch/events", {"type": "weather", "text": "rain"})
    ok("worldmodel: events for no session are a 404", st == 404)

    # a session the envelope asks to be short: it ends at max_seconds
    t0 = time.time()
    st, a = call(wmp, "POST", "/v1/scenes", envelope(kind_wanted=["stream"], layout=LAYOUT, max_seconds=2))
    short_ = a.get("session", {})
    ok("worldmodel: the envelope may ask for less than --max-seconds", short_.get("max_seconds") == 2)
    _, parts, closed = mjpeg(short_.get("url", "http://127.0.0.1:1/"))
    took = time.time() - t0
    ok("worldmodel: the session ends at max_seconds: the stream closes cleanly",
       closed and len(parts) >= 3 and 1.5 < took < 6, "%.1fs, %d parts, closed=%s" % (took, len(parts), closed))
    st, _ = call(wmp, "GET", "/v1/scenes/" + short_.get("id", "x"))
    st2, _ = call(wmp, "POST", "/v1/scenes/%s/events" % short_.get("id", "x"), {"type": "weather", "text": "rain"})
    ok("worldmodel: an ended session is 410, for a look and for an event", st == 410 and st2 == 410, "%d %d" % (st, st2))
    st, a = call(wmp, "POST", "/v1/scenes", envelope(kind_wanted=["stream"], layout=LAYOUT))
    st2, _ = call(wmp, "POST", "/v1/scenes", envelope(kind_wanted=["stream"], layout=LAYOUT))
    ok("worldmodel: past --max-sessions it says so (503)", st == 200 and st2 == 503, "%d %d" % (st, st2))
    st, _ = call(wmp, "DELETE", "/v1/scenes/" + a.get("session", {}).get("id", "x"))
    ok("worldmodel: DELETE ends a session", st == 200)

    # the model's half: frames through --upstream-frames
    wup = free_port()
    wmu, _ = start("worldmodel.py", "--stream-port", str(wup), "--fps", "10", "--upstream-frames", up + "/v1/frames",
                   name="worldmodel-frames")
    st, a = call(wmu, "POST", "/v1/scenes", envelope(kind_wanted=["stream"], layout=LAYOUT, reference_image=REF,
                                                     layout_depth=DEPTH))
    usid = a.get("session", {}).get("id", "x")
    ok("worldmodel --upstream-frames --stream-port: vendor local-frames, the reference used, the url on the stream port",
       st == 200 and a.get("meta", {}).get("vendor") == "local-frames" and a["meta"].get("reference_used") is True
       and a.get("session", {}).get("url", "").startswith("http://127.0.0.1:%d/s/" % wup), json.dumps(a)[:300])
    lv = wait_for(wmu, usid, lambda lv: lv.get("frames", 0) >= 3)
    ct, raw, dec = frame(wmu, usid)
    border = dec and all(dec[2][(y * dec[0] + x) * 3:(y * dec[0] + x) * 3 + 3] == b"\xff\x00\xff"
                         for x, y in ((0, 0), (W - 1, 0), (0, H - 1), (W - 1, H - 1), (W // 2, 0), (0, H // 2)))
    inner = dec and dec[2][((H // 2) * W + W // 2) * 3:((H // 2) * W + W // 2) * 3 + 3] != b"\xff\x00\xff"
    ok("worldmodel --upstream-frames: the frame went through the model (its border is on it)",
       lv.get("source") == "upstream" and border and inner, json.dumps(lv))
    fr = [r["body"] for r in seen(fake) if r["path"] == "/v1/frames"]
    ok("worldmodel --upstream-frames: each proxy frame went with caption, a first frame, then memory, and the depth map",
       len(fr) >= 3 and fr[0]["frame"] == [W, H] and all(b["caption"] and b["first_frame"] and b["layout_depth"] for b in fr)
       and fr[0]["memory"] == 0 and fr[-1]["memory"] >= 1, json.dumps(fr[:3]))
    _, parts, _ = mjpeg(a.get("session", {}).get("url", "http://127.0.0.1:1/"), want=3)
    ok("worldmodel --upstream-frames: the stream is still JPEG parts", len(parts) == 3
       and all(jpeg_markers(p) for p in parts))

    st, _ = call(wmu, "DELETE", "/v1/scenes/" + usid)
    time.sleep(0.3)   # let a frame already on its way to the fake land
    wmd, wmdlog = start("worldmodel.py", "--dry-run", "--upstream-frames",
                        up + "/v1/frames", name="worldmodel-dry")
    n = len(seen(fake))
    st, a = call(wmd, "POST", "/v1/scenes", envelope(kind_wanted=["stream"], layout=LAYOUT))
    time.sleep(0.5)
    log = open(wmdlog).read()
    ok("worldmodel --dry-run: prints the first frame's stats, calls no upstream",
       st == 200 and '"dry_run": "worldmodel"' in log and '"mean_rgb"' in log and '"boxes": 3' in log
       and len(seen(fake)) == n + 0, log[-400:])

    # ------------------------------------------------------------------
    print("\n# loopback only")
    mods = {n: load(n) for n in ("skybox", "marble", "images", "worldmodel")}
    for n, m in mods.items():
        ca = m.client_allowed
        ok(n + ": client_allowed takes loopback, v6 loopback and mapped loopback, refuses the rest",
           ca("127.0.0.1", False) and ca("::1", False) and ca("::ffff:127.0.0.1", False)
           and not ca("192.168.1.5", False) and not ca("10.0.0.2", False) and not ca("junk", False)
           and ca("192.168.1.5", True))
    lan = None
    try:
        s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        s.connect(("10.255.255.255", 1))
        lan = s.getsockname()[0]
        s.close()
    except OSError:
        pass
    if lan and not lan.startswith("127."):
        wide, _ = start("images.py", "--upstream", up, "--host", "0.0.0.0", "--dry-run", name="images-wide")
        st, a = call(wide, "POST", "/v1/scenes", envelope(), host=lan)
        ok("a client from %s is refused with 403" % lan, st == 403 and "loopback" in a.get("error", ""), json.dumps(a))
        st, _ = call(wide, "GET", "/", host=lan)
        ok("...even for the up probe", st == 403)
        wwp = free_port()
        wwide, _ = start("worldmodel.py", "--host", "0.0.0.0", "--stream-port", str(wwp), name="worldmodel-wide")
        st, a = call(wwide, "POST", "/v1/scenes", envelope(kind_wanted=["stream"], layout=LAYOUT), host=lan)
        ok("worldmodel: a client from %s is refused with 403" % lan, st == 403 and "loopback" in a.get("error", ""))
        st, _ = call(wwide, "POST", "/v1/scenes", envelope(kind_wanted=["stream"], layout=LAYOUT))
        try:
            urllib.request.urlopen("http://%s:%d/s/0.mjpeg" % (lan, wwp), timeout=10)
            st = 200
        except urllib.error.HTTPError as e:
            st = e.code
        ok("worldmodel: ...and on the stream port", st == 403, str(st))
        open_, _ = start("images.py", "--upstream", up, "--host", "0.0.0.0", "--dry-run", "--allow-remote",
                         name="images-open")
        st, a = call(open_, "POST", "/v1/scenes", envelope(), host=lan)
        ok("--allow-remote lifts it", st == 200 and is_png(a.get("b64", "")))
    else:
        print("skip - no non-loopback address to test a remote client from")

    # ------------------------------------------------------------------
    print("\n# no secrets in the logs")
    time.sleep(0.3)
    logs = {f: open(os.path.join(LOGDIR, f)).read() for f in os.listdir(LOGDIR)}
    ok("the key appears in no proxy's stderr", all(KEY not in v for v in logs.values()),
       ", ".join(f for f, v in logs.items() if KEY in v))
    ok("outside --dry-run, the prompt appears in no proxy's log",
       all("Bluebird Diner, morning" not in v for v in logs.values() if "DRY-RUN" not in v),
       ", ".join(f for f, v in logs.items() if "DRY-RUN" not in v and "Bluebird Diner, morning" in v))
    lines = [l for l in logs.get("images.log", "").splitlines() if " POST /v1/scenes " in l]
    ok("one log line per request", len(lines) == 3, str(len(lines)))


try:
    main()
finally:
    for p in PROCS:
        p.terminate()
    for p in PROCS:
        try:
            p.wait(timeout=5)
        except subprocess.TimeoutExpired:
            p.kill()

if FAILED == 0:
    shutil.rmtree(LOGDIR, ignore_errors=True)
else:
    print("logs kept in " + LOGDIR)
print("\n%s" % ("all passed" if FAILED == 0 else "%d FAILED" % FAILED))
sys.exit(1 if FAILED else 0)
