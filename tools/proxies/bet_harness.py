#!/usr/bin/env python3
"""
A recording tee, for engine/tests/bet-test.php and nothing else.

    python3 tools/proxies/bet_harness.py --log seen.jsonl \
        --route 9001=http://127.0.0.1:9101 --route 9002=http://127.0.0.1:9102

Each --route LISTEN=TARGET is a loopback listener that writes down every
request it is given — method, path, the JSON body whole — as one line of
--log, tagged with the listen port, and then hands the request to TARGET
unchanged, Host header included (so a machine that names "the host the
request came to", as worldmodel.py does for its session url, names this
listener, and the stream comes back through it too). The answer is passed
back as it arrives, chunk by chunk, so an MJPEG stream is a stream here
as well.

It is the one place the bet's three machines are watched from, so what each
of them RECEIVED is read the same way for all three, with no machine taught
to report it. It changes nothing: no field is added, dropped or reordered.

Standard library only.
"""

import argparse, http.client, json, sys, threading, urllib.parse
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

LOCK = threading.Lock()
LOG = None
HOP = {"connection", "keep-alive", "transfer-encoding", "te", "trailer", "upgrade",
       "proxy-connection", "proxy-authenticate", "proxy-authorization"}


def record(rec):
    with LOCK:
        with open(LOG, "a", encoding="utf-8") as f:
            f.write(json.dumps(rec, separators=(",", ":")) + "\n")


def handler_for(listen, target):
    t = urllib.parse.urlparse(target)

    class Tee(BaseHTTPRequestHandler):
        def log_message(self, *a):
            pass

        def relay(self):
            n = int(self.headers.get("Content-Length") or 0)
            raw = self.rfile.read(n) if n > 0 else b""
            try:
                body = json.loads(raw) if raw else None
            except ValueError:
                body = {"_not_json": len(raw)}
            record({"port": listen, "method": self.command, "path": self.path, "body": body})
            headers = {k: v for k, v in self.headers.items() if k.lower() not in HOP}
            conn = http.client.HTTPConnection(t.hostname, t.port or 80, timeout=30)
            try:
                conn.request(self.command, self.path, body=raw if raw else None, headers=headers)
                resp = conn.getresponse()
            except OSError as e:
                conn.close()
                data = json.dumps({"error": "tee: target unreachable: %s" % type(e).__name__}).encode()
                self.send_response(502)
                self.send_header("Content-Type", "application/json")
                self.send_header("Content-Length", str(len(data)))
                self.end_headers()
                self.wfile.write(data)
                return
            self.send_response(resp.status)
            for k, v in resp.getheaders():
                if k.lower() not in HOP:
                    self.send_header(k, v)
            self.send_header("Connection", "close")
            self.end_headers()
            try:
                while True:
                    chunk = resp.read1(65536)
                    if not chunk:
                        break
                    self.wfile.write(chunk)
                    self.wfile.flush()
            except (BrokenPipeError, ConnectionResetError, OSError):
                pass
            finally:
                conn.close()

        do_GET = do_POST = do_DELETE = relay

    return Tee


def main():
    global LOG
    ap = argparse.ArgumentParser(description="A recording tee for the bet test.")
    ap.add_argument("--log", required=True)
    ap.add_argument("--route", action="append", default=[], help="LISTEN_PORT=http://127.0.0.1:TARGET_PORT")
    a = ap.parse_args()
    LOG = a.log
    servers = []
    for r in a.route:
        port, _, target = r.partition("=")
        srv = ThreadingHTTPServer(("127.0.0.1", int(port)), handler_for(int(port), target))
        srv.daemon_threads = True
        servers.append(srv)
    for srv in servers[1:]:
        threading.Thread(target=srv.serve_forever, daemon=True).start()
    sys.stderr.write("bet tee: %s\n" % ", ".join(a.route))
    sys.stderr.flush()
    try:
        servers[0].serve_forever()
    except KeyboardInterrupt:
        pass


if __name__ == "__main__":
    main()
