<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AnalysisController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// URL expansion for Amazon short URLs
Route::post('/expand-url', [App\Http\Controllers\UrlExpansionController::class, 'expandUrl']);

// Async analysis endpoints - require session and CSRF protection
Route::prefix('analysis')->name('api.analysis.')
    ->middleware(['web']) // Use web middleware for session support
    ->group(function () {
        Route::post('/start', [AnalysisController::class, 'startAnalysis'])->name('start');
        Route::get('/progress/{sessionId}', [AnalysisController::class, 'getProgress'])->name('progress');
        Route::delete('/cancel/{sessionId}', [AnalysisController::class, 'cancelAnalysis'])->name('cancel');
        Route::post('/cleanup', [AnalysisController::class, 'cleanup'])->name('cleanup');
    });


