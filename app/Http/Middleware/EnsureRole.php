<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Penjaga role, dipakai sebagai `->middleware('role:field_officer,mcs_supervisor')`.
 *
 * Sengaja allowlist: role yang tidak disebut TIDAK dapat izin. Sudah diantisipasi
 * ada role ke-5 (monitoring khusus JMJ), dan denylist — `abort_if($role === 'x')` —
 * membuatnya mewarisi izin tulis di hari pertama tanpa ada yang sadar.
 *
 * Ini cuma menjawab "role apa". Pertanyaan "punya siapa" dan "statusnya boleh
 * tidak" dijawab Policy dan Service, bukan di sini.
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        // Pesannya diisi: abort_unless(..., 403) polos menghasilkan body
        // {"message": ""} dan frontend tidak punya apa pun untuk ditampilkan.
        abort_unless(in_array($request->user()?->role, $roles, true), 403,
            'Role Anda tidak punya akses ke endpoint ini.');

        return $next($request);
    }
}
