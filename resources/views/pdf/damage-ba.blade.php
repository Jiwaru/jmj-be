{{-- BERITA ACARA KERUSAKAN SARANA AKIBAT KECELAKAAN — SK 40-KPTS-JMJ-XI-2025 hal. 19.

     Tata letaknya diukur dari pindaian kertasnya. Yang perlu diketahui:

     1. Margin kirinya LEBAR (24mm) dan barisnya RAPAT (line-height 1.2) — kebalikan
        BA Kejadian yang marginnya sempit dan barisnya renggang. Dua dokumen Word yang
        berbeda; menyamakan keduanya bikin dua-duanya salah.

     2. Seluruh halaman dicetak BIRU TUA. Itu warna kertasnya, bukan hiasan — BA
        Kejadian di sebelahnya hitam, dan kalau disamakan yang satu tidak cocok dengan
        arsip yang sudah ada.

     3. Logo di ATAS judul, bukan di sampingnya. Di BA Kejadian logonya di samping.
        Sekali lagi: dua dokumen berbeda.

     4. Nomornya dipecah jadi empat potong seperti kertasnya, bukan satu slot panjang:
        Nomor : ......... / TM .JOGLO /......... / 20 .......

     5. Tabelnya TIDAK punya kolom persentase kerusakan. Baris dengan
        damage_percentage < 100 karena itu tidak bisa dikalikan pembaca
        (volume x harga satuan != jumlah harga). Persentasenya disebut di kolom
        JENIS KERUSAKAN supaya alasannya kelihatan, dan HARGA SATUAN tetap harga
        Lampiran I apa adanya — angka yang bisa dicocokkan orang dengan katalog resmi
        tidak boleh dipalsukan. Baris 100% dicetak polos.

     6. Garis tanda tangan PIHAK KESATU memuat namanya, tapi tanda tangannya tetap basah.
        `customer_signature` dari canvas Step 5 sengaja tidak ditempel: yang
        ditandatangani di HP itu pernyataan kesanggupan di TKP, sedangkan BA ini baru
        lahir belakangan di tangan MCS. Berkasnya tetap ada di `documents`.

     7. ponytail: sepuluh baris tabel seperti kertasnya. Kalau itemnya lebih dari
        sepuluh, halaman keduanya wajar. Kalau suatu saat WAJIB satu halaman berapa pun
        itemnya, yang dikorbankan berikutnya baris kosong, lalu alamat — bukan font. --}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Berita Acara Kerusakan</title>
    @include('pdf._style', ['margin' => '13mm 24mm', 'ukuran' => '9pt', 'rapat' => 1.2])
    <style>
        .kop img { display: block; width: 50mm; margin-bottom: 2mm; }
        .kop h1 { font-weight: bold; }

        /* Blok isian: "1." 8mm dari margin, titik dua di 44mm, garis isian 40mm. */
        table.data { margin-left: 8mm; }
        table.data td.no { width: 6mm; }
        table.data td.label { width: 30mm; }
        table.data td.titik { width: 6mm; }

        /* Lebar kolom tabel rincian: 12+60+22+32+36 = 162mm = lebar isi halaman. */
        table.rincian { font-size: 8pt; margin: 2mm 0; }
    </style>
</head>
<body class="biru">

@if ($draft)
    <div class="draft">DRAFT</div>
@endif

<div class="kop">
    <img src="{{ resource_path('views/pdf/logo.png') }}" alt="Jasamarga Tollroad Operator">
    <h1>Berita Acara Kerusakan Sarana Akibat Kecelakaan</h1>
    <h1>Ruas Jogja – Solo</h1>
</div>

{{-- Nomor dipecah balik ke potongan kertasnya. Sumbernya tetap satu kolom
     damage_ba_number ("004/TM.JOGLO/IX/2026") — yang dipecah cuma tampilannya,
     supaya tidak ada nomor kedua yang bisa berbeda dari yang tersimpan. --}}
@php $n = explode('/', (string) $ba->damage_ba_number); @endphp
<p class="nomor jeda">
    Nomor : <x-isian lebar="14mm">{{ $n[0] ?? '' }}</x-isian> / TM .JOGLO /<x-isian lebar="14mm">{{ $n[2] ?? '' }}</x-isian>
    / 20 <x-isian lebar="7mm">{{ substr($n[3] ?? '', -2) }}</x-isian>
</p>

<p class="jeda">
    Pada hari ini <x-isian lebar="25mm">{{ $dibuat['hari'] }}</x-isian>,
    tanggal <x-isian lebar="18mm">{{ $dibuat['tanggal'] }}</x-isian>,
    bulan <x-isian lebar="36mm">{{ $dibuat['bulan'] }}</x-isian>
    tahun <x-isian lebar="22mm">{{ $dibuat['tahun'] }}</x-isian>
</p>

<p>
    Pukul <x-isian lebar="14mm">{{ $dibuat['jam'] }}.{{ $dibuat['menit'] }}</x-isian> WIB,
    yang bertanda tangan dibawah ini:
</p>

<table class="data">
    <tr>
        <td class="no">1.</td>
        <td class="label">Nama / Umur</td>
        <td class="titik">:</td>
        <td>
            {{-- TABRAK LARI ditulis apa adanya, bukan dibiarkan kosong. BA-nya memang
                 harus terbit walau pelakunya tidak ada — itu yang memasukkan asetnya ke
                 antrean Work Order — tapi blok PIHAK KESATU yang kosong seluruhnya
                 terbaca seperti formulir yang lupa diisi, padahal justru itu faktanya. --}}
            {{-- Dirakit di PHP, bukan @if berjajar di dalam slot: "@endif@else" TIDAK
                 dikenali Blade — pola direktifnya menuntut `\B@`, dan huruf 'f' di
                 depan '@' membuatnya jadi batas kata, jadi @else-nya lolos sebagai
                 teks biasa dan cabang tabrak lari tidak pernah tercetak. --}}
            @php
                $pihakKesatu = $culprit
                    ? $culprit->full_name.($culprit->age ? " / {$culprit->age} th" : '')
                    : 'TIDAK TERIDENTIFIKASI (tabrak lari)';
            @endphp
            <x-isian lebar="40mm" kiri>{{ $pihakKesatu }}</x-isian>
        </td>
    </tr>
    <tr>
        <td></td>
        <td class="label">Pekerjaan</td>
        <td class="titik">:</td>
        <td><x-isian lebar="40mm" kiri>{{ $culprit?->occupation }}</x-isian></td>
    </tr>
    <tr>
        <td></td>
        <td class="label">Alamat</td>
        <td class="titik">:</td>
        <td><x-isian lebar="40mm" kiri>{{ $culprit?->address }}</x-isian></td>
    </tr>
    <tr>
        <td></td>
        <td class="label">No. SIM / KTP</td>
        <td class="titik">:</td>
        <td>
            <x-isian lebar="40mm" kiri>{{ trim(($culprit?->driver_license_number ?? '').' / '.($culprit?->national_id ?? ''), ' /') }}</x-isian>
        </td>
    </tr>
</table>

<p>
    Dalam hal ini disebut sebagai
    {{-- Ketiganya tetap tercetak seperti kertasnya, yang terpilih ditebalkan —
         itu cara kertas aslinya dipakai: dilingkari, bukan dua yang lain dihapus.
         Kalau party_role kosong (laporan versi lama), ketiganya tercetak polos dan
         dilingkari tangan. --}}
    @foreach (config('enums.party_role') as $i => $role)
        @if ($i) / @endif
        @if ($culprit?->party_role === $role)<strong>{{ strtolower($role) }}</strong>@else{{ strtolower($role) }}@endif
    @endforeach
    {{-- $vehicle, bukan $culprit->involvedVehicle: yang diminta slot ini kendaraan
         yang TERLIBAT, bukan kendaraan yang dikemudikan pelaku. Pemilik yang tidak
         ikut menyetir tautannya memang null (ERD), dan tanpa fallback di
         BaPdfService blok PIHAK KESATU-nya tercetak tanpa nomor polisi. --}}
    kendaraan jenis <x-isian lebar="40mm">{{ $vehicle?->vehicle_type }}</x-isian>
    no polisi : <x-isian lebar="40mm">{{ $vehicle?->plate_number }}</x-isian>
    yang mengalami kecelakaan pada hari <x-isian lebar="14mm">{{ $kejadian['hari'] }}</x-isian>,
    tanggal <x-isian lebar="14mm">{{ $kejadian['tanggal'] }} {{ $kejadian['bulan'] }} {{ $kejadian['tahun'] }}</x-isian>,
    jam <x-isian lebar="14mm">{{ $kejadian['jam'] }}.{{ $kejadian['menit'] }}</x-isian>
    wib, lokasi di KM <x-isian lebar="7mm">{{ $report->km }}</x-isian>
    + <x-isian lebar="7mm">{{ $report->m }}</x-isian>
    Jalur <x-isian lebar="14mm">{{ $report->laneSlot() }}</x-isian>
    {{-- Seksi diturunkan dari km lewat config('toll.sections'), bukan kolom. Kalau km
         jatuh di luar semua rentang, slotnya KOSONG — bukan dibulatkan ke seksi
         terdekat. Seksi salah cetak di dokumen tagihan gagalnya senyap. --}}
    Jalan Tol Seksi <x-isian lebar="14mm">{{ $seksi }}</x-isian>
    {{-- "on/off" itu pilih-satu seperti "pemilik / pengemudi / pengurus" di atas:
         yang terpilih ditebalkan, yang lain tetap tercetak, karena begitu cara
         kertasnya dipakai — dilingkari, bukan dihapus.
         Slot titik-titiknya lalu DIKOSONGKAN untuk ramp: arahnya sudah terjawab oleh
         tebalnya, dan yang tersisa ditanyakan slot itu adalah ramp MANA — kolomnya
         tidak ada, jadi diisi tangan. Gerbang Tol / Rest Area tidak menebalkan apa
         pun dan namanya yang mengisi slot. --}}
    @php
        $arah = $report->rampDirection();
        // Ditulis begini, bukan @if/@else berjajar: kertasnya menulis "on/off" TANPA
        // spasi, dan @else yang diberi napas menghasilkan "on /off".
        $tebal = fn (string $kata) => $arah === $kata ? "<strong>{$kata}</strong>" : $kata;
    @endphp
    / {!! $tebal('on') !!}/{!! $tebal('off') !!}
    ramp <x-isian lebar="14mm">{{ $arah ? '' : $report->locationSlot() }}</x-isian>,
    yang selanjutnya disebut sebagai <strong>PIHAK KESATU</strong>
</p>

<table class="data" style="margin-top: 3.5mm;">
    <tr>
        <td class="no">2.</td>
        <td class="label">Nama</td>
        <td class="titik">:</td>
        <td><x-isian lebar="40mm" kiri>{{ $mcs?->name }}</x-isian></td>
    </tr>
    <tr>
        {{-- NIK karyawan, bukan NIK KTP — pembedanya ada di formulir ini sendiri:
             PIHAK KESATU diminta "No. SIM / KTP", PIHAK KEDUA diminta "NIK". --}}
        <td></td>
        <td class="label">NIK</td>
        <td class="titik">:</td>
        <td><x-isian lebar="40mm" kiri>{{ $mcs?->identity_code }}</x-isian></td>
    </tr>
    <tr>
        {{-- Tercetak konstan di kertasnya, bukan titik-titik. MCS = Mobile Customer
             Service — itu yang tertulis di formulir SK, dan itu kepanjangannya. --}}
        <td></td>
        <td class="label">Jabatan</td>
        <td class="titik">:</td>
        <td>Mobile Customer Service Supervisor</td>
    </tr>
</table>

<p>Yang selanjutnya disebut sebagai <strong>PIHAK KEDUA</strong> /&nbsp; PT. Jasamarga Tollroad Operator</p>

<p class="jeda"><strong>PIHAK KESATU dan PIHAK KEDUA secara bersama-sama telah meninjau / melihat dan
    menghitung jumlah / nilai kerusakan sarana milik PT. Jasamarga Tollroad Operator yang
    diakibatkan oleh kejadian tersebut sebagai berikut :</strong></p>

@php $tambahan = (float) $report->additional_cost; @endphp
<table class="rincian">
    <tr>
        <th style="width: 12mm;">NO</th>
        <th style="width: 60mm;">JENIS KERUSAKAN</th>
        <th style="width: 22mm;">VOLUME</th>
        <th style="width: 32mm;">HARGA SATUAN<br>(Rp)</th>
        <th style="width: 36mm;">JUMLAH HARGA<br>(Rp)</th>
    </tr>
    @foreach ($items as $i => $item)
        <tr>
            <td class="no">{{ $i + 1 }}</td>
            <td>
                {{-- KOMPONENNYA saja, bukan type + component. Contoh tabel di SK dan
                     di Alur 2.3 menulis "Guard Rail Beam (4000X312X2.7)" — nama type
                     ("Guardrail Kendaraan Tipe A") sudah terkandung di situ, dan
                     menggabungkannya menghasilkan "Guardrail Kendaraan Tipe A Guard
                     Rail Beam (...)" yang mengulang dirinya sendiri.
                     Nama type dipakai HANYA kalau komponennya kosong — baris katalog
                     yang tidak punya komponen tetap harus punya sebutan. --}}
                {{ $item->assetCatalog?->component ?: $item->assetCatalog?->assetType?->name }}
                {{-- Persentase disebut HANYA kalau bukan 100. Tabel formulir tidak punya
                     kolomnya, jadi tanpa keterangan ini baris 40% terbaca seperti salah
                     hitung — dan di dokumen yang dipakai menagih, itu bahan sanggahan
                     pertama yang akan diangkat siapa pun yang keberatan. --}}
                @if ((float) $item->damage_percentage != 100.0)
                    (kerusakan {{ rtrim(rtrim(number_format((float) $item->damage_percentage, 2, ',', '.'), '0'), ',') }}%)
                @endif
            </td>
            <td class="angka">
                {{ rtrim(rtrim(number_format((float) $item->quantity, 2, ',', '.'), '0'), ',') }}
                {{ $item->assetCatalog?->unit_of_measurement }}
            </td>
            {{-- Harga Lampiran I apa adanya, TIDAK dikali persentase. --}}
            <td class="angka">{{ number_format((float) $item->catalogPrice?->max_price, 0, ',', '.') }}</td>
            <td class="angka">{{ number_format((float) $item->subtotal, 0, ',', '.') }}</td>
        </tr>
    @endforeach
    {{-- BIAYA TAMBAHAN PUNYA BARISNYA SENDIRI. Angkanya sudah ikut ke TOTAL lewat
         incident_report.total_estimation, jadi tanpa baris ini kolom JUMLAH HARGA
         tidak bisa dijumlah pembacanya — dan selisih yang tidak bisa dijelaskan di
         dokumen yang dipakai menagih adalah bahan sanggahan pertama.

         VOLUME dan HARGA SATUAN dikosongkan, bukan diisi "1": ini bukan item
         Lampiran I, jadi tidak ada harga satuan resmi yang bisa dicocokkan orang.

         ponytail: satu baris tanpa rincian. Kalau kantor menjawab Mentok #10 dan
         biaya tambahan ternyata punya komponen sendiri (mobilisasi, alat berat),
         yang benar tabel keduanya — bukan memecah baris ini. --}}
    @if ($tambahan > 0)
        <tr>
            <td class="no">{{ $items->count() + 1 }}</td>
            <td>Biaya tambahan di luar katalog</td>
            <td class="angka"></td>
            <td class="angka"></td>
            <td class="angka">{{ number_format($tambahan, 0, ',', '.') }}</td>
        </tr>
    @endif
    @for ($i = $items->count() + ($tambahan > 0 ? 1 : 0); $i < 10; $i++)
        <tr class="kosong"><td></td><td></td><td></td><td></td><td></td></tr>
    @endfor
    <tr>
        <td class="polos"></td><td class="polos"></td><td class="polos"></td>
        <td class="total">TOTAL</td>
        <td class="angka"><strong>{{ number_format((float) $ba->total_amount, 0, ',', '.') }}</strong></td>
    </tr>
</table>

<p class="jeda">Terbilang <x-isian lebar="140mm" kiri>{{ $ba->amount_in_words }}</x-isian></p>

<p class="jeda">Demikian Berita Acara ini dibuat dengan sebenar-benarnya.</p>

{{-- Tabel lebar-tetap, BUKAN span absolut: DomPDF tidak menghormati `width` pada
     kotak yang diposisikan absolut — kolomnya melar selebar sisa halaman dan isinya
     berhenti terpusat. Dua kolom 46mm dengan pemisah 66mm menaruh sumbu keduanya di
     23 dan 135 mm dari margin kiri, sama seperti kertasnya. --}}
<table style="width: 158mm; table-layout: fixed; margin-top: 4mm;">
    <tr>
        <td style="width: 46mm; text-align: center;">Pihak Kedua<br>MCS Supervisor&nbsp; Shift {{ $shift ?: 'I /II/ III' }}</td>
        <td style="width: 66mm;"></td>
        <td style="width: 46mm; text-align: center; vertical-align: top;">Pihak Kesatu</td>
    </tr>
    <tr>
        <td style="padding-top: 12mm; text-align: center;">
            <x-ttd :nik="$mcs?->identity_code">{{ $mcs?->name }}</x-ttd>
        </td>
        <td></td>
        {{-- Nama pelaku di garisnya, tanpa NIK (kertasnya tidak punya baris NIK untuk
             PIHAK KESATU). Tabrak lari: $culprit null, garisnya tetap titik-titik. --}}
        <td style="padding-top: 12mm; text-align: center;"><x-ttd>{{ $culprit?->full_name }}</x-ttd></td>
    </tr>
</table>

<div class="mengetahui" style="margin-top: 6mm;">
    Mengetahui,<br>
    Deputy Area Manager Traffic Management
    <div style="margin-top: 12mm;">
        <x-ttd :nik="$deputy['nik']">{{ $deputy['name'] }}</x-ttd>
    </div>
</div>

</body>
</html>
