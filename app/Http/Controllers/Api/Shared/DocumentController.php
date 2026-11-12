<?php

namespace App\Http\Controllers\Api\Shared;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Services\Shared\DocumentStorageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    public function __construct(private readonly DocumentStorageService $documents) {}

    /**
     * Menyajikan berkas. Ini satu-satunya jalan keluar file dari disk `local` —
     * tidak ada URL statis, karena folder ini berisi foto KTP dan NIK pihak ketiga.
     */
    public function show(Document $document): StreamedResponse
    {
        Gate::authorize('view', $document);

        abort_unless(Storage::disk('local')->exists($document->path), 404);

        // mime_type diambil dari baris database, bukan ditebak ulang dari isi file:
        // yang tersimpan itu yang sudah divalidasi waktu unggah.
        return Storage::disk('local')->response($document->path, null, [
            'Content-Type' => $document->mime_type,
            // Tanpa ini browser boleh menebak ulang tipe file dari isinya dan
            // mengabaikan Content-Type di atas — penebakan itulah yang mengubah
            // berkas unggahan jadi HTML yang dieksekusi.
            'X-Content-Type-Options' => 'nosniff',
            // Header yang sama dengan yang dipasang ServeFile bawaan Laravel pada
            // berkas yang dilayaninya. Lapis terakhir: sekalipun ada berkas yang
            // lolos dan sempat dirender, 'sandbox' menaruhnya di origin buntu dan
            // default-src 'none' memutus jalan pulangnya.
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    public function destroy(Request $request, Document $document): array
    {
        Gate::authorize('delete', $document);

        // Penghapusnya ikut diteruskan: activity_logs mencatat siapa, dan Policy sudah
        // memastikan dia pemilik laporannya.
        $this->documents->delete($document, $request->user());

        return ['message' => 'Berkas dihapus.'];
    }
}
