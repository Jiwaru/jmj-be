<?php

namespace App\Services\Maintenance;

use App\Models\IncidentReport;
use App\Models\PatrolReport;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\Search;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Empat angka dan satu antrean di layar Unit Maintenance.
 *
 * TIDAK ADA SISTEM NOTIFIKASI TERPISAH. Daftar terfilter di `queue()` itu sendiri yang
 * merupakan notifikasinya — begitu MCS mengesahkan BA Kerusakan, kerusakannya muncul di
 * sini tanpa ada yang perlu mengirim apa pun. Menambahkan tabel notifikasi berarti dua
 * tempat yang bisa tidak sinkron untuk satu fakta yang sudah terbaca dari data.
 */
class MaintenanceDashboardService
{
    public function summary(): array
    {
        return [
            'repair_queue' => $this->repairQueue(),
            'patrol_queue' => $this->patrolQueueCount(),
            'active_work_orders' => $this->activeWorkOrders(),
            'pending_review' => $this->pendingReview(),
            'overdue' => $this->overdue(),
        ];
    }

    /**
     * "Antrean Perbaikan" — kerusakan tervalidasi yang belum ada Work Order-nya.
     *
     * SATU BARIS = SATU TITIK LOKASI = SATU WORK ORDER yang akan terbit, bukan satu
     * komponen. Empat guardrail rusak di KM yang sama itu satu kunjungan regu, jadi
     * menampilkannya sebagai empat kartu membuat Unit Maintenance menerbitkan empat WO
     * untuk satu pekerjaan.
     *
     * `damagedItems` sengaja dimuat DENGAN filter yang sama: kartunya menampilkan komponen
     * apa saja yang akan ditutup WO ini, dan yang sudah dikerjakan tidak boleh ikut
     * terhitung di sana.
     */
    public function queue(): LengthAwarePaginator
    {
        // Yang paling lama menunggu di atas — ini antrean pekerjaan, bukan kabar
        // terbaru. Laporan tertua yang belum dikerjakan adalah yang paling perlu
        // dilihat, kebalikan dari daftar milik MCS.
        return $this->queueQuery()->oldest('date_time')->paginate(20);
    }

    /**
     * SATU baris antrean, bentuknya sama persis dengan baris di queue(). Dipakai form
     * penerbitan WO yang dibuka langsung (tanpa daftar antrean yang sudah termuat).
     *
     * Ini jawaban atas "Unit tidak bisa membuka GET /incident-reports/{uuid}" — dan
     * memang tidak boleh: detail laporan membawa NIK, foto KTP, dan syarat pembayaran.
     * Yang dibutuhkan Unit cuma lokasi dan komponen yang belum ber-WO.
     *
     * 404 kalau laporannya bukan (lagi) antrean: BA Kerusakan belum sah, atau semua
     * kerusakannya sudah punya WO.
     */
    public function queueItem(string $uuid): IncidentReport
    {
        return $this->queueQuery()->where('uuid', $uuid)->firstOrFail();
    }

    private function queueQuery(): Builder
    {
        return IncidentReport::query()
            // Kolomnya DIBATASI. Baris laporan utuh ikut membawa settlement_type,
            // relief_requested, dan pledged_amount — itu syarat komersial antara JMJ dan
            // pengguna jalan, bukan urusan regu perbaikan. Jalur operasional dan jalur
            // finansial memang sengaja dipisah (Fase 3); membocorkannya lewat antrean
            // menyambung keduanya lagi tanpa ada yang memutuskan begitu.
            //
            // uuid WAJIB ikut walau layarnya tidak menampilkannya: dia local key untuk
            // damagedItems dan incidentBa, dan tanpa dia kedua relasi diam-diam kosong.
            ->select(['uuid', 'report_number', 'date_time', 'km', 'm', 'route', 'lane',
                'location_type', 'total_estimation', 'is_asset_broken'])
            ->whereHas('incidentBa.damageBa', fn ($q) => $q->where('validation_status', 'validated'))
            ->whereHas('damagedItems', fn ($q) => $q->whereNull('work_order_id'))
            ->with([
                'damagedItems' => fn ($q) => $q->whereNull('work_order_id')->with('assetCatalog.assetType'),
                'incidentBa:uuid,incident_report_id,incident_ba_number',
                'incidentBa.damageBa:uuid,damage_report_id,damage_ba_number,total_amount,validated_at',
            ]);
    }

    /**
     * "Antrean Patroli" — temuan patroli yang belum ada Work Order-nya. Notifikasinya
     * sendiri, sama seperti queue(): begitu petugas sync laporan rusak, dia muncul di
     * sini tanpa ada yang perlu mengirim apa pun.
     */
    public function patrolQueue(mixed $search = null, int $perPage = 20): LengthAwarePaginator
    {
        $pola = Search::pattern($search);

        return PatrolReport::query()
            ->where('is_damaged', true)
            ->whereDoesntHave('workOrder')
            ->with(['officer:id,identity_code,name', 'assetCatalog.assetType'])
            // Cari di uraian, jenis lokasi, dan nama/komponen aset. Kata kunci angka
            // ("14") juga dicocokkan ke KM secara PERSIS — "KM 14" tidak boleh ikut
            // menjaring KM 114 dan 140 seperti kalau KM dijadikan teks lalu di-ilike.
            ->when($pola, fn ($q) => $q->where(function ($q) use ($pola, $search) {
                $q->where('damage_description', 'ilike', $pola)
                    ->orWhere('location_type', 'ilike', $pola)
                    ->orWhereHas('assetCatalog', fn ($a) => $a->where('name', 'ilike', $pola)->orWhere('component', 'ilike', $pola));

                // Maks 4 digit: `km` smallint, dan 99999 dibandingkan ke smallint = 500.
                if (preg_match('/^\s*(?:km\s*)?(\d{1,4})\s*$/i', $search, $km)) {
                    $q->orWhere('km', (int) $km[1]);
                }
            }))
            ->oldest('patrolled_at')
            ->paginate($perPage);
    }

    /**
     * Isi dropdown penunjukan regu di layar penerbitan Work Order dan di modal Ganti Regu.
     *
     * PREDIKATNYA DIBACA, BUKAN DIKETIK ULANG. `User::petugasMaintenance()` adalah closure
     * yang sama persis yang dipakai memvalidasi `assignee_ids`. Ditulis ulang di sini, dua
     * tempat itu bebas melenceng — dan gejalanya bukan test merah, melainkan dropdown yang
     * menawarkan orang lalu menolaknya 422 begitu dipilih. Yang menemukan itu tim FE, atau
     * lebih buruk, orang Unit Maintenance.
     *
     * Tiga kolom saja, sama dengan `officers` di bekal luring dan sama dengan
     * `user:id,identity_code,name` yang di-eager-load di seluruh app: satu bentuk "orang"
     * di seluruh API berarti FE menulis satu komponen picker, bukan dua.
     *
     * `position` sengaja TIDAK ikut. Kolomnya nullable, dan pada data yang ada isinya
     * 'Petugas Maintenance' untuk ketiga-tiganya — kolom yang mencetak string yang sama di
     * setiap baris dropdown itu derau, bukan informasi. Kalau nanti jabatannya benar-benar
     * membedakan (Kepala Regu vs Teknisi), saat itu dia jadi label yang berguna.
     *
     * Tidak berpaginasi: ini isi dropdown, dan dropdown berhalaman bukan dropdown.
     *
     * @return Collection<int, User>
     */
    public function officers(): Collection
    {
        return User::where(User::petugasMaintenance())
            ->orderBy('name')
            ->get(['id', 'identity_code', 'name']);
    }

    /**
     * Angka kartu pertama: berapa TITIK yang menunggu diterbitkan Work Order.
     *
     * Bentuknya sengaja tidak memakai queue()->total(): yang ini cuma menghitung, dan
     * memuat 20 baris beserta relasinya untuk dibuang lagi itu ongkos yang tidak dibayar
     * siapa pun.
     */
    private function repairQueue(): int
    {
        return DB::table('incident_report as r')
            ->join('incident_ba as i', 'i.incident_report_id', '=', 'r.uuid')
            ->join('damage_ba as b', function ($join) {
                $join->on('b.damage_report_id', '=', 'i.uuid')
                    ->where('b.validation_status', 'validated');
            })
            ->whereExists(fn ($q) => $q->select(DB::raw(1))
                ->from('damaged_items as d')
                ->whereColumn('d.incident_report_id', 'r.uuid')
                ->whereNull('d.work_order_id'))
            ->count();
    }

    /**
     * Angka kartu kedua: berapa laporan patroli rusak yang menunggu Work Order.
     *
     * DB::table, bukan patrolQueue()->paginate()->total() — alasan sama dengan
     * repairQueue(): memuat 20 baris beserta relasinya cuma untuk dibuang lagi itu
     * ongkos yang tidak dibayar siapa pun.
     */
    private function patrolQueueCount(): int
    {
        return DB::table('patrol_report')
            ->where('is_damaged', true)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('work_orders')
                ->whereColumn('work_orders.patrol_report_id', 'patrol_report.uuid'))
            ->count();
    }

    /**
     * "Sedang Dikerjakan" — sudah terbit, belum diajukan selesai.
     */
    private function activeWorkOrders(): int
    {
        // `preparing` ikut sejak Fase 5: regu sudah menerima tugas dan sedang menyiapkan
        // material. Itu pekerjaan yang sedang berjalan, cuma belum di lokasi — tidak ikut,
        // kartunya berbohong tepat pada jam-jam paling sibuk.
        return DB::table('work_orders')->whereIn('repair_status', ['new', 'preparing', 'in_progress'])->count();
    }

    /**
     * "Menunggu Evaluasi" — regu mengajukan selesai, Unit Maintenance belum memeriksanya.
     */
    private function pendingReview(): int
    {
        return DB::table('work_orders')->whereIn('repair_status', WorkOrder::REVIEWABLE_STATUSES)->count();
    }

    /**
     * "Lewat Target" — dihitung dari `target_date`, bukan `sla_deadline`.
     *
     * `sla_deadline` dibiarkan NULL di seluruh sistem: menurunkannya dari `priority` butuh
     * angka jam per tingkat yang belum pernah disebut kantor, dan tenggat tebakan yang
     * tercetak salahnya senyap. `target_date` diketik orang yang menerbitkan WO, jadi
     * angkanya selalu ada dasarnya.
     *
     * Yang lewat target TIDAK diapa-apakan — tidak ada perubahan status, tidak ada eskalasi
     * otomatis. Sistem cuma menghitungnya, sama seperti masa tenggang surat keringanan.
     */
    private function overdue(): int
    {
        return DB::table('work_orders')
            ->whereIn('repair_status', WorkOrder::OPEN_STATUSES)
            ->whereNotNull('target_date')
            // Berjam sejak 1 Okt: lewat jam targetnya, bukan lewat tanggalnya.
            ->where('target_date', '<', Carbon::now())
            ->count();
    }
}
