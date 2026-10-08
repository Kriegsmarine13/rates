<?php

namespace App\Http\Controllers;

use App\Http\Requests\RateRequest;
use App\Services\RatesService;
use Illuminate\Http\JsonResponse;

class RatesController extends Controller
{
    public function __construct(
        public RatesService $service
    )
    {
    }

    public function rate(RateRequest $request): JsonResponse
    {
        $params = $request->validated();

        return response()
            ->json(
                $this->service->getRates(
                    $params['target'],
                    $params['date'],
                    $params['base']
                )
            );
    }
}
