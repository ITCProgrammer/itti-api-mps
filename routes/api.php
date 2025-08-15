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
    Route::get('/getMesinByItemCode', [MpsController::class, 'mesinByItemCode']);
    Route::get('/getMesinData', [MpsController::class, 'index']);
    Route::get('/getPoAndFor', [MpsController::class, 'loadPoAndFor']);
    Route::get('/getMesinByPo', [MpsController::class, 'loadMesinByPo']);
    Route::get('/getSplittForecast', [MpsController::class, 'loadSplittForecast']);
    Route::post('/postStatusMesin', [MpsController::class, 'loadStatusMesin']);
    Route::post('/postSchedule', [MpsController::class, 'saveScheduleMesin']);
    Route::post('/postSplittForecast', [MpsController::class, 'saveSplittForecast']);
    Route::post('/postScheduleForcast', [MpsController::class, 'saveScheduleForcast']);
    Route::post('/deleteSchedule', [MpsController::class, 'deleteScheduleMesin']);

    Route::get('/getForcast', [MpsController::class, 'loadForcast']);
    Route::get('/getDetailForcast', [MpsController::class, 'loadDetailForcast']);
    Route::post('/getDetailStock', [MpsController::class, 'getStockDetail']);
    Route::get('/getDetailData/{item_code}', [MpsController::class, 'getDetailData']);
    Route::get('/getSearchData/{item_code}', [MpsController::class, 'getSearchData']);


    Route::prefix('schedule-plan')->name('schedule.plan.')->group(function () {
        Route::get('/', [SchedulePlanController::class, 'index'])->name('index');
        Route::post('/import', [SchedulePlanController::class, 'import'])->name('import');
        Route::get('/data-detail', [SchedulePlanController::class, 'dataDetail'])->name('data.detail');
    });
});