"""Server tiruan sumber data untuk uji end-to-end konektor (tanpa akses internet).

Meniru bentuk respons endpoint asli yang terbaca 18 Okt 2026:
  /bsps/...   -> pkp.go.id/cek-penerima-bsps
  /sp2kp/...  -> api-sp2kp.kemendag.go.id
  /opd/...    -> contoh API bertoken (Bearer)
Data dibangkitkan dari snapshot agar hasil sinkron bisa dicocokkan angka per angka.

Jalankan: python3 mock_sources.py <snapshot.json> <port>
"""
import json, sys, random, uuid
from http.server import ThreadingHTTPServer, BaseHTTPRequestHandler
from urllib.parse import urlparse, parse_qs

snap = json.load(open(sys.argv[1]))
PORT = int(sys.argv[2])
TOKEN = "rahasia-uji-123"
rng = random.Random(42)

KABNAME = {"7501": "KABUPATEN GORONTALO", "7502": "KABUPATEN BOALEMO", "7503": "KABUPATEN BONE BOLANGO",
           "7504": "KABUPATEN POHUWATO", "7505": "KABUPATEN GORONTALO UTARA", "7571": "KOTA GORONTALO"}
kecs = snap["kec"]
desas = snap["desa"]
# satu entri ganda seperti di sumber asli (SARI TANI muncul dua kali)
desas_src = desas + [desas[5]]

markers = []  # (id, desa, progres, lat, lng)
for kode, v in snap["bsps"].items():
    tot, p0, p30, p100, px, lat, lng = v
    cats = ["0"] * p0 + ["30"] * p30 + ["100"] * p100 + ["50"] * px
    for c in cats:
        if lat is None:
            la, lo = 0.5, 122.5
        else:
            la, lo = lat + rng.uniform(-0.002, 0.002), lng + rng.uniform(-0.002, 0.002)
        markers.append((str(uuid.UUID(int=rng.getrandbits(128))), kode, c, la, lo))
# satu titik salah koordinat (di luar bbox) untuk menguji penyaringan centroid
markers[0] = markers[0][:3] + (-6.2, 106.8)

dates = snap["sp2kp"]["dates"]
series = snap["sp2kp"]["series"]
variants = {str(v["id"]): v for v in snap["sp2kp"]["variants"]}
pasar = {"P" + str(p["id"]): p for p in snap["sp2kp"]["pasar"]}


def bsps(path, q):
    g = lambda k: (q.get(k) or [None])[0]
    if path == "filter/tahun":
        return {"success": True, "data": [{"id": 2026, "name": "2026"}], "default": 2026}
    if path == "filter/kabkota":
        return {"success": True, "data": [{"id": int(k), "name": n, "province_id": 75, "province_name": "GORONTALO"} for k, n in KABNAME.items() if g("provinsi_id") == "75"]}
    if path == "filter/kecamatan":
        return {"success": True, "data": [{"id": int(k["id"]), "name": k["name"].upper(), "regency_id": int(k["kab"])} for k in kecs if k["kab"] == g("kabkota_id")]}
    if path == "filter/desa":
        return {"success": True, "data": [{"id": int(d["id"]), "name": d["name"].upper(), "district_id": int(d["kec"])} for d in desas_src if d["kec"] == g("kecamatan_id")]}
    if path == "markers":
        out = []
        for mid, desa, prog, la, lo in markers:
            if g("provinsi_id") and not desa.startswith(g("provinsi_id")): continue
            if g("kabkota_id") and not desa.startswith(g("kabkota_id")): continue
            if g("kecamatan_id") and not desa.startswith(g("kecamatan_id")): continue
            if g("desa_id") and desa != g("desa_id"): continue
            if g("progres_fisik") and prog != g("progres_fisik"): continue
            out.append({"id": mid, "lat": la, "long": lo})
        return {"success": True, "data": out, "total_aid_recipients": len(out)}
    return None


def sp2kp(path, q):
    if path != "report/api/average-price-public":
        return None
    tgl = (q.get("tanggal") or [""])[0]
    if tgl not in dates:
        return {"data": [], "totalCount": 0}
    i = dates.index(tgl)
    recs = []
    for node, vs in series.items():
        if node == "75": level, kab, pid = 1, None, None
        elif node.startswith("P"): level, kab, pid = 3, pasar[node]["kab"], int(node[1:])
        else: level, kab, pid = 2, node, None
        for vid, arr in vs.items():
            if arr[i] is None: continue
            v = variants[vid]
            r = {"id": rng.randint(1, 10**8), "tanggal": tgl, "kode_provinsi": "75", "kode_kab_kota": kab, "level": level,
                 "pasar_id": pid, "variant_id": int(vid), "harga": float(arr[i]) + 0.4,
                 "creator": {"first_name": "Petugas", "nip": "199001012020011001", "email": "petugas@example.go.id"},
                 "komoditas": {"nama": v["group"]}, "variant": {"nama": v["name"], "order_public": v["order"], "satuan": {"nama": "kg"}}}
            if pid:
                p = pasar[node]
                r["pasar"] = {"id": pid, "nama": p["name"], "kode_kab_kota": p["kab"], "kode_kecamatan": None, "kode_kelurahan": None, "lat": p["lat"], "lon": p["lng"]}
            recs.append(r)
    take = int((q.get("take") or ["10"])[0])
    return {"data": recs[:take], "totalCount": len(recs)}


def opd(path, q, headers):
    if headers.get("Authorization") != "Bearer " + TOKEN:
        return 401, {"message": "token tidak valid"}
    page = int((q.get("page") or ["1"])[0])
    rows = [{"kode_wilayah": k["id"], "periode": "2026-09-30", "persen": round(10 + j * 0.7, 2), "jumlah_balita": 100 + j}
            for j, k in enumerate(snap["kab"])]
    return 200, {"data": rows if page == 1 else []}


class H(BaseHTTPRequestHandler):
    def log_message(self, *a): pass

    def do_GET(self):
        u = urlparse(self.path)
        q = parse_qs(u.query)
        parts = u.path.strip("/").split("/", 1)
        code, body = 404, {"message": "tidak ada"}
        if parts[0] == "bsps":
            b = bsps(parts[1] if len(parts) > 1 else "", q)
            if b is not None: code, body = 200, b
        elif parts[0] == "sp2kp":
            b = sp2kp(parts[1] if len(parts) > 1 else "", q)
            if b is not None: code, body = 200, b
        elif parts[0] == "opd":
            code, body = opd(parts[1] if len(parts) > 1 else "", q, self.headers)
        data = json.dumps(body).encode()
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)


ThreadingHTTPServer(("127.0.0.1", PORT), H).serve_forever()
