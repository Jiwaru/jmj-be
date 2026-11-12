{{-- Satu garis tanda tangan: nama di garisnya, NIK di bawahnya.

     Aturannya sama dengan komponen isian — titik-titik itu PLACEHOLDER. Ada namanya,
     titik-titiknya hilang dan namanya yang tercetak; belum ada, titik-titiknya tinggal
     dan diisi tangan. Yang begitu: PIHAK KESATU (diteken basah pengguna jalan) dan
     Deputy AM TM selama DEPUTY_AM_TM_NAME belum diisi.

     `nik` boleh null — blok PIHAK KESATU di BA Kerusakan memang tidak punya baris NIK
     di kertasnya. --}}
@props(['nik' => null])
@php $nama = trim($slot->toHtml()); @endphp
<span class="garis-ttd{{ $nama === '' ? ' kosong' : '' }}">{!! $slot !!}</span>
@if ($nik !== null)<span class="nik">NIK. {{ $nik }}</span>@endif
