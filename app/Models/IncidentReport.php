<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use App\Support\Shift;

class IncidentReport extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'incident_report';

    protected $primaryKey = 'uuid';

    /**
     * Status yang MUNGKIN masih boleh diubah. Bukan jawaban akhirnya — `isEditable()`
     * yang menutup celahnya. Ditaruh di model karena dua service memakainya.
     */
    public const EDITABLE_STATUSES = ['new', 'pending_verification', 'rejected'];

    protected $fillable = [
        // uuid WAJIB fillable: HP yang membuatnya, dan itulah kunci idempotensi
        // sinkronisasi. Tanpa ini fill() membuangnya diam-diam, HasUuids membuatkan
        // uuid baru, dan tiap kiriman ulang melahirkan laporan kedua.
        'uuid',
        'report_number',
        'user_id',
        'officer_2_id',
        'date_time',
        'km',
        'm',
        'lane',
        'route',
        'location_type',
        'weather_condition',
        'causer',
        'causer_other',
        'incident_explanation',
        'action',
        'analyze',
        'conclusion',
        'is_asset_broken',
        'is_identified',
        'additional_cost',
        'total_estimation',
        'settlement_type',
        'relief_requested',
        'pledged_amount',
        'sync_status',
        'report_status',
        'latitude',
        'longitude',
    ];

    protected function casts(): array
    {
        return [
            'date_time' => 'datetime',
            // Tanpa cast ini `action` keluar sebagai string JSON mentah dan FE
            // dapat "[\"Koordinasi Unit Derek\"]", bukan array.
            'action' => 'array',
            'is_asset_broken' => 'boolean',
            'is_identified' => 'boolean',
            'relief_requested' => 'boolean',
            'additional_cost' => 'decimal:2',
            'total_estimation' => 'decimal:2',
            'pledged_amount' => 'decimal:2',
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    /**
     * Batas "laporan siapa yang boleh dilihat", per role.
     *
     * Di model, bukan di controller: KONVENSI melarang `if` bisnis di controller, dan
     * `index()` memang belum punya service sendiri. Ini fakta milik satu tabel.
     *
     * ALLOWLIST, bukan denylist (keputusan 31 Ags). Role yang tidak disebut di sini
     * tidak melihat apa pun — bukan melihat semuanya. Waktu role ke-5 (monitoring JMJ)
     * ditambahkan, dia harus disebut di sini dengan sengaja, bukan mewarisi akses
     * penuh di hari pertama karena kebetulan tidak cocok dengan cabang `field_officer`.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return match ($user->role) {
            'field_officer' => $query->where('user_id', $user->id),
            'mcs_supervisor' => $query,
            default => $query->whereRaw('false'),
        };
    }

    /**
     * "Antrean BA Kejadian" — menunggu verifikasi, BA Kejadian belum disahkan. Termasuk
     * yang barisnya belum ada (paling sering) dan yang pernah ditolak lalu dikirim ulang.
     *
     * SATU-SATUNYA definisi antrean: dipakai badge di mcs/summary DAN daftar
     * `?queue=awaiting_bak`. Dua definisi = badge dan isi tab yang bisa beda angka.
     */
    public function scopeAwaitingIncidentBa(Builder $query): Builder
    {
        return $query->where('report_status', 'pending_verification')
            ->whereDoesntHave('incidentBa', fn ($ba) => $ba->where('validation_status', 'validated'));
    }

    /**
     * "Antrean BA Kerusakan" — BA Kejadian sudah disahkan, ada kerusakan aset, BA
     * Kerusakan belum. Pasangan `?queue=awaiting_bar`, alasannya sama dengan di atas.
     *
     * Sengaja TIDAK menyaring report_status: BA Kerusakan yang ditolak membuat laporan
     * `rejected`, dan MCS tetap perlu melihatnya di antrean ini setelah petugas
     * memperbaiki daftar kerusakannya.
     */
    public function scopeAwaitingDamageBa(Builder $query): Builder
    {
        return $query->where('is_asset_broken', true)
            ->whereHas('incidentBa', fn ($ba) => $ba->where('validation_status', 'validated')
                ->whereDoesntHave('damageBa', fn ($bar) => $bar->where('validation_status', 'validated')));
    }

    /**
     * "Dokumen Tertunda (Masa Tenggang)" — mengajukan keringanan, BA Kerusakan sudah
     * disahkan, surat permohonan bermaterainya belum masuk. Pasangan
     * `?queue=awaiting_relief_letter` dan badge `pending_relief_letter`, alasannya sama
     * dengan dua scope di atas.
     *
     * Yang lewat tenggat TETAP di sini: sistem cuma memantau (Mentok #15), jadi daftar
     * ini justru tempat yang terlambat paling perlu terlihat.
     *
     * whereNotExists + cast `::text`, bukan whereDoesntHave('documents'):
     * documents.documentable_id itu varchar(36) dan Postgres menolak membandingkan
     * kolom uuid dengan kolom varchar (SQLSTATE 42883).
     */
    public function scopeAwaitingReliefLetter(Builder $query): Builder
    {
        return $query->where('relief_requested', true)
            ->whereHas('incidentBa.damageBa', fn ($bar) => $bar->where('validation_status', 'validated'))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('documents as d')
                ->where('d.documentable_type', 'incident_report')
                ->whereRaw('d.documentable_id = incident_report.uuid::text')
                ->where('d.document_type', 'relief_letter'));
    }

    /**
     * Seksi jalan tol tempat kejadian, untuk slot "Jalan Tol Seksi ......" di BA
     * Kerusakan. DITURUNKAN dari `km`, bukan kolom — seksi itu fungsi dari KM, dan
     * kolom sendiri cuma membuka kemungkinan seksi melenceng dari km di baris yang sama.
     *
     * Yang dikembalikan ANGKA-nya saja ("1"), bukan "Seksi 1": kata "Seksi" sudah
     * tercetak di formulirnya. Pemakai lain — kalau nanti ada — yang menambahkan
     * kata itu sendiri.
     *
     * Mengembalikan null kalau km kosong atau jatuh di luar semua rentang, dan slotnya
     * lalu tercetak KOSONG. Sengaja tidak dibulatkan ke seksi terdekat: seksi yang
     * salah cetak di dokumen tagihan gagalnya senyap — baru ketahuan waktu dicocokkan
     * dengan kertas berbulan-bulan kemudian. Slot kosong ketahuan hari itu juga.
     */
    public function section(): ?string
    {
        if ($this->km === null) {
            return null;
        }

        foreach (config('toll.sections') as $section) {
            if ($this->km >= $section['from_km'] && $this->km < $section['to_km']) {
                return $section['name'];
            }
        }

        return null;
    }

    /**
     * Shift saat kejadian, untuk slot "( shift )" di BA Kejadian. DITURUNKAN dari
     * `date_time` lewat config('toll.shifts'), bukan diketik MCS dan bukan kolom.
     *
     * Alasannya sama dengan section(): shift itu fungsi dari jam, jadi menyimpannya
     * atau meminta orang mengetiknya cuma membuka kemungkinan shift melenceng dari
     * jam kejadian di baris yang sama. Yang mengetiknya MCS pula — orang yang tidak
     * ada di TKP dan membaca laporannya berjam-jam kemudian.
     *
     * Mengembalikan '1' | '2' | '3' (CHECK di migration cuma menerima itu), atau null
     * kalau jamnya tidak jatuh di rentang mana pun — slotnya lalu tercetak
     * "I /II/ III" seperti kertasnya, dilingkari tangan.
     */
    public function shift(): ?string
    {
        // Perhitungannya di App\Support\Shift sejak Fase 5, karena work_order_logs
        // menurunkan shift-nya dengan cara yang sama persis. Logika shift malam yang
        // melewati tengah malam adalah hal yang paling gampang disalin salah.
        return Shift::at($this->date_time);
    }

    /**
     * Isi slot "/ Ramp ......" di BA Kejadian dan "/ on/off ramp ......" di BA
     * Kerusakan. DITURUNKAN dari `location_type`, bukan kolom sendiri.
     *
     * Bukan sekadar menyalin nilainya: kata "Ramp" sudah tercetak di kertasnya, jadi
     * 'Ramp Masuk' yang dicetak utuh berbunyi "Ramp Ramp Masuk". Petanya di
     * config('toll.ba_location_slot'); yang tidak terdaftar dicetak apa adanya,
     * supaya nilai baru di config/enums.php tidak diam-diam hilang dari dokumen.
     *
     * 'Jalur Utama' mengembalikan string kosong dan slotnya tercetak titik-titik —
     * sama seperti kertas yang diisi tangan sekarang.
     */
    public function locationSlot(): string
    {
        return config('toll.ba_location_slot')[$this->location_type] ?? (string) $this->location_type;
    }

    /**
     * Isi slot "jalur ......" di kedua Berita Acara: lajur dan arahnya.
     *
     * Kata "Lajur" DIBUANG dari nilai enum-nya karena kata "jalur" sudah tercetak di
     * formulirnya — 'A' + 'Lajur 2' yang disambung apa adanya berbunyi "jalur A Lajur
     * 2", satu kata yang sama dua kali dalam empat kata.
     *
     * Dirangkai "2 arah A", BUKAN "A / 2": slot berikutnya di formulirnya sudah dipisah
     * garis miring ("jalur ...... / Ramp ......"), jadi garis miring kedua di dalam
     * slotnya bikin satu baris punya tiga pemisah yang artinya berbeda-beda.
     *
     * 'Bahu Jalan' tidak berawalan "Lajur", jadi lewat utuh: "Bahu Jalan arah A".
     * Salah satunya kosong tetap terbaca: tanpa route jadi "2", tanpa lane jadi "A".
     */
    public function laneSlot(): string
    {
        $lajur = preg_replace('/^Lajur\s+/', '', (string) $this->lane);

        if ($lajur === '') {
            return (string) $this->route;
        }

        return $this->route ? "{$lajur} arah {$this->route}" : $lajur;
    }

    /**
     * 'on' | 'off' | null — mana dari "on/off ramp" yang ditebalkan di BA Kerusakan.
     *
     * null untuk yang bukan ramp: tidak ada yang ditebalkan, dan slot titik-titiknya
     * yang dipakai (lihat locationSlot()). Gerbang tol bukan "on" dan bukan "off".
     */
    public function rampDirection(): ?string
    {
        return config('toll.ba_ramp_direction')[$this->location_type] ?? null;
    }

    /**
     * Boleh dikirim ulang petugas, dan dokumennya boleh dihapus.
     *
     * STATUS SAJA TIDAK CUKUP, dan itu celah yang sempat terbuka: laporan yang ada
     * kerusakan asetnya TETAP `pending_verification` setelah BA Kejadian disahkan
     * (Alur 2.1) — dia masih menunggu BA Kerusakan. Selama yang dibaca cuma status,
     * petugas bisa menulis ulang km, causer, dan analyze setelah BA Kejadian dibekukan
     * ke PDF. Akibatnya dua: BA Kerusakan menyebut lokasi yang berbeda dari BA
     * Kejadian untuk satu perkara, dan `analyze` yang dibalik jadi "Tidak Ada
     * Kerusakan Aset" membuat laporan mentok — keempat aksi MCS menolaknya dan
     * laporannya hilang dari kedua antrean sambil tetap `pending_verification`.
     *
     * `rejected` DIKECUALIKAN dengan sengaja: itu jalur rework BA Kerusakan, dan di
     * situ BA Kejadian memang sudah validated. Menutupnya juga berarti laporan yang
     * BA Kerusakannya ditolak tidak pernah bisa diperbaiki siapa pun.
     */
    public function isEditable(): bool
    {
        if (! in_array($this->report_status, self::EDITABLE_STATUSES, true)) {
            return false;
        }

        return $this->report_status === 'rejected' || ! $this->incidentBaValidated();
    }

    /**
     * BA Kejadian sudah dibekukan? Dipakai dua penjaga yang berbeda — kiriman ulang
     * petugas di sini, dan koreksi lokasi oleh MCS di IncidentVerificationService.
     *
     * Query, bukan relasi yang dimuat: pemanggilnya memegang baris yang baru saja
     * di-`lockForUpdate()`, dan `load()` di atasnya akan ikut membawa relasi itu ke
     * response yang dikembalikan controller.
     */
    public function incidentBaValidated(): bool
    {
        return $this->incidentBa()->where('validation_status', 'validated')->exists();
    }

    public function reporter()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Penanda tangan 2 BA Kejadian — ditunjuk pelapor sendiri saat submit (redesain
     * 16 Sep). Penanda tangan 1 tidak punya relasi sendiri, itu `reporter()` di atas.
     */
    public function officer2()
    {
        return $this->belongsTo(User::class, 'officer_2_id');
    }

    public function incidentBa()
    {
        return $this->hasOne(IncidentBa::class, 'incident_report_id', 'uuid');
    }

    public function operationalTeam()
    {
        return $this->hasMany(OperationalTeam::class, 'incident_report_id', 'uuid');
    }

    public function involvedVehicles()
    {
        return $this->hasMany(InvolvedVehicle::class, 'incident_report_id', 'uuid');
    }

    /**
     * orderBy('id') WAJIB, bukan kerapian. Formulir BA Kerusakan cuma punya SATU blok
     * PIHAK KESATU, jadi yang tercetak `culprits->first()` — dan tanpa urutan yang
     * dinyatakan, "pertama" itu apa pun yang PostgreSQL kebetulan kembalikan. Biasanya
     * urutan insert, tapi bukan jaminan: seq scan boleh berubah urutan setelah UPDATE
     * atau VACUUM. Di dokumen yang dipakai menagih, itu artinya orang yang salah
     * tercetak sebagai pihak yang bertanggung jawab.
     */
    public function culprits()
    {
        return $this->hasMany(Culprit::class, 'incident_report_id', 'uuid')->orderBy('id');
    }

    public function damagedItems()
    {
        return $this->hasMany(DamagedItem::class, 'incident_report_id', 'uuid');
    }

    /**
     * Semua unggahan lapangan nempel ke uuid laporan ini — foto TKP, KTP, kendaraan,
     * tanda tangan, surat pernyataan. Lihat DocumentStorageService untuk alasannya.
     */
    public function documents()
    {
        return $this->morphMany(Document::class, 'documentable', 'documentable_type', 'documentable_id', 'uuid');
    }

    public function workOrders()
    {
        return $this->hasMany(WorkOrder::class, 'incident_report_id', 'uuid');
    }
}
