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
SOUND = {"channel": "sound", "kind_wanted": ["audio"], "prompt": "the sound of the Bluebird Diner",
         "seed": 1375495960, "fingerprint": "sound:abc", "quality": "full", "timeout_s": 300}


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

    plain, _ = start("images.py", "--upstream", up, name="images-plain")
    st, a = call(plain, "POST", "/v1/scenes", envelope())
    ok("images without --pano: kind_wanted [pano, image] falls to image", st == 200 and a.get("kind") == "image")
    st, a = call(plain, "POST", "/v1/scenes", envelope(kind_wanted=["pano"]))
    ok("images without --pano: pano alone is a cannot that says why", st == 400 and "--pano" in a.get("error", ""))

    # ------------------------------------------------------------------
    print("\n# loopback only")
    mods = {}
    for n in ("skybox", "marble", "images"):
        spec = importlib.util.spec_from_file_location("proxy_" + n, os.path.join(HERE, n + ".py"))
        mods[n] = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(mods[n])
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
