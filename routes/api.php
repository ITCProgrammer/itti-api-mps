<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\MpsController;
use App\Http\Controllers\SchedulePlanController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/profile', function (Request $request) {return $request->user();});

    Route::get('/getMesin', [MpsController::class, 'mesin']);
    Route::get('/getMesinData', [MpsController::class, 'index']);
    Route::get('/getPoAndFor', [MpsController::class, 'loadPoAndFor']);
    Route::get('/getMesinByPo', [MpsController::class, 'loadMesinByPo']);
    Route::post('/postStatusMesin', [MpsController::class, 'loadStatusMesin']);
    Route::post('/postSchedule', [MpsController::class, 'saveScheduleMesin']);
    Route::post('/deleteSchedule', [MpsController::class, 'deleteScheduleMesin']);

    Route::prefix('schedule-plan')->name('schedule.plan.')->group(function () {
        Route::get('/', [SchedulePlanController::class, 'index'])->name('index');
        Route::post('/import', [SchedulePlanController::class, 'import'])->name('import');
        Route::get('/data-detail', [SchedulePlanController::class, 'dataDetail'])->name('data.detail');
    });
});