<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Konektor Data · Dasbor Eksekutif</title>
<style>
:root{--bg:#f3f4f1;--panel:#fcfcfb;--line:#dfe1da;--ink:#121513;--ink2:#4b514c;--muted:#7c827b;--accent:#0e7c66;--good:#0a7d0a;--crit:#c03535}
@media (prefers-color-scheme:dark){:root{--bg:#0c0f0e;--panel:#151917;--line:#2a302d;--ink:#eef1ee;--ink2:#bfc6c0;--muted:#8a918b;--accent:#2fbf9c;--good:#2bb52b;--crit:#e66767;color-scheme:dark}}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif;padding:24px max(16px,env(safe-area-inset-left))}
.wrap{max-width:1100px;margin:0 auto;display:flex;flex-direction:column;gap:16px}
h1{margin:0;font-size:1.4rem}a{color:var(--accent)}
.card{background:var(--panel);border:1px solid var(--line);border-radius:6px;padding:16px}
.row{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}
label{display:flex;flex-direction:column;gap:4px;font-size:.8rem;color:var(--ink2)}
input,select,textarea{font:inherit;color:inherit;background:var(--bg);border:1px solid var(--line);border-radius:5px;padding:8px}
textarea{font-family:ui-monospace,Menlo,monospace;font-size:.82rem;min-height:110px}
button{font:inherit;border:1px solid var(--line);background:var(--panel);border-radius:5px;padding:7px 12px;cursor:pointer}
button.pri{background:var(--accent);color:#fff;border-color:var(--accent)}
table{width:100%;border-collapse:collapse;font-size:.88rem}td,th{padding:8px;border-bottom:1px solid var(--line);text-align:left;vertical-align:top}
.ok{color:var(--good)}.bad{color:var(--crit)}.mut{color:var(--muted);font-size:.8rem}
details summary{cursor:pointer}pre{white-space:pre-wrap;font-size:.78rem;max-height:220px;overflow:auto;background:var(--bg);padding:8px;border-radius:4px}
.flash{border-left:3px solid var(--accent)}
</style>
</head>
<body><div class="wrap">
<header style="display:flex;justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:8px"><h1>Konektor data</h1><a href="/">← Kembali ke dasbor</a></header>
@if(session('pesan'))<div class="card flash">{{ session('pesan') }}</div>@endif
@if($errors->any())<div class="card flash bad">{{ implode(' ', $errors->all()) }}</div>@endif

@foreach($sumber as $s)
<section class="card">
  <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap">
    <div><b>{{ $s->nama }}</b> <span class="mut">{{ $s->kode }} · {{ $s->driver }} · jadwal <code>{{ $s->jadwal }}</code> WITA · {{ $s->aktif ? 'aktif' : 'nonaktif' }}</span><br>
      <span class="mut">Terakhir sinkron: {{ $s->terakhir_sinkron?->timezone('Asia/Makassar')->format('d M Y H:i') ?? 'belum pernah' }} · Token: {{ $s->getRawOriginal('token') ? 'tersimpan (terenkripsi)' : 'tidak ada' }}</span></div>
    <div style="display:flex;gap:6px">
      <form method="post" action="/admin/sumber/{{ $s->id }}/sinkron">@csrf<button class="pri">{{ $s->minta_sinkron ? 'Menunggu penjadwal…' : 'Sinkronkan sekarang' }}</button></form>
      @unless(in_array($s->kode,['bsps','sp2kp']))<form method="post" action="/admin/sumber/{{ $s->id }}/hapus">@csrf<button>Hapus</button></form>@endunless
    </div>
  </div>
  @if($s->riwayat->count())
  <table style="margin-top:10px"><tr><th>Mulai</th><th>Status</th><th>Baris</th><th>Log</th></tr>
  @foreach($s->riwayat as $r)<tr><td>{{ $r->mulai->timezone('Asia/Makassar')->format('d M H:i') }}</td><td class="{{ $r->status==='sukses'?'ok':($r->status==='gagal'?'bad':'') }}">{{ $r->status }}</td><td>{{ number_format($r->baris,0,',','.') }}</td>
    <td><details><summary>lihat</summary><pre>{{ $r->pesan }}</pre></details></td></tr>@endforeach</table>
  @endif
  <details style="margin-top:10px"><summary>Ubah konektor</summary>@include('admin-form',['s'=>$s])</details>
</section>
@endforeach

<section class="card"><b>Tambah konektor API bertoken</b>
 <p class="mut">Untuk API JSON milik OPD/instansi. Token disimpan terenkripsi di server dan tidak pernah dikirim ke peramban pengguna dasbor.</p>
 @include('admin-form',['s'=>null])</section>
</div></body></html>
