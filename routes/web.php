<?php

use App\Http\Controllers\PermissionController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\UserController;
use App\Models\Store;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => Inertia::render('Welcome', [
    'framework' => app()->version(),
]))->name('home');

Route::middleware(['auth', 'organization.access'])->group(function (): void {
    Route::get('/users', [UserController::class, 'index'])->middleware('permission:users.view')->name('users.index');
    Route::get('/users/create', [UserController::class, 'create'])->middleware('permission:users.create')->name('users.create');
    Route::post('/users', [UserController::class, 'store'])->middleware('permission:users.create')->name('users.store');
    Route::get('/users/{user}/edit', [UserController::class, 'edit'])->middleware('permission:users.update')->name('users.edit');
    Route::get('/users/{user}', [UserController::class, 'show'])->middleware('permission:users.view')->name('users.show');
    Route::put('/users/{user}', [UserController::class, 'update'])->middleware('permission:users.update')->name('users.update');
    Route::delete('/users/{user}', [UserController::class, 'destroy'])->middleware('permission:users.delete')->name('users.destroy');
    Route::put('/users/{user}/roles', [UserController::class, 'assignRoles'])->middleware('permission:users.assign_role')->name('users.roles.update');
    Route::put('/users/{user}/stores', [UserController::class, 'assignStores'])->middleware('permission:users.update')->name('users.stores.update');

    Route::get('/roles', [RoleController::class, 'index'])->middleware('permission:roles.view')->name('roles.index');
    Route::post('/roles', [RoleController::class, 'store'])->middleware('permission:roles.create')->name('roles.store');
    Route::get('/roles/{role}', [RoleController::class, 'show'])->middleware('permission:roles.view')->name('roles.show');
    Route::put('/roles/{role}', [RoleController::class, 'update'])->middleware('permission:roles.update')->name('roles.update');
    Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->middleware('permission:roles.delete')->name('roles.destroy');
    Route::put('/roles/{role}/permissions', [RoleController::class, 'updatePermissions'])->middleware('permission:roles.update')->name('roles.permissions.update');

    Route::get('/permissions', [PermissionController::class, 'index'])->middleware('permission:roles.view')->name('permissions.index');

    Route::get('/stores/{store}/access-check', fn (Store $store) => response()->json(['data' => ['id' => $store->id]]))
        ->middleware(['store.access', 'permission:store.view'])
        ->name('stores.access-check');
});
