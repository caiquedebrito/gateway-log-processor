<?php

declare(strict_types=1);

use App\Http\Controllers\GatewayLogReportController;
use Illuminate\Support\Facades\Route;

Route::post('/gateway-log/reports', [GatewayLogReportController::class, 'store']);
