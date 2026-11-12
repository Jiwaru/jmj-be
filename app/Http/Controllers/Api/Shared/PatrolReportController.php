<?php

namespace App\Http\Controllers\Api\Shared;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\SyncPatrolReportRequest;
use App\Http\Requests\Api\UploadPatrolDocumentRequest;
use App\Models\PatrolReport;
use App\Services\Shared\DocumentStorageService;
use App\Services\Shared\PatrolReportSyncService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PatrolReportController extends Controller
{
    public function __construct(
        private readonly PatrolReportSyncService $sync,
        private readonly DocumentStorageService $documents,
    ) {}

    public function index(Request $request): LengthAwarePaginator
    {
        return PatrolReport::visibleTo($request->user())
            ->with('officer:id,identity_code,name')
            ->latest('patrolled_at')
            ->paginate(20);
    }

    public function show(Request $request, string $uuid): array
    {
        $report = PatrolReport::findOrFail($uuid);
        Gate::authorize('view', $report);

        $report->load(['officer:id,identity_code,name', 'assetCatalog.assetType', 'documents']);

        return ['report' => $report];
    }

    public function sync(SyncPatrolReportRequest $request, string $uuid): array
    {
        // Laporan baru boleh dibuat siapa pun ber-role field_officer; yang sudah ada
        // cuma boleh disentuh pemiliknya. Pola sama dengan IncidentReportController::sync().
        if ($report = PatrolReport::find($uuid)) {
            Gate::authorize('update', $report);
        }

        return ['report' => $this->sync->upsert($uuid, $request->user(), $request->validated())];
    }

    public function uploadDocument(UploadPatrolDocumentRequest $request, string $uuid): array
    {
        $report = PatrolReport::findOrFail($uuid);
        Gate::authorize('update', $report);

        return [
            'document' => $this->documents->storeForPatrol(
                $report,
                $request->file('file'),
                $request->validated('file_uuid'),
                $request->user(),
            ),
        ];
    }
}
