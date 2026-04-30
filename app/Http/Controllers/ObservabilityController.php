<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\HealthService;
use App\Services\MetricsService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class ObservabilityController extends Controller
{
    public function __construct(
        public MetricsService $metricsService,
        public HealthService $healthService,
    ) {}

    public function metrics(): JsonResponse
    {
        return response()->json($this->metricsService->getMetrics());
    }

    
    public function health(): JsonResponse
    {
        $health = $this->healthService->check();
        $statusCode = $health['status'] === 'unhealthy'
            ? Response::HTTP_SERVICE_UNAVAILABLE
            : Response::HTTP_OK;

        return response()->json($health, $statusCode);
    }
}
