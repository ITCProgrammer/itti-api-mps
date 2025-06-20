<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\MpsController;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/profile', function (Request $request) { return $request->user(); });

    Route::get('/getMesin', [MpsController::class, 'mesin']);
    Route::get('/getMesinData', [MpsController::class, 'index']);
    Route::get('/getPoAndFor', [MpsController::class, 'loadPoAndFor']);
    Route::get('/getMesinByPo', [MpsController::class, 'loadMesinByPo']);
    Route::post('/postSchedule', [MpsController::class, 'saveScheduleMesin']);

});
