{{-- Gaya bersama dua Berita Acara.

     Angka-angka di bawah ini DIUKUR dari pindaian formulir SK, bukan dikira-kira:
     lebar kolom, indentasi, dan tinggi baris tabel diambil dari posisi piksel di
     kertasnya lalu dikonversi ke milimeter. Kalau ada yang diubah, ubahnya dengan
     alasan yang bisa ditunjuk di kertasnya juga.

     Fontnya Helvetica, BUKAN DejaVu: formulir SK-nya diketik Arial, dan Helvetica
     satu-satunya core font PDF yang metriknya sama. Bonusnya font tidak ditanam sama
     sekali, jadi tidak ada urusan subsetting dan berkasnya kecil.

     Dua BA ini DUA dokumen Word yang berbeda, bukan satu template dengan dua isi —
     margin, ukuran font, dan rapat barisnya memang tidak sama. Yang beda diteruskan
     lewat $margin/$ukuran, yang sama tinggal di sini. --}}
<style>
    @page { margin: {{ $margin }}; }

    body {
        font-family: Helvetica, Arial, sans-serif;
        font-size: {{ $ukuran }};
        line-height: {{ $rapat }};
        color: #000;
    }

    /* BA Kerusakan dicetak biru tua seluruhnya, BA Kejadian hitam. Beda itu ada di
       kertasnya, bukan selera — arsip yang sudah ada berwarna begitu. */
    body.biru { color: #1F3864; }

    h1 { font-size: {{ $ukuran }}; text-align: center; margin: 0; text-transform: uppercase; }

    .nomor { text-align: center; }

    p { margin: 0; }
    p.rata { text-align: justify; }
    p.jeda { margin-top: {{ $ukuran }}; }

    /* Slot isian KOSONG — dipakai lewat komponen isian. Yang sudah terisi tidak memakai
       kelas ini sama sekali: dicetak sebagai teks biasa (lihat komponennya). */
    .f {
        border-bottom: 1px dotted currentColor;
        padding: 0 2px;
        display: inline-block;
        min-width: 14mm;
        text-align: center;
    }
    .f.kiri { text-align: left; }

    table.data td { padding: 0; vertical-align: top; }

    table.rincian { width: 100%; border-collapse: collapse; }
    table.rincian th, table.rincian td { border: 1px solid currentColor; padding: 0.3mm 1.5mm; }
    table.rincian th { text-align: center; font-weight: bold; }
    table.rincian td.no { text-align: center; }
    table.rincian td.angka { text-align: right; white-space: nowrap; }
    table.rincian tr.kosong td { height: 4.1mm; }
    /* Baris TOTAL: tiga kolom pertama TANPA garis, persis seperti kertasnya. */
    table.rincian td.polos { border: 0; }
    table.rincian td.total { text-align: right; font-weight: bold; }

    /* Blok tanda tangan pakai TABEL lebar-tetap, bukan kotak absolut: DomPDF tidak
       menghormati `width` pada kotak yang diposisikan absolut — kotaknya melar selebar
       sisa halaman dan isinya berhenti terpusat. Lebar kolomnya diukur dari kertasnya
       supaya sumbu tiap garis jatuh di tempat yang sama.

       Garisnya inline-block supaya bisa dipusatkan oleh text-align selnya, dan
       titik-titiknya HANYA muncul waktu namanya belum ada — begitu terisi yang
       tercetak namanya, tanpa garis. Aturan yang sama dengan komponen isian. */
    .garis-ttd {
        display: inline-block;
        width: 40mm;
        padding-top: 0.5mm;
        text-align: center;
    }
    .garis-ttd.kosong { border-top: 1px dotted currentColor; }
    /* margin auto: blok 40mm tidak ikut terpusat oleh text-align induknya. */
    .nik { display: block; width: 40mm; margin: 0 auto; text-align: center; }

    /* BA Kejadian menaruh tiga blok ini dengan koordinat absolut — lihat komentarnya
       di templatnya. Yang absolut cuma pembungkusnya (> span), isinya tidak ikut. */
    .tanda { position: relative; }
    .tanda > span { position: absolute; }

    .mengetahui { text-align: center; }

    /* Penanda DRAFT: yang belum disahkan HARUS kelihatan belum disahkan. PDF yang
       dicetak lalu diteken padahal belum divalidasi sistem adalah dokumen yang tidak
       punya nomor sah dan tidak bisa dilacak balik ke barisnya. */
    .draft {
        position: fixed;
        top: 42%;
        left: 0;
        width: 100%;
        text-align: center;
        font-size: 62pt;
        font-weight: bold;
        color: #000;
        opacity: 0.10;
        transform: rotate(-25deg);
    }
</style>
