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
});

require __DIR__.'/settings.php';
