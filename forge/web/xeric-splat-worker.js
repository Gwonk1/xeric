// xeric-splat-worker.js — the depth sort for xeric-splat.js, off the main thread.
//
// Served from this origin and constructed as
// new Worker('xeric-splat-worker.js?v=<hash>'): `worker-src 'self'` allows
// exactly that and nothing else — no blob:, no data:, and no WebAssembly anywhere in it.
//
// THE PROTOCOL, three messages:
//   in  { type:'pos',  pos: Float32Array(3n), n }  once, the centres (transferred)
//   in  { type:'sort', eye:[x,y,z], fwd:[x,y,z] }  per camera change
//   out { type:'order', order: Uint32Array(n) }    back to front (transferred)
//
// DEBOUNCED: sorts that arrive while one is queued replace it, so a drag that
// outruns the sort is answered once, for where the camera is now. The page
// also keeps only one in flight; this is the second line.
//
// THE SORT is a counting sort on depth along the view direction, quantised to
// 16 bits (65,536 buckets): two passes over the splats and one over the
// buckets, O(n). Two splats closer than (range / 65,536) in depth may draw in
// either order, which at a room's few metres is a fraction of a millimetre.
(function (self) {
  'use strict';
  var pos = null, n = 0, want = null, queued = false;

  function depthSort(pos, n, eye, fwd) {
    var depth = new Float32Array(n), lo = Infinity, hi = -Infinity, i;
    for (i = 0; i < n; i++) {
      var d = (pos[i * 3] - eye[0]) * fwd[0] + (pos[i * 3 + 1] - eye[1]) * fwd[1] + (pos[i * 3 + 2] - eye[2]) * fwd[2];
      depth[i] = d; if (d < lo) lo = d; if (d > hi) hi = d;
    }
    var k = 65535 / ((hi - lo) || 1), keys = new Uint16Array(n), count = new Uint32Array(65536);
    for (i = 0; i < n; i++) { var b = ((hi - depth[i]) * k) | 0; keys[i] = b; count[b]++; }   // farthest is bucket 0
    for (var s = 0, j = 0; j < 65536; j++) { var c = count[j]; count[j] = s; s += c; }
    var order = new Uint32Array(n);
    for (i = 0; i < n; i++) order[count[keys[i]]++] = i;
    return order;
  }

  function run() {
    queued = false;
    var m = want; want = null;
    if (!m || !pos) return;
    var order = depthSort(pos, n, m.eye, m.fwd);
    self.postMessage({ type: 'order', order: order }, [order.buffer]);
  }

  self.onmessage = function (e) {
    var m = e.data || {};
    if (m.type === 'pos') { pos = m.pos; n = Math.min(m.n | 0, pos.length / 3); return; }
    if (m.type === 'sort') {
      want = m;
      if (!queued) { queued = true; setTimeout(run, 0); }
    }
  };
  self.depthSort = depthSort;          // for the test, which runs this file in a sandbox
})(self);
