<?php

namespace App\Services\Shared;

use App\Exceptions\DomainException;
use App\Models\PatrolReport;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Sinkronisasi laporan patroli. Mirror IncidentReportSyncService::upsert(), jauh lebih
 * sederhana — tidak ada baris anak (culprit/vehicle/damaged_items), jadi tidak ada yang
 * dihapus-lalu-ditulis-ulang.
 */
class PatrolReportSyncService
{
    private const CLIENT_FIELDS = [
        'patrolled_at', 'route', 'is_damaged',
        'asset_catalog_id', 'location_type', 'km', 'm', 'lane', 'damage_description',
        'latitude', 'longitude',
    ];

    /**
     * Upsert satu laporan, idempoten terhadap uuid buatan HP — pola sama dengan
     * IncidentReportSyncService::upsert().
     */
    public function upsert(string $uuid, User $officer, array $data): PatrolReport
    {
        return DB::transaction(function () use ($uuid, $officer, $data) {
            $report = PatrolReport::lockForUpdate()->find($uuid);

            if ($report && ! $report->isEditable()) {
                throw new DomainException(
                    'Laporan sudah ditindaklanjuti Unit Maintenance dan tidak bisa diubah lagi.',
                    'patrol_report',
                );
            }

            return PatrolReport::updateOrCreate(['uuid' => $uuid], array_merge(
                collect($data)->only(self::CLIENT_FIELDS)->all(),
                [
                    'officer_id' => $report?->officer_id ?? $officer->id,
                    'sync_status' => 'sent',
                ],
            ));
        });
    }
}
