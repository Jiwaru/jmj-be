<?php

namespace App\Http\Controllers\Api\Shared;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Lonceng notifikasi — database notifications bawaan Laravel, TANPA push. Aplikasi
 * menanyakannya sendiri (polling); notifikasi cuma terlihat selagi aplikasi dibuka.
 *
 * Milik akun yang login, jadi tidak butuh Policy: query-nya selalu lewat
 * `$request->user()->notifications()`, notifikasi orang lain tidak pernah terjangkau.
 */
class NotificationController extends Controller
{
    public function index(Request $request): array
    {
        $user = $request->user();

        // 50 per halaman, `?page=` untuk halaman berikutnya. Tanpa `page` = halaman 1 =
        // 50 terbaru, persis respons lama — lonceng yang sudah ada tidak perlu diubah.
        $halaman = $user->notifications()->paginate(50, ['id', 'data', 'read_at', 'created_at']);

        return [
            'notifications' => $halaman->items(),
            // Dihitung dari SEMUA notifikasi, bukan halaman ini — badge lonceng.
            'unread_count' => $user->unreadNotifications()->count(),
            // Di dalam `data`, bukan `meta` di root: paginator utuh di root akan dipecah
            // ApiEnvelope jadi `data: [...]`, dan `unread_count` ikut hilang.
            'pagination' => [
                'current_page' => $halaman->currentPage(),
                'last_page' => $halaman->lastPage(),
                'per_page' => $halaman->perPage(),
                'total' => $halaman->total(),
            ],
        ];
    }

    public function markRead(Request $request, string $id): array
    {
        $request->user()->notifications()->findOrFail($id)->markAsRead();

        return ['unread_count' => $request->user()->unreadNotifications()->count()];
    }

    public function markAllRead(Request $request): array
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return ['unread_count' => 0];
    }
}
