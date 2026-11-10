#!/usr/bin/env python3
"""
Xeric reference proxy — a LOCAL WORLD MODEL in the shape the plan bets on:
the engine holds the world, the model draws it. A `stream` session behind
Xeric's generic scene envelope.

    python3 tools/proxies/worldmodel.py --port 9100
    python3 tools/proxies/worldmodel.py --port 9100 \
        --upstream-frames http://127.0.0.1:7860/v1/frames

    XERIC_SCENE_BASE=http://127.0.0.1:9100 XERIC_SCENE_KIND=generic
    (and media.scene.stream.enabled: true in the template)

A SCAFFOLD, NOT A MODEL. Matrix-Game 4.0 keeps the world in a game engine and
has a video model re-render the engine's low-poly frames, each conditioned on
that proxy frame, a caption, the first frame and a few memory frames. Xeric is
already the engine: it knows where the pie case is, who just came in, what the
weather did. So this file is the engine's half of that loop and a socket for
the model's half:

  1. The envelope's `layout` (the room's blockout — labelled boxes at bearings
     and distances from the vantage) is rasterised by a tiny software renderer
     in pure Python: flat-shaded boxes in a room, 360 degrees around you on a
     cylinder, at the brief's light. That is the PROXY FRAME (320x180).
  2. Each proxy frame goes to --upstream-frames, a local video model that turns
     it into a finished one (`POST {frame, caption, first_frame, memory, ...}`
     -> `{frame}` — a shape invented here, since no such server is standard
     yet; see send_frame). No upstream: the blockout frames themselves are the
     stream, so the whole path runs with no GPU at all.
  3. The frames are served as MJPEG (multipart/x-mixed-replace, a baseline JPEG
     encoder below — no numpy, no PIL) at /s/{session}.mjpeg, which the page
     can show in a plain <img>. On the API's own port by default, because
     Xeric plays a session only from the machine's own origin (scheme, host
     and port of XERIC_SCENE_BASE); --stream-port gives it a listener of its
     own for any other client.
  4. While it is live, Xeric POSTs its own events to
     /v1/scenes/{session}/events — weather (a tint), light (brightness), an
     arrival (a new figure at the bearing of the person named, else at the
     door), a departure (that figure gone). The texts are the engine's, never
     the player's wishes. DELETE /v1/scenes/{session} ends it early.

WHERE THE REAL MODEL PLUGS IN: send_frame(). Point --upstream-frames at a
server that answers that shape (a thin wrapper around Matrix-Game, Waypoint or
whatever runs on your card), or replace send_frame with an in-process call.
Everything else — the session, the events, the stream, the guard — stays.

LOOPBACK ONLY, BY DEFAULT, on both ports. A GPU is a resource too. Clients
that are not 127.0.0.0/8 or ::1 are refused unless --allow-remote.

NO KEY. It is a machine on this desk. Nothing is read from the request but the
envelope; the prompt and event texts are never logged.

ANSWERS ONLY `stream`. kind_wanted without 'stream' (a pano, a still, a sound)
is a 400 {error} with no asset — the engine's plain "cannot". A blockout is not
a picture of the room and is never offered as one.

    {"kind": "stream",
     "session": {"id", "url", "protocol": "mjpeg", "expires_at", "max_seconds"},
     "meta": {"vendor", "vendor_id", "seed_used", "elapsed_s", ...}}

`expires_at` is epoch seconds. The session ends at max_seconds (--max-seconds,
or less if the envelope asks): the stream closes, later events get 410.

--dry-run prints the first frame's stats and calls no upstream.

Standard library only. Python 3.9+.
"""

import argparse, base64, ipaddress, json, math, re, struct, sys, threading, time, uuid, zlib
import urllib.error, urllib.parse, urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

VENDOR = "xeric-blockout"          # meta.vendor with no upstream; "local-frames" with one
ARGS = None
SESSIONS, ENDED, LOCK = {}, {}, threading.Lock()
BOUNDARY = "xericframe"


# --------------------------------------------------------------------------
# The layout -> a scene the renderer can draw. The frame is engine/layout.php's:
# metres; x to the right, z ahead, y up; a bearing clockwise from straight
# ahead; room.x0..x1, z0..z1 the walls (else width/depth centred on the
# origin); vantage {x, y (eye height), z, yaw}; each object a box at x,z
# (centre), standing on y, w x d x h. Objects with no x,z are placed by bearing
# and distance from the vantage. The `player` object is you, and the camera is
# you, so it is never drawn.
# --------------------------------------------------------------------------

KIND_RGB = {
    "person": (205, 140, 105), "figure": (205, 140, 105), "counter": (150, 105, 70),
    "table": (150, 105, 70), "furniture": (150, 105, 70), "booth": (140, 55, 50),
    "seat": (140, 55, 50), "stool": (140, 55, 50), "case": (185, 190, 195),
    "appliance": (185, 190, 195), "door": (110, 80, 55), "window": (170, 200, 220),
    "plant": (70, 130, 70), "shelf": (125, 95, 70),
}
PERSON = (0.5, 0.45, 1.7)


def num(v):
    return isinstance(v, (int, float)) and not isinstance(v, bool) and math.isfinite(v)


def clamp(v, lo, hi):
    return lo if v < lo else hi if v > hi else v


def object_rgb(kind, label, seed):
    base = KIND_RGB.get(str(kind).lower()) or next((KIND_RGB[w] for w in words(label) if w in KIND_RGB), None)
    h = zlib.crc32(("%s|%s" % (label, seed)).encode())
    if base is None:      # an unknown kind: a muted colour of its own, stable by name
        base = (90 + h % 90, 90 + (h >> 8) % 90, 90 + (h >> 16) % 90)
    j = 0.9 + ((h >> 24) % 21) / 100.0
    return tuple(int(clamp(c * j, 0, 255)) for c in base)


def place(bearing, distance):
    b = math.radians(bearing)
    return distance * math.sin(b), distance * math.cos(b)


def scene_from(envelope):
    """The envelope -> {room, eye, boxes, people, brightness, tint}. No layout: a crude one
    from the brief's bearings (everything 3 m out), so a session still has a room."""
    brief = envelope.get("brief") if isinstance(envelope.get("brief"), dict) else {}
    lay = envelope.get("layout") if isinstance(envelope.get("layout"), dict) else None
    derived = lay is None
    if derived:
        objs = []
        for i, t in enumerate(brief.get("interior") or []):
            if isinstance(t, dict) and num(t.get("bearing")):
                objs.append({"id": "i%d" % i, "label": str(t.get("text", ""))[:60], "kind": "furniture",
                             "bearing": t["bearing"], "distance": 3.0})
        for w in brief.get("who") or []:
            if isinstance(w, dict) and num(w.get("bearing")):
                objs.append({"id": str(w.get("handle", "")), "label": str(w.get("name", "")), "kind": "person",
                             "bearing": w["bearing"], "distance": 2.5})
        lay = {"objects": objs}
    scale = 0.3048 if str(lay.get("units", "m")).lower() in ("ft", "feet") else 1.0
    room = lay.get("room") if isinstance(lay.get("room"), dict) else {}
    m = lambda d, *keys, dflt: next((float(d[k]) * scale for k in keys if num(d.get(k))), dflt)
    rw, rd = clamp(m(room, "width", "w", dflt=8.0), 2, 200), clamp(m(room, "depth", "d", dflt=10.0), 2, 200)
    rh = clamp(m(room, "height", "h", dflt=3.0), 2, 30)
    x0, x1 = m(room, "x0", dflt=-rw / 2), m(room, "x1", dflt=rw / 2)
    z0, z1 = m(room, "z0", dflt=-rd / 2), m(room, "z1", dflt=rd / 2)
    if not (x1 - x0 >= 1 and z1 - z0 >= 1):
        x0, x1, z0, z1 = -rw / 2, rw / 2, -rd / 2, rd / 2
    van = lay.get("vantage") if isinstance(lay.get("vantage"), dict) else {}
    vx = clamp(m(van, "x", dflt=0.0), x0 + 0.1, x1 - 0.1)
    vz = clamp(m(van, "z", dflt=0.0), z0 + 0.1, z1 - 0.1)
    eye = clamp(m(van, "eye", "y", dflt=1.6), 0.3, rh - 0.1)
    yaw = float(van["yaw"]) % 360 if num(van.get("yaw")) else 0.0
    # Everything below is relative to the vantage, in the layout's own axes;
    # the renderer turns by yaw. Bearings stay "from straight ahead".
    sc = {"walls": (x0 - vx, x1 - vx, z0 - vz, z1 - vz), "rh": rh, "eye": eye, "yaw": yaw,
          "boxes": [], "people": [], "derived": derived, "door": None}
    for o in (lay.get("objects") or [])[:64]:
        if not isinstance(o, dict):
            continue
        kind = str(o.get("kind", "") or "")
        label = str(o.get("label", "") or "")
        if kind.lower() in ("player", "you", "camera"):
            continue
        if num(o.get("x")) and num(o.get("z")):
            cx, cz = float(o["x"]) * scale - vx, float(o["z"]) * scale - vz
            bearing, dist = (math.degrees(math.atan2(cx, cz)) - yaw) % 360, math.hypot(cx, cz)
        elif num(o.get("bearing")) and num(o.get("distance")):
            bearing, dist = float(o["bearing"]) % 360, clamp(float(o["distance"]) * scale, 0.3, 200)
            cx, cz = place(bearing + yaw, dist)
        else:
            continue
        person = kind.lower() == "person"
        dw, dd, dh = PERSON if person else (1.0, 1.0, 1.0)
        box = {"id": str(o.get("id", label)), "label": label, "kind": kind, "cx": cx, "cz": cz,
               "y": clamp(float(o["y"]) * scale, 0, rh - 0.05) if num(o.get("y")) else 0.0,
               "w": clamp(float(o["w"]) * scale, 0.05, 50) if num(o.get("w")) else dw,
               "d": clamp(float(o["d"]) * scale, 0.05, 50) if num(o.get("d")) else dd,
               "h": clamp(float(o["h"]) * scale, 0.05, rh) if num(o.get("h")) else dh,
               "rgb": object_rgb(kind, label, o.get("seed"))}
        sc["boxes"].append(box)
        if person:
            sc["people"].append({"id": box["id"], "label": label, "bearing": bearing, "distance": dist})
        if sc["door"] is None and (kind.lower() == "door" or "door" in words(label)):
            sc["door"] = bearing
    for w in brief.get("who") or []:   # people the brief knows, for an arrival to be matched against
        if isinstance(w, dict) and num(w.get("bearing")) and w.get("name"):
            sc["people"].append({"id": str(w.get("handle", "")), "label": str(w["name"]),
                                 "bearing": float(w["bearing"]) % 360, "distance": 2.5})
    phase = brief.get("now", {}).get("phase", "") if isinstance(brief.get("now"), dict) else ""
    sc["brightness"] = brightness_for("%s %s" % (brief.get("light", ""), phase), 0.95)
    if brief.get("dark") is True:
        sc["brightness"] = min(sc["brightness"], 0.35)
    sc["tint"] = tint_for(str(brief.get("weather", "")), (1.0, 1.0, 1.0), strength=0.5)
    return sc


LIGHT_WORDS = [("out", 0.25), ("dark", 0.3), ("night", 0.35), ("candle", 0.45), ("lamp", 0.55),
               ("dusk", 0.6), ("evening", 0.65), ("dim", 0.6), ("dawn", 0.7), ("early", 0.75),
               ("morning", 0.95), ("afternoon", 1.0), ("noon", 1.05), ("midday", 1.05),
               ("bright", 1.1), ("on", 0.95)]
WEATHER_TINTS = [("storm", (0.62, 0.68, 0.85)), ("thunder", (0.62, 0.68, 0.85)), ("rain", (0.8, 0.88, 1.05)),
                 ("drizzle", (0.85, 0.9, 1.03)), ("shower", (0.82, 0.88, 1.04)), ("snow", (0.95, 0.99, 1.1)),
                 ("fog", (0.9, 0.92, 0.94)), ("mist", (0.9, 0.92, 0.95)), ("haze", (0.96, 0.94, 0.9)),
                 ("overcast", (0.88, 0.9, 0.95)), ("cloud", (0.9, 0.92, 0.96)), ("grey", (0.9, 0.91, 0.94)),
                 ("chill", (0.95, 0.98, 1.04)), ("cold", (0.94, 0.97, 1.05)), ("sun", (1.08, 1.0, 0.86)),
                 ("clear", (1.04, 1.0, 0.94)), ("bright", (1.05, 1.0, 0.92)), ("hot", (1.1, 0.98, 0.84)),
                 ("heat", (1.1, 0.98, 0.84))]


def words(text):
    return re.findall(r"[a-z]+", str(text).lower())


def brightness_for(text, default):
    w = words(text)
    for k, v in LIGHT_WORDS:
        if any(x.startswith(k) if len(k) > 3 else x == k for x in w):
            return v
    return default


def tint_for(text, default, strength=1.0):
    w = words(text)
    for k, t in WEATHER_TINTS:
        if any(x.startswith(k) for x in w):
            return tuple(1.0 + (c - 1.0) * strength for c in t)
    return default


# --------------------------------------------------------------------------
# The renderer: the vantage at the origin, a cylinder all the way round.
# Column c looks along bearing (c + 0.5) / W * 360 - 180 (plus the vantage's
# yaw), so straight ahead is the middle of the frame and straight behind is both edges. A row is a slope
# (tan of elevation), so a column is a 2-D problem: the room's wall and each
# box are spans of constant colour, painted far to near.
# --------------------------------------------------------------------------

LIGHT_DIR = (-0.45, 0.75, 0.5)   # a window up and to the left, ahead
FOG = (40, 38, 44)


def bearing_to_column(bearing, w):
    return int(((bearing + 180.0) % 360.0) / 360.0 * w) % w


def ray_box(dx, dz, x0, x1, z0, z1):
    """2-D slab test from the origin -> (t_in, t_out, axis) or None."""
    tmin, tmax, axis = -1e9, 1e9, "x"
    for d, lo, hi, a in ((dx, x0, x1, "x"), (dz, z0, z1, "z")):
        if abs(d) < 1e-9:
            if lo > 0 or hi < 0:
                return None
            continue
        t0, t1 = lo / d, hi / d
        if t0 > t1:
            t0, t1 = t1, t0
        if t0 > tmin:
            tmin, axis = t0, a
        tmax = min(tmax, t1)
    if tmax < tmin or tmax <= 0.05 or tmin <= 0.0:
        return None
    return tmin, tmax, axis


def render(sc, w, h):
    f = w / (2 * math.pi)
    cy = h / 2.0
    eye, rh, yaw = sc["eye"], sc["rh"], sc.get("yaw", 0.0)
    x0, x1, z0, z1 = sc["walls"]
    k, tint = sc["brightness"], sc["tint"]
    L = LIGHT_DIR
    ln = math.sqrt(sum(c * c for c in L))
    L = tuple(c / ln for c in L)

    def shade(rgb, n_dot_l, t):
        lit = (0.45 + 0.55 * max(0.0, n_dot_l)) * k
        fog = min(0.75, t * 0.045)
        return bytes(int(clamp((c * lit * (1 - fog) + fc * fog * k) * tn, 0, 255))
                     for c, fc, tn in zip(rgb, FOG, tint))

    def row_of(slope):   # the row a slope lands on (fractional)
        return cy - slope * f

    def span(col, slope_lo, slope_hi, px):
        r0 = int(math.ceil(row_of(slope_hi) - 0.5))
        r1 = int(math.floor(row_of(slope_lo) - 0.5))
        for r in range(max(0, r0), min(h - 1, r1) + 1):
            o = (r * w + col) * 3
            img[o:o + 3] = px

    img = bytearray(w * h * 3)
    floor_rows, ceil_rows = [], []
    for r in range(h):
        s = (cy - r - 0.5) / f
        floor_rows.append(shade((120, 100, 80), L[1], eye / -s) if s < 0 else None)
        ceil_rows.append(shade((210, 205, 195), -L[1] * 0.2, (rh - eye) / s) if s > 0 else None)
    boxes = [(b, b["cx"] - b["w"] / 2, b["cx"] + b["w"] / 2, b["cz"] - b["d"] / 2, b["cz"] + b["d"] / 2)
             for b in sc["boxes"]]
    for c in range(w):
        az = math.radians(yaw + (c + 0.5) / w * 360.0 - 180.0)
        dx, dz = math.sin(az), math.cos(az)
        # the wall this column ends on, from inside the room
        tx = (x1 / dx) if dx > 1e-9 else (x0 / dx) if dx < -1e-9 else 1e9
        tz = (z1 / dz) if dz > 1e-9 else (z0 / dz) if dz < -1e-9 else 1e9
        tw, n = (tx, (-1 if dx > 0 else 1, 0, 0)) if tx < tz else (tz, (0, 0, -1 if dz > 0 else 1))
        wall = shade((175, 160, 135), n[0] * L[0] + n[2] * L[2], tw)
        lo, hi = -eye / tw, (rh - eye) / tw
        for r in range(h):
            s = (cy - r - 0.5) / f
            o = (r * w + c) * 3
            img[o:o + 3] = floor_rows[r] if s < lo else ceil_rows[r] if s > hi else wall
        hits = []
        for b, bx0, bx1, bz0, bz1 in boxes:
            hit = ray_box(dx, dz, bx0, bx1, bz0, bz1)
            if hit and hit[0] < tw:
                hits.append((hit, b))
        hits.sort(key=lambda hb: -hb[0][0])
        for (tin, tout, axis), b in hits:
            n = (-1 if dx > 0 else 1, 0, 0) if axis == "x" else (0, 0, -1 if dz > 0 else 1)
            by = b.get("y", 0.0)
            bh = min(by + b["h"], rh)
            far = min(tout, tw)
            span(c, (by - eye) / tin, (bh - eye) / tin, shade(b["rgb"], n[0] * L[0] + n[2] * L[2], tin))
            if eye > bh:      # its top, seen from above
                span(c, (bh - eye) / tin, (bh - eye) / far, shade(b["rgb"], L[1], tin))
            elif eye < by:    # its underside, seen from below
                span(c, (by - eye) / far, (by - eye) / tin, shade(b["rgb"], -L[1] * 0.3, tin))
    return img


# --------------------------------------------------------------------------
# Images, in pure Python: PNG both ways (zlib), and a baseline JPEG encoder
# (JFIF, 4:4:4, the Annex K tables), which is what an MJPEG part has to be.
# --------------------------------------------------------------------------

def png_encode(w, h, rgb):
    def chunk(t, d):
        return struct.pack(">I", len(d)) + t + d + struct.pack(">I", zlib.crc32(t + d) & 0xFFFFFFFF)
    rows = b"".join(b"\x00" + bytes(rgb[y * w * 3:(y + 1) * w * 3]) for y in range(h))
    return (b"\x89PNG\r\n\x1a\n" + chunk(b"IHDR", struct.pack(">IIBBBBB", w, h, 8, 2, 0, 0, 0))
            + chunk(b"IDAT", zlib.compress(rows, 6)) + chunk(b"IEND", b""))


def png_decode(raw):
    """An 8-bit, non-interlaced grey/RGB/RGBA PNG -> (w, h, bytearray rgb). ValueError otherwise."""
    if raw[:8] != b"\x89PNG\r\n\x1a\n":
        raise ValueError("not a PNG")
    i, idat, hdr = 8, [], None
    while i + 8 <= len(raw):
        n = struct.unpack(">I", raw[i:i + 4])[0]
        t, d = raw[i + 4:i + 8], raw[i + 8:i + 8 + n]
        if t == b"IHDR":
            hdr = struct.unpack(">IIBBBBB", d[:13])
        elif t == b"IDAT":
            idat.append(d)
        elif t == b"IEND":
            break
        i += 12 + n
    if not hdr:
        raise ValueError("no IHDR")
    w, h, depth, ctype, _, _, interlace = hdr
    bpp = {0: 1, 2: 3, 4: 2, 6: 4}.get(ctype)
    if depth != 8 or bpp is None or interlace or not (0 < w <= 8192 and 0 < h <= 8192):
        raise ValueError("unsupported PNG")
    try:
        data = zlib.decompress(b"".join(idat))
    except zlib.error:
        raise ValueError("bad PNG data")
    stride = w * bpp
    if len(data) < h * (stride + 1):
        raise ValueError("short PNG data")
    out, prev = bytearray(), bytearray(stride)
    for y in range(h):
        ft, line = data[y * (stride + 1)], bytearray(data[y * (stride + 1) + 1:(y + 1) * (stride + 1)])
        if ft == 1:
            for x in range(bpp, stride):
                line[x] = (line[x] + line[x - bpp]) & 255
        elif ft == 2:
            for x in range(stride):
                line[x] = (line[x] + prev[x]) & 255
        elif ft == 3:
            for x in range(stride):
                line[x] = (line[x] + ((line[x - bpp] if x >= bpp else 0) + prev[x]) // 2) & 255
        elif ft == 4:
            for x in range(stride):
                a = line[x - bpp] if x >= bpp else 0
                b, c = prev[x], (prev[x - bpp] if x >= bpp else 0)
                p = a + b - c
                pa, pb, pc = abs(p - a), abs(p - b), abs(p - c)
                line[x] = (line[x] + (a if pa <= pb and pa <= pc else b if pb <= pc else c)) & 255
        elif ft != 0:
            raise ValueError("bad PNG filter")
        prev = line
        if bpp == 3:
            out += line
        else:
            for x in range(w):
                px = line[x * bpp:(x + 1) * bpp]
                out += bytes((px[0], px[0], px[0])) if bpp <= 2 else px[:3]
    return w, h, out


ZIGZAG = [0, 1, 8, 16, 9, 2, 3, 10, 17, 24, 32, 25, 18, 11, 4, 5, 12, 19, 26, 33, 40, 48, 41, 34, 27, 20, 13, 6,
          7, 14, 21, 28, 35, 42, 49, 56, 57, 50, 43, 36, 29, 22, 15, 23, 30, 37, 44, 51, 58, 59, 52, 45, 38, 31,
          39, 46, 53, 60, 61, 54, 47, 55, 62, 63]
Q_LUM = [16, 11, 10, 16, 24, 40, 51, 61, 12, 12, 14, 19, 26, 58, 60, 55, 14, 13, 16, 24, 40, 57, 69, 56,
         14, 17, 22, 29, 51, 87, 80, 62, 18, 22, 37, 56, 68, 109, 103, 77, 24, 35, 55, 64, 81, 104, 113, 92,
         49, 64, 78, 87, 103, 121, 120, 101, 72, 92, 95, 98, 112, 100, 103, 99]
Q_CHR = [17, 18, 24, 47, 99, 99, 99, 99, 18, 21, 26, 66, 99, 99, 99, 99, 24, 26, 56, 99, 99, 99, 99, 99,
         47, 66, 99, 99, 99, 99, 99, 99] + [99] * 32
_AC_VALS_LUM = bytes.fromhex(
    "01020300041105122131410613516107227114328191a1082342b1c11552d1f02433627282090a161718191a25262728292a"
    "3435363738393a434445464748494a535455565758595a636465666768696a737475767778797a838485868788898a9293"
    "9495969798999aa2a3a4a5a6a7a8a9aab2b3b4b5b6b7b8b9bac2c3c4c5c6c7c8c9cad2d3d4d5d6d7d8d9dae1e2e3e4e5e6"
    "e7e8e9eaf1f2f3f4f5f6f7f8f9fa")
_AC_VALS_CHR = bytes.fromhex(
    "000102031104052131061241510761711322328108144291a1b1c109233352f0156272d10a162434e125f11718191a2627"
    "28292a35363738393a434445464748494a535455565758595a636465666768696a737475767778797a82838485868788898a"
    "92939495969798999aa2a3a4a5a6a7a8a9aab2b3b4b5b6b7b8b9bac2c3c4c5c6c7c8c9cad2d3d4d5d6d7d8d9dae2e3e4e5e6"
    "e7e8e9eaf2f3f4f5f6f7f8f9fa")
HUFF = {  # (class<<4 | id) -> (bits[16], values): ITU T.81 Annex K.3
    0x00: ([0, 1, 5, 1, 1, 1, 1, 1, 1, 0, 0, 0, 0, 0, 0, 0], bytes(range(12))),
    0x10: ([0, 2, 1, 3, 3, 2, 4, 3, 5, 5, 4, 4, 0, 0, 1, 0x7D], _AC_VALS_LUM),
    0x01: ([0, 3, 1, 1, 1, 1, 1, 1, 1, 1, 1, 0, 0, 0, 0, 0], bytes(range(12))),
    0x11: ([0, 2, 1, 2, 4, 4, 3, 4, 7, 5, 4, 4, 0, 1, 2, 0x77], _AC_VALS_CHR),
}
_COS = [[(math.sqrt(0.5) if u == 0 else 1.0) * 0.5 * math.cos((2 * x + 1) * u * math.pi / 16) for x in range(8)]
        for u in range(8)]


def _codes(bits, vals):
    """Canonical Huffman codes: value -> (code, length)."""
    out, code, i = {}, 0, 0
    for length in range(1, 17):
        for _ in range(bits[length - 1]):
            out[vals[i]] = (code, length)
            code += 1
            i += 1
        code <<= 1
    return out


_CODES = {k: _codes(*v) for k, v in HUFF.items()}


def _qtable(base, quality):
    q = clamp(int(quality), 1, 100)
    scale = 5000 // q if q < 50 else 200 - 2 * q
    return [int(clamp((b * scale + 50) // 100, 1, 255)) for b in base]


def jpeg_encode(w, h, rgb, quality=80):
    """RGB bytes -> a baseline JFIF JPEG, 4:4:4, standard tables."""
    ql, qc = _qtable(Q_LUM, quality), _qtable(Q_CHR, quality)
    pw, ph = (w + 7) // 8 * 8, (h + 7) // 8 * 8
    Y, Cb, Cr = [0.0] * (pw * ph), [0.0] * (pw * ph), [0.0] * (pw * ph)
    for y in range(ph):
        sy = min(y, h - 1) * w * 3
        for x in range(pw):
            o = sy + min(x, w - 1) * 3
            r, g, b = rgb[o], rgb[o + 1], rgb[o + 2]
            i = y * pw + x
            Y[i] = 0.299 * r + 0.587 * g + 0.114 * b - 128.0
            Cb[i] = -0.168736 * r - 0.331264 * g + 0.5 * b
            Cr[i] = 0.5 * r - 0.418688 * g - 0.081312 * b

    out = bytearray()
    acc, nb = 0, 0

    def put(code, length):
        nonlocal acc, nb
        acc = (acc << length) | code
        nb += length
        while nb >= 8:
            nb -= 8
            byte = (acc >> nb) & 0xFF
            out.append(byte)
            if byte == 0xFF:
                out.append(0)
        acc &= (1 << nb) - 1

    def coded(v):
        size = abs(v).bit_length()
        return size, (v if v >= 0 else v + (1 << size) - 1)

    def block(plane, bx, by, q, dc_tab, ac_tab, pred):
        vals = [plane[(by + yy) * pw + bx + xx] for yy in range(8) for xx in range(8)]
        if vals.count(vals[0]) == 64:      # a flat block: DC alone (most of a blockout)
            coef = [0] * 64
            coef[0] = int(round(vals[0] * 8.0 / q[0]))
        else:
            rows = [vals[i * 8:i * 8 + 8] for i in range(8)]
            tmp = [[sum(cu[x] * row[x] for x in range(8)) for cu in _COS] for row in rows]
            F = [0.0] * 64
            for v in range(8):
                cv = _COS[v]
                for u in range(8):
                    F[v * 8 + u] = sum(cv[yy] * tmp[yy][u] for yy in range(8))
            coef = [int(round(F[ZIGZAG[k]] / q[ZIGZAG[k]])) for k in range(64)]
        diff = coef[0] - pred
        size, bits = coded(diff)
        put(*dc_tab[size])
        if size:
            put(bits, size)
        run = 0
        for k in range(1, 64):
            c = coef[k]
            if c == 0:
                run += 1
                continue
            while run > 15:
                put(*ac_tab[0xF0])
                run -= 16
            size, bits = coded(c)
            put(*ac_tab[(run << 4) | size])
            put(bits, size)
            run = 0
        if run:
            put(*ac_tab[0x00])
        return coef[0]

    pY = pB = pR = 0
    qz_l, qz_c = [ql[ZIGZAG[k]] for k in range(64)], [qc[ZIGZAG[k]] for k in range(64)]
    for by in range(0, ph, 8):
        for bx in range(0, pw, 8):
            pY = block(Y, bx, by, ql, _CODES[0x00], _CODES[0x10], pY)
            pB = block(Cb, bx, by, qc, _CODES[0x01], _CODES[0x11], pB)
            pR = block(Cr, bx, by, qc, _CODES[0x01], _CODES[0x11], pR)
    if nb:
        put((1 << (8 - nb)) - 1, 8 - nb)

    def seg(marker, payload):
        return b"\xff" + bytes([marker]) + struct.pack(">H", len(payload) + 2) + payload

    head = b"\xff\xd8" + seg(0xE0, b"JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00")
    head += seg(0xDB, b"\x00" + bytes(qz_l) + b"\x01" + bytes(qz_c))
    head += seg(0xC0, struct.pack(">BHHB", 8, h, w, 3) + b"\x01\x11\x00" + b"\x02\x11\x01" + b"\x03\x11\x01")
    for k in (0x00, 0x10, 0x01, 0x11):
        bits, vals = HUFF[k]
        head += seg(0xC4, bytes([k]) + bytes(bits) + vals)
    head += seg(0xDA, b"\x03" + b"\x01\x00" + b"\x02\x11" + b"\x03\x11" + b"\x00\x3f\x00")
    return head + bytes(out) + b"\xff\xd9"


# --------------------------------------------------------------------------
# THE MODEL'S HALF. The one function a real local world model replaces.
# --------------------------------------------------------------------------

def send_frame(sess, png):
    """A proxy frame -> a finished frame (raw image bytes, PNG or JPEG), via --upstream-frames.

    The request is the Matrix-Game 4.0 conditioning as Xeric can give it: the
    low-poly proxy frame, a caption (the composed prompt), the first finished
    frame (or the room's reference picture, before there is one), up to
    --memory recent finished frames, and the layout's depth/seg maps when the
    envelope carried them. The shape is this file's invention - nobody serves
    it yet - so verify it against whatever wrapper you put round your model."""
    body = {"session": sess.id, "index": sess.index, "seed": sess.seed,
            "frame": {"mime": "image/png", "b64": base64.b64encode(png).decode()},
            "caption": sess.caption}
    first = sess.first or sess.reference
    if first:
        body["first_frame"] = first
    if sess.memory:
        body["memory"] = list(sess.memory)
    for k in ("layout_depth", "layout_seg"):
        if sess.maps.get(k):
            body[k] = sess.maps[k]
    req = urllib.request.Request(ARGS.upstream_frames, data=json.dumps(body).encode(), method="POST",
                                 headers={"Content-Type": "application/json",
                                          "Accept": "application/json, image/jpeg, image/png"})
    with urllib.request.urlopen(req, timeout=ARGS.frame_timeout) as r:
        raw = r.read(ARGS.max_bytes + 1)
        ctype = r.headers.get("Content-Type", "")
    if len(raw) > ARGS.max_bytes:
        raise ValueError("frame too large")
    if ctype.startswith("image/"):
        return raw
    fr = json.loads(raw).get("frame") or {}
    return base64.b64decode(fr.get("b64", ""), validate=True)


# --------------------------------------------------------------------------
# Sessions.
# --------------------------------------------------------------------------

def log(line):
    sys.stderr.write(time.strftime("%Y-%m-%dT%H:%M:%S ") + "worldmodel " + line + "\n")
    sys.stderr.flush()


def stats(w, h, rgb, sc):
    n = w * h
    mean = [round(sum(rgb[i::3]) / n, 1) for i in range(3)]
    return {"frame": [w, h], "boxes": len(sc["boxes"]), "mean_rgb": mean,
            "brightness": sc["brightness"], "tint": [round(t, 3) for t in sc["tint"]],
            "layout": "derived from brief bearings" if sc["derived"] else "envelope"}


class Session:
    def __init__(self, envelope, max_seconds):
        self.id = uuid.uuid4().hex[:16]
        self.started = time.time()
        self.max_seconds = max_seconds
        self.expires = self.started + max_seconds
        self.scene = scene_from(envelope)
        self.caption = envelope.get("prompt", "")
        self.seed = envelope.get("seed") if isinstance(envelope.get("seed"), int) else None
        ref = envelope.get("reference_image")
        self.reference = ref if isinstance(ref, dict) and ref.get("b64") else None
        self.maps = {k: envelope[k] for k in ("layout_depth", "layout_seg") if isinstance(envelope.get(k), dict)}
        self.first, self.memory = None, []
        self.cond = threading.Condition()
        self.dirty, self.version, self.seq, self.index = True, 0, 0, 0
        self.blockout = None                       # (rgb, png) of the current scene
        self.jpeg = self.still = None              # the latest part, and its lossless form if any
        self.source, self.upstream_errors, self.events = "blockout", 0, 0
        self.ended = False
        self.arrivals = 0

    def answer(self, base_stream):
        return {"kind": "stream",
                "session": {"id": self.id, "url": "%s/s/%s.mjpeg" % (base_stream, self.id),
                            "protocol": "mjpeg", "expires_at": int(self.expires),
                            "max_seconds": int(self.max_seconds)},
                "meta": {"vendor": "local-frames" if ARGS.upstream_frames and not ARGS.dry_run else VENDOR,
                         "vendor_id": self.id, "seed_used": self.seed,
                         "elapsed_s": round(time.time() - self.started, 2),
                         "reference_used": bool(self.reference and ARGS.upstream_frames and not ARGS.dry_run),
                         "layout_used": "derived" if self.scene["derived"] else True,
                         "frame": [ARGS.width, ARGS.height], "fps": ARGS.fps,
                         # Each frame is the whole way round (render: one column a
                         # bearing), so the page lays the tags on it linearly.
                         "fov": 360, "projection": "equirectangular",
                         "events": "/v1/scenes/%s/events" % self.id}}

    def live(self):
        with self.cond:
            return {"frames": self.index, "version": self.version, "source": self.source,
                    "events": self.events, "upstream_errors": self.upstream_errors,
                    "boxes": len(self.scene["boxes"]), "seconds_left": max(0, int(self.expires - time.time()))}

    def tick(self):
        w, h = ARGS.width, ARGS.height
        with self.cond:
            dirty, sc = self.dirty, self.scene
            self.dirty = False
        if dirty or self.blockout is None:
            rgb = render(sc, w, h)
            self.blockout = (rgb, png_encode(w, h, rgb))
            if self.index == 0 and ARGS.dry_run:
                print(json.dumps({"dry_run": "worldmodel", "session": self.id, **stats(w, h, rgb, sc),
                                  "would_post": ARGS.upstream_frames or None}, indent=2), file=sys.stderr)
        rgb, png = self.blockout
        jpeg, still, source = None, None, "blockout"
        if ARGS.upstream_frames and not ARGS.dry_run:
            try:
                raw = send_frame(self, png)
                if raw[:3] == b"\xff\xd8\xff":
                    jpeg, source = raw, "upstream"
                else:
                    fw, fh, frgb = png_decode(raw)
                    jpeg, still, source = jpeg_encode(fw, fh, frgb, ARGS.quality), raw, "upstream"
                item = {"mime": "image/jpeg" if still is None else "image/png",
                        "b64": base64.b64encode(still or jpeg).decode()}
                if self.first is None:
                    self.first = item
                self.memory = (self.memory + [item])[-ARGS.memory:] if ARGS.memory > 0 else []
            except (urllib.error.URLError, OSError, ValueError, KeyError, AttributeError) as e:
                self.upstream_errors += 1
                if self.upstream_errors in (1, 10, 100) or self.upstream_errors % 1000 == 0:
                    log("session %s: upstream frame failed (%s), serving the blockout; %d so far"
                        % (self.id, type(e).__name__, self.upstream_errors))
        if jpeg is None:
            if dirty or self.jpeg is None or self.source != "blockout":
                jpeg = jpeg_encode(w, h, rgb, ARGS.quality)
            else:
                jpeg = self.jpeg
            still = png
        with self.cond:
            self.jpeg, self.still, self.source = jpeg, still, source
            self.index += 1
            self.seq += 1
            if dirty:
                self.version += 1
            self.cond.notify_all()

    def run(self):
        period = 1.0 / max(0.1, ARGS.fps)
        try:
            while time.time() < self.expires and not self.ended:
                t0 = time.time()
                try:
                    self.tick()
                except Exception as e:   # one bad frame must not leave a stream hanging
                    log("session %s: frame failed: %s" % (self.id, type(e).__name__))
                time.sleep(max(0.0, min(period - (time.time() - t0), self.expires - time.time())))
        finally:
            self.end("expired" if time.time() >= self.expires else "ended")

    def end(self, why):
        with self.cond:
            if self.ended and self.id in ENDED:
                return
            self.ended = True
            self.cond.notify_all()
        with LOCK:
            SESSIONS.pop(self.id, None)
            ENDED[self.id] = why
            while len(ENDED) > 256:
                ENDED.pop(next(iter(ENDED)))
        log("session %s %s after %.1fs, %d frames" % (self.id, why, time.time() - self.started, self.index))

    def event(self, ev):
        """Apply one engine event. -> (status, answer)."""
        t, text = ev.get("type"), ev.get("text", "")
        if t not in EVENTS or not isinstance(text, str):
            return 400, {"error": "an event is {type: %s, text, at}" % "|".join(EVENTS)}
        with self.cond:
            sc = self.scene
            out = {"applied": t}
            if t == "weather":
                sc["tint"] = tint_for(text, (0.94, 0.95, 0.97))
                out["tint"] = [round(c, 3) for c in sc["tint"]]
            elif t == "light":
                sc["brightness"] = brightness_for(text, sc["brightness"])
                out["brightness"] = sc["brightness"]
            elif t == "departure":
                drawn = [b for b in sc["boxes"] if b["kind"].lower() == "person"]
                who = match_person(text, [{"id": b["id"], "label": b["label"]} for b in drawn])
                gone = next((b for b in drawn if who and b["id"] == who["id"]), None)
                if gone is None and not who:   # nobody named: the last one who came in goes
                    gone = next((b for b in reversed(drawn) if b["id"].startswith("arrival-")), None)
                if gone is not None:
                    sc["boxes"].remove(gone)
                out["removed"] = gone["id"] if gone else None
            else:
                who = match_person(text, sc["people"])
                if who:
                    bearing, dist, label = who["bearing"], who["distance"], who["label"]
                else:
                    bearing = sc["door"] if sc["door"] is not None else ARGS.door_bearing
                    dist, label = 2.0, "someone"
                if who and any(b["id"] == who["id"] and b["kind"].lower() == "person" for b in sc["boxes"]):
                    out.update(bearing=round(bearing), matched=label, already_here=True)
                else:
                    dist = min(dist, wall_distance(sc, bearing + sc["yaw"]) - 0.4)
                    cx, cz = place(bearing + sc["yaw"], max(0.6, dist))
                    self.arrivals += 1
                    sc["boxes"].append({"id": (who or {}).get("id") or "arrival-%d" % self.arrivals,
                                        "label": label, "kind": "person", "cx": cx, "cz": cz, "y": 0.0,
                                        "w": PERSON[0], "d": PERSON[1], "h": PERSON[2],
                                        "rgb": object_rgb("person", label, self.arrivals)})
                    out.update(bearing=round(bearing), matched=label if who else None, box=sc["boxes"][-1]["id"])
            self.events += 1
            self.dirty = True
        return 200, out


EVENTS = ("weather", "light", "arrival", "departure")


def match_person(text, people):
    w = set(words(text))
    for p in people:
        names = [x for x in words(p["label"]) + words(str(p["id"]).split(":")[-1]) if len(x) >= 3]
        if names and any(n in w for n in names):
            return p
    return None


def wall_distance(sc, bearing):
    x0, x1, z0, z1 = sc["walls"]
    dx, dz = place(bearing, 1.0)
    tx = (x1 / dx) if dx > 1e-9 else (x0 / dx) if dx < -1e-9 else 1e9
    tz = (z1 / dz) if dz > 1e-9 else (z0 / dz) if dz < -1e-9 else 1e9
    return min(tx, tz)


def start_session(envelope, req_host=""):
    wanted = envelope.get("kind_wanted") or []
    if not isinstance(wanted, list):
        wanted = [wanted]
    if "stream" not in wanted:
        return 400, {"error": "this machine makes only stream; asked for %s" % ", ".join(map(str, wanted) or ["nothing"])}
    if isinstance(envelope.get("protocols"), list) and "mjpeg" not in envelope["protocols"]:
        return 400, {"error": "this machine streams only mjpeg; the page plays %s"
                     % (", ".join(map(str, envelope["protocols"])) or "nothing")}
    asked = [v for v in (envelope.get("max_seconds"), (envelope.get("stream") or {}).get("max_seconds")
                         if isinstance(envelope.get("stream"), dict) else None) if num(v) and v > 0]
    max_s = min([ARGS.max_seconds] + asked)
    with LOCK:
        if len(SESSIONS) >= ARGS.max_sessions:
            return 503, {"error": "this machine is drawing %d sessions already (--max-sessions)" % len(SESSIONS)}
        sess = Session(envelope, max_s)
        SESSIONS[sess.id] = sess
    sess.tick()     # the first frame exists before the answer does
    threading.Thread(target=sess.run, daemon=True).start()
    return 200, sess.answer(stream_base(req_host))


def stream_base(req_host=""):
    """Where the session url points. Xeric plays a session only on the machine's
    own origin (the one the page's CSP is widened to), so by default the stream
    is on the API port and the url names the Host the request came to — exactly
    the base Xeric was given. --stream-port moves it to a listener of its own,
    for a client that does not hold that rule."""
    if not ARGS.stream_port and not ARGS.public_host and re.fullmatch(
            r"(?:[A-Za-z0-9](?:[A-Za-z0-9.-]{0,251}[A-Za-z0-9])?|\[[0-9A-Fa-f:.]+\])(?::\d{1,5})?", req_host or ""):
        return "http://" + req_host
    host = ARGS.public_host or (ARGS.host if ARGS.host not in ("0.0.0.0", "::", "") else "127.0.0.1")
    if ":" in host and not host.startswith("["):
        host = "[" + host + "]"
    return "http://%s:%d" % (host, ARGS.stream_port or ARGS.port)


def lookup(sid):
    with LOCK:
        return SESSIONS.get(sid), ENDED.get(sid)


# --------------------------------------------------------------------------
# HTTP: the envelope and (by default) the stream on --port.
# --------------------------------------------------------------------------

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


class Base(BaseHTTPRequestHandler):
    def log_message(self, *a):
        pass

    def answer(self, status, obj, note="", ctype="application/json"):
        data = obj if isinstance(obj, bytes) else json.dumps(obj).encode()
        self.send_response(status)
        self.send_header("Content-Type", ctype)
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)
        log("%s %s %s %d %.2fs%s" % (self.client_address[0], self.command, self.path.split("?")[0][:80],
                                     status, time.time() - self._t, note))

    def guard(self):
        self._t = time.time()
        if client_allowed(self.client_address[0], ARGS.allow_remote):
            return True
        self.answer(403, {"error": "this machine answers loopback clients only (--allow-remote to lift)"})
        return False

    def mjpeg(self, sid):
        """The session as multipart/x-mixed-replace, one JPEG a frame, until it ends."""
        sess, gone = lookup(sid)
        if sess is None:
            return self.answer(410 if gone else 404, {"error": "session %s" % (gone or "unknown")})
        self.send_response(200)
        self.send_header("Content-Type", "multipart/x-mixed-replace; boundary=" + BOUNDARY)
        self.send_header("Cache-Control", "no-store")
        self.send_header("Connection", "close")
        self.end_headers()
        log("%s GET /s/%s.mjpeg 200 stream open" % (self.client_address[0], sess.id))
        seen, parts = -1, 0
        try:
            while True:
                with sess.cond:
                    while sess.seq == seen and not sess.ended:
                        sess.cond.wait(1.0)
                    if sess.ended:
                        break
                    seen, jpeg = sess.seq, sess.jpeg
                self.wfile.write(("--%s\r\nContent-Type: image/jpeg\r\nContent-Length: %d\r\n\r\n"
                                  % (BOUNDARY, len(jpeg))).encode() + jpeg + b"\r\n")
                self.wfile.flush()
                parts += 1
            self.wfile.write(("--%s--\r\n" % BOUNDARY).encode())
            self.wfile.flush()
        except (BrokenPipeError, ConnectionResetError, OSError):
            pass
        log("%s GET /s/%s.mjpeg closed after %d parts" % (self.client_address[0], sess.id, parts))

    def json_body(self):
        try:
            n = int(self.headers.get("Content-Length") or 0)
        except ValueError:
            n = -1
        if n <= 0 or n > ARGS.max_bytes:
            return None
        try:
            j = json.loads(self.rfile.read(n))
        except ValueError:
            return None
        return j if isinstance(j, dict) else None


class Api(Base):
    server_version = "xeric-proxy-worldmodel"

    def do_GET(self):
        if not self.guard():
            return
        path = urllib.parse.urlparse(self.path).path
        if path in ("/", ""):
            return self.answer(200, {"proxy": "worldmodel", "makes": ["stream"], "protocol": "mjpeg",
                                     "upstream_frames": bool(ARGS.upstream_frames), "dry_run": ARGS.dry_run,
                                     "sessions": len(SESSIONS)})
        ms = re.fullmatch(r"/s/([0-9a-f]{1,32})\.mjpeg", path)
        if ms and not ARGS.stream_port:
            return self.mjpeg(ms.group(1))
        m = re.fullmatch(r"/v1/scenes/([0-9a-f]{1,32})(/frame)?", path)
        if not m:
            return self.answer(404, {"error": "not found"})
        sess, gone = lookup(m.group(1))
        if sess is None:
            return self.answer(410 if gone else 404, {"error": "session %s" % (gone or "unknown")})
        if m.group(2):   # the latest frame, lossless when there is a lossless one
            with sess.cond:
                img = sess.still or sess.jpeg
            return self.answer(200, img, ctype="image/png" if img[:4] == b"\x89PNG" else "image/jpeg")
        a = sess.answer(stream_base(self.headers.get("Host", "")))
        a["meta"]["live"] = sess.live()
        self.answer(200, a)

    def do_DELETE(self):
        if not self.guard():
            return
        m = re.fullmatch(r"/v1/scenes/([0-9a-f]{1,32})", urllib.parse.urlparse(self.path).path)
        sess, gone = lookup(m.group(1)) if m else (None, None)
        if sess is None:
            return self.answer(410 if gone else 404, {"error": "session %s" % (gone or "unknown")})
        sess.end("ended")
        self.answer(200, {"ended": sess.id})

    def do_POST(self):
        if not self.guard():
            return
        path = urllib.parse.urlparse(self.path).path
        m = re.fullmatch(r"/v1/scenes/([0-9a-f]{1,32})/events", path)
        if path != "/v1/scenes" and not m:
            return self.answer(404, {"error": "not found"})
        body = self.json_body()
        if body is None:
            return self.answer(400, {"error": "a JSON object of sensible size is required"})
        if m:
            sess, gone = lookup(m.group(1))
            if sess is None:
                return self.answer(410 if gone else 404, {"error": "session %s" % (gone or "unknown")})
            status, obj = sess.event(body)
            return self.answer(status, obj, " event=%s" % str(body.get("type"))[:16])
        status, obj = start_session(body, self.headers.get("Host", ""))
        self.answer(status, obj, " kind=%s" % obj.get("kind", "-"))


class Stream(Base):
    server_version = "xeric-proxy-worldmodel-stream"

    def do_GET(self):
        if not self.guard():
            return
        m = re.fullmatch(r"/s/([0-9a-f]{1,32})\.mjpeg", urllib.parse.urlparse(self.path).path)
        if not m:
            return self.answer(404, {"error": "not found"})
        self.mjpeg(m.group(1))


def main(argv=None):
    global ARGS
    p = argparse.ArgumentParser(description="Xeric reference proxy: a local world model, engine-driven.")
    p.add_argument("--port", type=int, default=9100, help="the envelope: POST /v1/scenes and events")
    p.add_argument("--stream-port", type=int, default=0,
                   help="serve the MJPEG on a port of its own (default: the API port, which is the only "
                        "origin Xeric will play a session from)")
    p.add_argument("--host", default="127.0.0.1")
    p.add_argument("--public-host", default="", help="the host the session url names (default --host)")
    p.add_argument("--upstream-frames", default="",
                   help="a local video model's frames endpoint, e.g. http://127.0.0.1:7860/v1/frames")
    p.add_argument("--memory", type=int, default=3, help="recent finished frames sent back as memory")
    p.add_argument("--size", default="320x180", help="WxH of the proxy frame")
    p.add_argument("--fps", type=float, default=4.0)
    p.add_argument("--quality", type=int, default=80, help="JPEG quality")
    p.add_argument("--max-seconds", type=float, default=600.0, help="a session's life; the envelope may ask less")
    p.add_argument("--max-sessions", type=int, default=2)
    p.add_argument("--door-bearing", type=float, default=180.0,
                   help="where an arrival nobody can place comes in (a layout door wins)")
    p.add_argument("--frame-timeout", type=float, default=10.0, help="per upstream frame")
    p.add_argument("--max-bytes", type=int, default=32 * 1024 * 1024)
    p.add_argument("--wait", type=float, default=20.0, help="accepted for symmetry; a session answers at once")
    p.add_argument("--dry-run", action="store_true", help="print the first frame's stats; call no upstream")
    p.add_argument("--allow-remote", action="store_true", help="answer clients that are not loopback")
    ARGS = p.parse_args(argv)
    try:
        ARGS.width, ARGS.height = (int(v) for v in ARGS.size.lower().split("x"))
        assert 16 <= ARGS.width <= 1920 and 16 <= ARGS.height <= 1080
    except (ValueError, AssertionError):
        p.error("--size is WxH, 16x16 to 1920x1080")
    if ARGS.upstream_frames and urllib.parse.urlparse(ARGS.upstream_frames).scheme not in ("http", "https"):
        p.error("--upstream-frames must be http(s)")
    api = ThreadingHTTPServer((ARGS.host, ARGS.port), Api)
    api.daemon_threads = True
    if ARGS.stream_port:
        stream = ThreadingHTTPServer((ARGS.host, ARGS.stream_port), Stream)
        stream.daemon_threads = True
        threading.Thread(target=stream.serve_forever, daemon=True).start()
    log("listening on %s:%d, stream on :%d, frames %s%s" % (ARGS.host, ARGS.port, ARGS.stream_port or ARGS.port,
        ARGS.upstream_frames or "the blockout itself", " DRY-RUN" if ARGS.dry_run else ""))
    try:
        api.serve_forever()
    except KeyboardInterrupt:
        pass


if __name__ == "__main__":
    main()
