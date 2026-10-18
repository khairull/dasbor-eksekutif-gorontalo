"""Membangun batas wilayah Provinsi Gorontalo (prov, kab/kota, kecamatan, desa) dari SHP BIG.

Sumber: github.com/Alf-Anas/batas-administrasi-indonesia (Kel_Desa, pembaruan 13 Juni 2023,
turunan geoservice.big.go.id). Kode wilayah dipadankan ke format Kemendagri tanpa titik.
Pemakaian: python3 build_batas.py <Kel_Desa.shp> <folder_keluaran> <daftar_desa.json>
"""
import sys, json
import geopandas as gpd
from shapely.geometry import mapping

src, out, ref = sys.argv[1], sys.argv[2], sys.argv[3]
g = gpd.read_file(src, engine="pyogrio", where="KODE_PROV = '75'")
g = g[g.geometry.notna()].copy()
g["desa"] = g.KODE_KD.fillna("").str.replace(".", "", regex=False)
g["kec"] = g.KODE_KEC.fillna("").str.replace(".", "", regex=False)
g["kab"] = g.KODE_KK.fillna("").str.replace(".", "", regex=False)
g = g[g.kab.str.len() == 4]  # buang "area tidak terdefinisi" tanpa kode kab
g = g.to_crs(23845)  # DGN95 / Indonesia TM-3 zona 50.1 untuk operasi geometri dalam meter
g["geometry"] = g.geometry.buffer(0)

refd = json.load(open(ref))
names = {"kab": {k["id"]: k["name"] for k in refd["kab"]},
         "kec": {k["id"]: k["name"] for k in refd["kec"]},
         "desa": {d["id"]: d["name"] for d in refd["desa"]}}

def write(gdf, level, tol, keycol):
    gdf = gdf.copy()
    gdf["geometry"] = gdf.geometry.simplify(tol, preserve_topology=True)
    gdf = gdf.to_crs(4326)
    feats = []
    for _, r in gdf.iterrows():
        geom = json.loads(json.dumps(mapping(r.geometry)), parse_float=lambda x: round(float(x), 5))
        kode = r[keycol]
        feats.append({"type": "Feature", "properties": {"id": kode, "name": names[level].get(kode) or r.get("nama_src") or kode}, "geometry": geom})
    fc = {"type": "FeatureCollection", "features": feats}
    path = f"{out}/{level}-75.geojson"
    json.dump(fc, open(path, "w"), separators=(",", ":"))
    return path, len(feats)

g["nama_src"] = g.KEL_DESA
desa = g.dissolve(by="desa", as_index=False, aggfunc="first")
desa = desa[desa.desa.str.len() == 10]
kec = g.dissolve(by="kec", as_index=False, aggfunc="first"); kec["nama_src"] = kec.KECAMATAN
kab = g.dissolve(by="kab", as_index=False, aggfunc="first"); kab["nama_src"] = kab.KAB_KOTA
for lvl, gdf, tol, key in [("kab", kab, 120, "kab"), ("kec", kec, 60, "kec"), ("desa", desa, 25, "desa")]:
    p, n = write(gdf, lvl, tol, key)
    print(lvl, n, p)

ours = set(names["desa"]); theirs = set(desa.desa)
print("desa referensi:", len(ours), "| poligon:", len(theirs), "| cocok:", len(ours & theirs))
print("tanpa poligon (contoh):", sorted(ours - theirs)[:12])
print("poligon tanpa referensi (contoh):", sorted(theirs - ours)[:12])
