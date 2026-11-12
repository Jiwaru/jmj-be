{{-- Slot isian formulir.

     Titik-titiknya PLACEHOLDER, bukan garis tanda tangan. Yang KOSONG tercetak
     titik-titik selebar ukuran kertasnya dan diisi tangan; yang TERISI diganti
     nilainya — teks biasa, TANPA span pembungkus.

     Tanpa span itu bukan kerapian, itu syarat: span-nya inline-block, dan kotak
     inline-block tidak duduk di baseline yang sama dengan teks di sekelilingnya —
     nilainya kelihatan naik beberapa titik dari "Pada hari ini" di kirinya. Teks polos
     tidak punya kotak, jadi tidak ada yang bisa meleset.

     `lebar` hasil ukuran dari pindaian formulir SK, per slot, bukan seragam. --}}
@props(['lebar' => '14mm', 'kiri' => false])
@if (trim($slot->toHtml()) === '')<span class="f{{ $kiri ? ' kiri' : '' }}" style="min-width: {{ $lebar }};"></span>@else{!! $slot !!}@endif
