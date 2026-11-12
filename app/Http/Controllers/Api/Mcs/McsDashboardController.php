<?php

namespace App\Http\Controllers\Api\Mcs;

use App\Http\Controllers\Controller;
use App\Services\Mcs\McsDashboardService;

class McsDashboardController extends Controller
{
    public function __construct(private readonly McsDashboardService $dashboard) {}

    /**
     * Empat kartu angka di layar Dashboard MCS Supervisor.
     */
    public function summary(): array
    {
        return $this->dashboard->summary();
    }
}
