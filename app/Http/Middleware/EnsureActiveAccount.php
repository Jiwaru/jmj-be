<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Menolak token milik akun yang sudah dinonaktifkan.
 *
 * TANPA INI, `is_active` cuma dibaca sekali seumur token. AuthService memeriksanya waktu
 * login, lalu token berumur 30 hari terbit — dan sejak detik itu mem-flip `is_active`
 * jadi `false` tidak berpengaruh apa pun. Akun yang sudah dimatikan tetap bisa menerbitkan
 * Work Order, mengesahkan Berita Acara, dan mengunduh foto KTP pihak ketiga sampai
 * tokennya kedaluwarsa sendiri.
 *
 * Itu membatalkan alasan memilih Sanctum. Keputusan 28 Agustus menolak JWT dengan kalimat
 * yang persis menggambarkan keadaan ini: "JWT tidak bisa dicabut — HP petugas hilang di
 * jalan tol, atau `is_active` di-flip `false`, tokennya tetap hidup sampai expiry."
 * Sanctum dipilih supaya pencabutan MUNGKIN; yang belum ada penjaganya.
 *
 * 401, bukan 403: yang salah kredensialnya, bukan izinnya. Klien yang menerima 401
 * membuang tokennya dan kembali ke layar masuk — dan di sana AuthService menjawab dengan
 * kalimat yang sama, jadi orangnya dapat penjelasan yang konsisten, bukan dua cerita.
 *
 * Satu query per request, dan itu memang harga pencabutan yang bekerja: Sanctum toh sudah
 * mengambil baris user-nya untuk request ini, jadi `is_active` sudah ada di memori —
 * tidak ada query tambahan sama sekali.
 */
class EnsureActiveAccount
{
    public function handle(Request $request, Closure $next)
    {
        abort_if($request->user() && ! $request->user()->is_active, 401,
            'Akun Anda sudah dinonaktifkan. Hubungi atasan atau administrator.');

        return $next($request);
    }
}
