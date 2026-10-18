<form method="post" action="/admin/sumber" style="display:flex;flex-direction:column;gap:12px;margin-top:10px">@csrf
 @if($s)<input type="hidden" name="id" value="{{ $s->id }}">@endif
 <div class="row">
  <label>Kode <input name="kode" required value="{{ $s->kode ?? '' }}" placeholder="stunting-dinkes"></label>
  <label>Nama <input name="nama" required value="{{ $s->nama ?? '' }}" placeholder="Dinkes — prevalensi stunting"></label>
  <label>Driver <select name="driver">@foreach($drivers as $d)<option @selected(($s->driver ?? 'http_json')===$d)>{{ $d }}</option>@endforeach</select></label>
  <label>Jadwal (cron, WITA) <input name="jadwal" required value="{{ $s->jadwal ?? '0 2 * * *' }}"></label>
 </div>
 <div class="row">
  <label style="grid-column:span 2">URL dasar <input name="base_url" required value="{{ $s->base_url ?? '' }}" placeholder="https://api.contoh.go.id"></label>
  <label>Autentikasi <select name="auth_type">@foreach(['none'=>'Tanpa token','bearer'=>'Bearer token','header'=>'Header khusus','query'=>'Parameter query'] as $k=>$l)<option value="{{ $k }}" @selected(($s->auth_type ?? 'bearer')===$k)>{{ $l }}</option>@endforeach</select></label>
  <label>Nama header/parameter <input name="auth_name" value="{{ $s->auth_name ?? '' }}" placeholder="X-API-Key"></label>
 </div>
 <div class="row">
  <label>Token baru <input name="token" type="password" autocomplete="new-password" placeholder="{{ $s && $s->getRawOriginal('token') ? 'kosongkan agar token lama tetap' : 'tempel token' }}"></label>
  @if($s && $s->getRawOriginal('token'))<label style="flex-direction:row;align-items:center;gap:6px"><input type="checkbox" name="hapus_token" value="1"> Hapus token tersimpan</label>@endif
  <label style="flex-direction:row;align-items:center;gap:6px"><input type="checkbox" name="aktif" value="1" @checked($s->aktif ?? true)> Aktif</label>
 </div>
 <label>Opsi (JSON) <textarea name="opsi" spellcheck="false">{{ $s ? json_encode($s->opsi, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) : json_encode(['path'=>'/v1/data','query'=>['tahun'=>2026],'records_path'=>'data','peta'=>['metrik'=>'=nama_indikator','wilayah_kode'=>'kode_wilayah','tanggal'=>'periode','nilai'=>'nilai']], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</textarea></label>
 <div><button class="pri">Simpan</button></div>
</form>
