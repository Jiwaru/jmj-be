<?php

namespace App\Models;

use Closure;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'identity_code',
        'name',
        'position',
        'entity',
        'role',
        'is_active',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'password' => 'hashed',
        ];
    }

    /**
     * Bekal identitas yang dibawa mobile saat luring: role + entity menentukan
     * layar dan isi unduhan offline. Satu-satunya bentuk user yang keluar dari API.
     */
    public function profile(): array
    {
        return $this->only(['id', 'identity_code', 'name', 'position', 'entity', 'role']);
    }

    /**
     * SATU-SATUNYA definisi "siapa yang boleh jadi pelaksana Work Order". Dibaca DUA
     * pemakai: validasi `assignee_ids` (UpdateWorkOrderTeamRequest::crewRules) dan
     * endpoint daftar petugas yang mengisi dropdown-nya
     * (MaintenanceDashboardService::officers).
     *
     * Ditulis dua kali, dropdown-nya cepat atau lambat berisi orang yang ditolak 422 saat
     * dipilih — dan gejalanya muncul di layar orang lain, bukan di test.
     *
     * SENGAJA Closure, bukan scope Eloquent, dan itu bukan selera: `Rule::exists()`
     * menyodorkan `Query\Builder` ke callback-nya (DatabasePresenceVerifier::addConditions),
     * sementara daftarnya dipanggil di atas `Eloquent\Builder`. Scope cuma hidup di yang
     * kedua. Klausa `where()` polos jalan di dua-duanya.
     *
     * Yang memperbaiki itu JMTM, dan di `users` mereka ber-role `maintenance_officer`.
     * Menugaskan petugas patroli JMTO ke pekerjaan perbaikan JMTM adalah penugasan lintas
     * badan hukum yang salah, dan salahnya tidak akan pernah kelihatan dari data — barisnya
     * sah, orangnya ada, cuma pekerjaannya bukan pekerjaan dia.
     *
     * ponytail: batasan role ini ASUMSI, bukan jawaban kantor. Mentok #3 (ada perbaikan oleh
     * vendor luar yang tidak punya akun?) masih terbuka; kalau jawabannya ada, INI baris yang
     * pertama harus dilonggarkan — dan cukup di sini.
     *
     * Catatan: yang disaring `role`, bukan `entity`. Seeder membuat keduanya sejalan, jadi
     * belum pernah berselisih; kalau suatu saat harus dikunci ke `entity = 'jmtm'` juga,
     * tempatnya tetap satu baris ini.
     */
    public static function petugasMaintenance(): Closure
    {
        return fn ($q) => $q->where('role', 'maintenance_officer')->where('is_active', true);
    }

    /**
     * SATU-SATUNYA definisi "siapa yang boleh jadi penanda tangan BA Kejadian".
     * Dibaca DUA pemakai, pola sama dengan `petugasMaintenance()` di atas:
     * validasi `officer_2_id` (SyncIncidentReportRequest) dan bekal luring
     * `data.ba_officers` (OfflineBundleService::forFieldOfficer).
     *
     * BEDA dari `entity = 'jmto'` yang dipakai bootstrap `officers`/`team`: MCS
     * Supervisor juga entity `jmto`, tapi SK cuma mengenal Petugas Layanan Jalan
     * Tol sebagai penanda tangan slot petugas — MCS menandatangani lewat aksi
     * Sahkan itu sendiri, bukan lewat slot ini.
     */
    public static function petugasLapangan(): Closure
    {
        return fn ($q) => $q->where('role', 'field_officer')->where('is_active', true);
    }

    public function incidentReports()
    {
        return $this->hasMany(IncidentReport::class);
    }

    public function catalogPrices()
    {
        return $this->hasMany(CatalogPrice::class, 'changed_by');
    }

    public function documents()
    {
        return $this->hasMany(Document::class, 'uploaded_by');
    }

    public function operationalTeams()
    {
        return $this->hasMany(OperationalTeam::class);
    }

    public function activityLogs()
    {
        return $this->hasMany(ActivityLog::class);
    }
}
