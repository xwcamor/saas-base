<?php

// Use Illuminates
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Hash;

// Localization
use Mcamara\LaravelLocalization\Facades\LaravelLocalization;

use App\Http\Controllers\AuthManagement\UserController;

Route::group(
    [
        'prefix' => LaravelLocalization::setLocale(),
        'middleware' => [ 'localeSessionRedirect','localizationRedirect','localeViewPath' ],
    ],
    function () {

        // Protected by auth
        Route::middleware(['auth'])->group(function () {

            // Main 
            Route::get('/', function () {
                return Auth::check()
                    ? redirect()->route('dashboard_management.dashboards.index')
                    : redirect()->route('login');
            });
            
            require __DIR__.'/system_management.php';
            require __DIR__.'/user_management.php';
            require __DIR__.'/business_management.php';
            require __DIR__.'/dashboard_management.php';
            require __DIR__.'/automation_management.php';
            require __DIR__.'/communication.php';
            require __DIR__.'/notifications.php';
            require __DIR__.'/saved_views.php';
            require __DIR__.'/user_preferences.php';

            // ── Mi Perfil ──
            Route::get('profile',           [\App\Http\Controllers\ProfileController::class, 'show'])->name('profile.show');
            Route::put('profile',           [\App\Http\Controllers\ProfileController::class, 'update'])->name('profile.update');
            Route::put('profile/password',  [\App\Http\Controllers\ProfileController::class, 'updatePassword'])->name('profile.update_password');
        });

        // Públic
        require __DIR__.'/auth_management.php';
        require __DIR__.'/legal_management.php';        
    }
);