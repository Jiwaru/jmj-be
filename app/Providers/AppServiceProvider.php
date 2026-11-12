<?php

namespace App\Providers;

use App\Http\Middleware\ApiEnvelope;
use App\Models\AssetCatalog;
use App\Models\AssetType;
use App\Models\IncidentReport;
use App\Models\PatrolReport;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderLog;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\Operation;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // documents.documentable_type dan activity_logs.auditable_type menyimpan
        // nama tabel pendek, bukan FQCN — itu yang tertulis di dokumen alur, dan
        // kelas jadi boleh dipindah/rename tanpa mematahkan data lama.
        // Harus terpasang sebelum baris polymorphic pertama ditulis.
        //
        // enforceMorphMap (bukan morphMap) sengaja: model yang ikut relasi morph
        // tapi lupa didaftarkan langsung melempar ClassMorphViolationException,
        // bukan diam-diam menulis FQCN.
        //
        // 'users' sengaja sudah didaftarkan meski belum ada yang memakainya:
        // begitu Sanctum dipasang, personal_access_tokens.tokenable ikut morph
        // dan tanpa entri ini endpoint login mati dengan 500. Sudah kejadian.
        Relation::enforceMorphMap([
            'users' => User::class,
            'incident_report' => IncidentReport::class,
            'patrol_report' => PatrolReport::class,
            'work_orders' => WorkOrder::class,
            'work_order_logs' => WorkOrderLog::class,
            'asset_catalog' => AssetCatalog::class,
            'asset_types' => AssetType::class,
        ]);

        // Scramble berhenti di array polos yang dikembalikan controller; envelope
        // dipasang middleware sesudahnya, jadi generator tidak melihatnya sama
        // sekali. Satu baris ini yang membuat /docs/api menjanjikan bentuk yang
        // benar-benar keluar. Aturannya di ApiEnvelope::describe(), sekelas dengan
        // wrap() supaya keduanya tidak bisa melenceng diam-diam.
        //
        // Transformer BELAKANGAN dijalankan paling akhir, sesudah Scramble selesai
        // menyusun seluruh response — termasuk 401/403/404/422 dari exception.
        Scramble::configure()->withOperationTransformers(
            fn (Operation $operation) => ApiEnvelope::describe($operation)
        );
    }
}
