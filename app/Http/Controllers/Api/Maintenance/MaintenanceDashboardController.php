<?php

namespace App\Http\Controllers\Api\Maintenance;

use App\Http\Controllers\Controller;
use App\Services\Maintenance\MaintenanceDashboardService;
use App\Support\Search;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

class MaintenanceDashboardController extends Controller
{
    public function __construct(private readonly MaintenanceDashboardService $dashboard) {}

    /**
     * Empat kartu angka di layar Dashboard Unit Maintenance.
     */
    public function summary(): array
    {
        return $this->dashboard->summary();
    }

    /**
     * Antrean pekerjaan — satu baris per titik lokasi yang menunggu Work Order.
     *
     * Paginator dikembalikan utuh, bukan ->items(): ApiEnvelope yang memecahnya jadi
     * `data` + `meta`, dan tanpa `last_page` layarnya tidak punya cara tahu masih ada
     * halaman berikutnya.
     */
    public function queue(): LengthAwarePaginator
    {
        return $this->dashboard->queue();
    }

    /**
     * Satu baris antrean perbaikan — untuk form penerbitan WO yang dibuka langsung.
     */
    public function queueItem(string $uuid): array
    {
        return ['report' => $this->dashboard->queueItem($uuid)];
    }

    /**
     * Antrean temuan patroli — satu baris per laporan rusak yang menunggu Work Order.
     * `?search=` dicari di server, `?per_page=` maks 100.
     */
    public function patrolQueue(Request $request): LengthAwarePaginator
    {
        return $this->dashboard->patrolQueue($request->input('search'), Search::perPage($request));
    }

    /**
     * Daftar petugas maintenance untuk dropdown penunjukan regu.
     *
     * Dibungkus kunci `officers`, bukan dikembalikan sebagai array telanjang: bentuknya jadi
     * sama dengan `data.officers` di sync/bootstrap, jadi komponen picker di FE tidak peduli
     * daftarnya datang dari mana.
     */
    public function officers(): array
    {
        return ['officers' => $this->dashboard->officers()];
    }
}
