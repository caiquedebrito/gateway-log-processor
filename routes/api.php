<?php

declare(strict_types=1);

use App\Http\Controllers\GatewayLogReportController;
use Illuminate\Support\Facades\Route;

Route::post('/gateway-log/reports', [GatewayLogReportController::class, 'store']);
Route::get('/gateway-log/reports/{reportExport}/download', [GatewayLogReportController::class, 'download']);
Route::get('/gateway-log/reports/{reportExport}', [GatewayLogReportController::class, 'show']);
