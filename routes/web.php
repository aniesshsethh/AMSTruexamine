<?php

use App\Http\Controllers\TruexamineReportController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::inertia('/', 'welcome', [
    'canRegister' => Features::enabled(Features::registration()),
])->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');

    Route::get('truexamine-report', [TruexamineReportController::class, 'create'])
        ->name('truexamine-report.create');
    Route::post('truexamine-report', [TruexamineReportController::class, 'store'])
        ->middleware('throttle:8,1')
        ->name('truexamine-report.store');
    Route::get('truexamine-report/download', [TruexamineReportController::class, 'download'])
        ->middleware('throttle:30,1')
        ->name('truexamine-report.download');
    Route::get('truexamine-report/test-pdf', [TruexamineReportController::class, 'testPdf'])
        ->middleware('throttle:30,1')
        ->name('truexamine-report.test-pdf');
});

require __DIR__.'/settings.php';
