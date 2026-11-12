{{-- BERITA ACARA KEJADIAN OPERASIONAL PELAYANAN LALIN — SK 40-KPTS-JMJ-XI-2025 hal. 18.

     Tata letaknya diukur dari pindaian kertasnya, termasuk yang kelihatan janggal:

     - Margin kirinya SEMPIT (12mm) dan barisnya RENGGANG. BA Kerusakan di sebelahnya
       kebalikannya — margin lebar, baris rapat. Dua dokumen Word yang berbeda, bukan
       satu template, dan menyamakan keduanya bikin dua-duanya salah.
     - TIDAK ADA NOMOR di formulir ini. Kertasnya memang tidak mencantumkannya —
       incident_ba.incident_ba_number tetap ada di database sebagai pegangan sistem
       (kolomnya unique NOT NULL), tapi tidak pernah dicetak.
     - Slot yang datanya belum ada tetap tercetak sebagai titik-titik dan diisi tangan.
       Yang begitu: Ramp (kalau di jalur utama), dan baris Mengetahui kalau
       DEPUTY_AM_TM_NAME belum diisi.
     - TIGA garis tanda tangan: MCS Supervisor + dua Petugas Layanan Jalan Tol, pada
       posisi 22/90/134 mm dari margin kiri — tidak dibagi rata tiga kolom.
     - Kalimat penutupnya BIRU sendirian di antara teks hitam. Itu memang begitu di
       kertasnya; disamakan hitam justru bikin cetakannya beda dari arsip yang ada.
     - Kertasnya menulis "Beriata Acara" — salah ketik yang ikut tercetak bertahun-tahun.
       Yang dicetak di sini ejaan yang benar; menyalin salah ketik bukan kepatuhan. --}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Berita Acara Kejadian</title>
    @include('pdf._style', ['margin' => '7mm 12mm', 'ukuran' => '9pt', 'rapat' => 2.1])
    <style>
        /* TIGA kolom, kolom kiri dan kanan sama lebar: judulnya jadi terpusat pada
           LEBAR HALAMAN, bukan pada sisa ruang di kanan logo. Dua kolom saja
           mendorongnya ke kanan dan langsung kelihatan miring dibanding kertasnya. */
        table.kop { width: 100%; margin-bottom: 4mm; }
        table.kop td { vertical-align: middle; }
        table.kop td.sisi { width: 42mm; }
        .kop h1 { font-weight: bold; }

        /* Blok isian petugas: "1." di margin, label 7mm masuk, titik dua di 47mm,
           garis isian 92mm. Diukur dari kertasnya. */
        table.data { width: 100%; }
        table.data td.no { width: 7mm; }
        table.data td.label { width: 40mm; }
        table.data td.titik { width: 6mm; }

        .uraian { border: 1px solid #000; height: 72mm; padding: 3mm; margin-top: 3mm; }
    </style>
</head>
<body>

@if ($draft)
    <div class="draft">DRAFT</div>
@endif

<table class="kop">
    <tr>
        <td class="sisi"><img src="{{ resource_path('views/pdf/logo.png') }}" style="width: 40mm;" alt="Jasamarga Tollroad Operator"></td>
        <td>
            <h1>Berita Acara</h1>
            <h1>Kejadian Operasional Pelayanan Lalin</h1>
        </td>
        <td class="sisi"></td>
    </tr>
</table>

<p class="rata">
    Pada hari ini <x-isian lebar="22mm">{{ $dibuat['hari'] }}</x-isian>
    tanggal <x-isian lebar="40mm">{{ $dibuat['tanggal'] }}</x-isian>
    bulan <x-isian lebar="29mm">{{ $dibuat['bulan'] }}</x-isian>
    tahun Dua ribu
    <x-isian lebar="44mm">{{ $dibuat['tahun_huruf'] }}</x-isian>
    yang bertanda tangan di bawah ini kami Petugas Layanan Jalan Tol Ruas Jogja – Solo :
</p>

<table class="data">
    @foreach ($officers as $i => $officer)
        <tr>
            <td class="no">{{ $i + 1 }}.</td>
            <td class="label">Nama</td>
            <td class="titik">:</td>
            <td><x-isian lebar="92mm" kiri>{{ $officer?->name }}</x-isian></td>
        </tr>
        <tr>
            {{-- NIK di formulir ini Nomor Induk KARYAWAN, bukan NIK KTP — yang
                 menandatangani pegawai, dan pengguna jalan baru diminta "No. SIM / KTP"
                 di BA Kerusakan. Kolomnya users.identity_code. --}}
            <td></td>
            <td class="label">NIK</td>
            <td class="titik">:</td>
            <td><x-isian lebar="92mm" kiri>{{ $officer?->identity_code }}</x-isian></td>
        </tr>
        <tr>
            <td></td>
            <td class="label">Jabatan</td>
            <td class="titik">:</td>
            <td><x-isian lebar="92mm" kiri>{{ $officer?->position }}</x-isian></td>
        </tr>
    @endforeach
</table>

<p class="rata jeda">
    Menerangkan bahwa pada saat melaksanakan tugas pada periode
    <x-isian lebar="14mm">{{ $ba->duty_period }}</x-isian>
    {{-- Kurung dan titik dua ditempelkan, tanpa napas: "( ......... )" dan
         "....... : ........" enak dibaca selama slotnya masih titik-titik, tapi begitu
         terisi jadi "( II ) Pukul 19 : 45" — berjarak seperti salah ketik. --}}
    (<x-isian lebar="7mm">{{ $shift }}</x-isian>)
    Pukul <x-isian lebar="7mm">{{ $kejadian['jam'] }}</x-isian>:<x-isian lebar="7mm">{{ $kejadian['menit'] }}</x-isian> WIB
    telah menemukan kejadian di KM <x-isian lebar="14mm">{{ $report->km }}</x-isian>
    + <x-isian lebar="14mm">{{ $report->m }}</x-isian>
    jalur <x-isian lebar="14mm">{{ $report->laneSlot() }}</x-isian>
    {{-- "Masuk" / "Keluar", bukan 'Ramp Masuk' — kata "Ramp" sudah tercetak di
         kirinya. Jalur utama mengembalikan kosong dan slotnya tetap titik-titik.
         Petanya di IncidentReport::locationSlot(). --}}
    / Ramp <x-isian lebar="40mm">{{ $report->locationSlot() }}</x-isian>
</p>

<p class="rata">
    Kejadian tersebut berupa <x-isian lebar="92mm">{{ $berupa }}</x-isian>
    dengan uraian kejadian sbb:
</p>

{{-- Kotak uraian memuat DUA hal. Sejak 1 Sep `conclusion` berubah jadi pilihan
     terkunci, dan prosa bebas satu-satunya pindah ke `incident_explanation` — jadi
     mencetak salah satu saja membuang separuh isi yang diminta formulir. --}}
<div class="uraian" style="line-height: 1.4;">
    {!! nl2br(e($report->incident_explanation)) !!}
    @if ($report->conclusion)
        <p style="margin-top: 4mm;"><strong>Tindak lanjut:</strong> {{ $report->conclusion }}</p>
    @endif
</div>

<p class="jeda" style="color: #1F3864;">Demikian Berita Acara ini dibuat dengan sebenar-benarnya.</p>

{{-- Tiga blok tanda tangan DIBAGI RATA TIGA, bukan di 22 / 90 / 134 mm seperti
     kertasnya. Di kertasnya jarak kolom 1-2 (68mm) hampir dua kali jarak kolom 2-3
     (44mm) — itu kesenjangan dokumen Word yang ditata tangan, dan kelihatan miring
     begitu ketiganya terisi nama. Yang ditiru maksudnya, bukan kesalahannya.

     Baris pertama tingginya nol dan cuma memasang lebar kolom: dengan table-layout
     fixed lebar diambil dari baris PERTAMA, dan baris berisi colspan membuat DomPDF
     membagi sendiri — "Petugas Layanan Jalan Tol" lalu meleset dari sumbu dua kolom
     yang dinaunginya. --}}
<table style="width: 100%; table-layout: fixed; margin-top: 2mm;">
    <tr><td style="width: 33.34%;"></td><td style="width: 33.33%;"></td><td></td></tr>
    <tr>
        <td style="text-align: center;">MCS Supervisor</td>
        <td colspan="2" style="text-align: center;">Petugas Layanan Jalan Tol</td>
    </tr>
    <tr>
        <td style="padding-top: 16mm; text-align: center;">
            <x-ttd :nik="$mcs?->identity_code">{{ $mcs?->name }}</x-ttd>
        </td>
        <td style="padding-top: 16mm; text-align: center;">
            <x-ttd :nik="$officers[0]?->identity_code">{{ $officers[0]?->name }}</x-ttd>
        </td>
        <td style="padding-top: 16mm; text-align: center;">
            <x-ttd :nik="$officers[1]?->identity_code">{{ $officers[1]?->name }}</x-ttd>
        </td>
    </tr>
</table>

<div class="mengetahui" style="line-height: 1.3; margin-top: 6mm;">
    Mengetahui,<br>
    Deputy Area Manager Traffic Management<br>
    Ruas Jogja - Solo
    {{-- Deputy AM TM bukan pengguna sistem: tidak punya akun dan tidak melakukan aksi
         apa pun, jadi namanya dari config. Kosong = tercetak kosong dan diisi tangan,
         bukan error. --}}
    <div style="margin-top: 10mm;">
        <x-ttd :nik="$deputy['nik']">{{ $deputy['name'] }}</x-ttd>
    </div>
</div>

</body>
</html>
