<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use App\Http\Controllers\AgentController;
use App\Http\Controllers\PromoterController;
use App\Http\Controllers\PolicyController;
use App\Http\Controllers\SchemeController;

Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', function () {
        return Inertia::render('Dashboard/Index');
    })->name('dashboard');

    // Ruta unificada para el módulo de Directorio (Promotores y Agentes)
    Route::get('directorio', [PromoterController::class, 'index'])->name('directorio');

    // Cambiados de apiResource a resource para permitir el retorno de vistas con Inertia
    Route::resource('promoters', PromoterController::class);
    Route::resource('agents', AgentController::class);
    Route::resource('policies', PolicyController::class);
    Route::resource('schemes', SchemeController::class);

    // Módulo de Esquemas
    Route::prefix('esquemas')->name('esquemas.')->group(function () {
        
        // Comisiones (Index por defecto)
        Route::get('/', [SchemeController::class, 'index'])->name('index');
        Route::get('/comisiones/crear', [SchemeController::class, 'createCommission'])->name('comisiones.crear');
        Route::get('/comisiones/{scheme}', [SchemeController::class, 'show'])->name('comisiones.show');

        // Bonos
        Route::get('/bonos', [SchemeController::class, 'bonuses'])->name('bonos');
        Route::get('/bonos/crear', [SchemeController::class, 'createBonus'])->name('bonos.crear');
        
    });

});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';