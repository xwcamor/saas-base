<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\BusinessManagement\CustomerController;

/*
|--------------------------------------------------------------------------
| Business Management
|--------------------------------------------------------------------------
| Modulos de negocio del SaaS (no del core). Cada modulo se gobierna por
| permisos Spatie: customers.view, customers.create, etc. El admin del
| workspace asigna esos permisos a roles desde el modulo de Perfiles.
|
| Customers es el primer modulo real del SaaS, generado con make:module.
|
| ORDEN DE RUTAS CRITICO: las rutas con paths estaticos (customers/create,
| customers/trash, customers/export_*) DEBEN ir ANTES que customers/{customer}.
| Sin esto, Laravel hace route model binding con customer='create' y 404.
*/

Route::prefix('business_management')->name('business_management.')->group(function () {

    // ── Customers ──

    // 1) Trash + restore + force_delete (super only — defense in depth)
    Route::middleware('role:super')->group(function () {
        Route::get('customers/trash',                  [CustomerController::class, 'trash'])->name('customers.trash');
        Route::post('customers/bulk_restore',          [CustomerController::class, 'bulkRestore'])->name('customers.bulk_restore');
        Route::post('customers/{slug}/restore',        [CustomerController::class, 'restore'])->name('customers.restore');
        Route::get('customers/{slug}/restore',         fn () => redirect()->route('business_management.customers.trash'));
        Route::delete('customers/{slug}/force_delete', [CustomerController::class, 'forceDelete'])->name('customers.force_delete');
    });

    // 2) Exports (gated por plan_feature por formato)
    Route::middleware('permission:customers.view')->group(function () {
        Route::middleware(['throttle:5,1', 'plan_feature:export_excel'])
            ->post('customers/export_excel', [CustomerController::class, 'exportExcel'])->name('customers.export_excel');
        Route::middleware(['throttle:5,1', 'plan_feature:export_pdf'])
            ->post('customers/export_pdf',   [CustomerController::class, 'exportPdf'])->name('customers.export_pdf');
        Route::middleware(['throttle:5,1', 'plan_feature:export_word'])
            ->post('customers/export_word',  [CustomerController::class, 'exportWord'])->name('customers.export_word');
        Route::middleware('throttle:5,1') // export_csv libre en todos los planes
            ->post('customers/export_csv',   [CustomerController::class, 'exportCsv'])->name('customers.export_csv');
    });

    // 3) Imports (gated por plan_feature:bulk_operations)
    Route::middleware(['permission:customers.create', 'plan_feature:bulk_operations'])->group(function () {
        Route::post('customers/import',          [CustomerController::class, 'import'])->name('customers.import');
        Route::get('customers/import_template',  [CustomerController::class, 'importTemplate'])->name('customers.import_template');
    });

    // 4) Bulk operations (gated por plan_feature:bulk_operations)
    Route::middleware(['permission:customers.delete', 'plan_feature:bulk_operations', 'throttle:10,1'])->group(function () {
        Route::post('customers/bulk_delete',     [CustomerController::class, 'bulkDelete'])->name('customers.bulk_delete');
        Route::post('customers/bulk_set_active', [CustomerController::class, 'bulkSetActive'])->name('customers.bulk_set_active');
    });

    // Undo del ultimo borrado (60s window) — gated por permiso de delete.
    Route::middleware('permission:customers.delete')->group(function () {
        Route::post('customers/undo_last_delete', [CustomerController::class, 'undoLastDelete'])->name('customers.undo_last_delete');
    });

    // Edit All — batch edit de name + is_active (gated por permiso de edit).
    Route::middleware('permission:customers.edit')->group(function () {
        Route::get('customers/edit_all',         [CustomerController::class, 'editAll'])->name('customers.edit_all');
        Route::post('customers/edit_all/update', [CustomerController::class, 'editAllUpdate'])->name('customers.edit_all.update');
    });

    // 5) CRUD principal — paths estaticos PRIMERO (create), despues los con {customer}.
    Route::middleware('permission:customers.create')->group(function () {
        Route::get('customers/create', [CustomerController::class, 'create'])->name('customers.create');
        Route::post('customers',       [CustomerController::class, 'store'])->name('customers.store');
        Route::post('customers/{customer}/duplicate', [CustomerController::class, 'duplicate'])->name('customers.duplicate');
    });

    // Acciones con slug — DESPUES de los paths estaticos.
    Route::middleware('permission:customers.view')->group(function () {
        Route::get('customers',            [CustomerController::class, 'index'])->name('customers.index');
        Route::get('customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');
    });
    Route::middleware('permission:customers.edit')->group(function () {
        Route::get('customers/{customer}/edit', [CustomerController::class, 'edit'])->name('customers.edit');
        Route::put('customers/{customer}',      [CustomerController::class, 'update'])->name('customers.update');
    });
    Route::middleware('permission:customers.delete')->group(function () {
        Route::get('customers/{customer}/delete',        [CustomerController::class, 'delete'])->name('customers.delete');
        Route::delete('customers/{customer}/deleteSave', [CustomerController::class, 'deleteSave'])->name('customers.deleteSave');
    });
});
