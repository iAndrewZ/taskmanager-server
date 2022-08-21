<?php

use App\Http\Controllers\BoardController;
use App\Http\Controllers\StatusController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Auth;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::post("/register", [UserController::class, "register"]);
Route::post("/login", [UserController::class, "login"]);
Route::post("/verify-email", [UserController::class, "verifyEmail"]);


Route::middleware(['auth:sanctum'])->group(function () {
    Route::get("/user", [UserController::class, "getUser"]);
    Route::post("/create-board", [BoardController::class, "createBoard"]);


    Route::post("/create-status", [StatusController::class, "add"]);
    Route::post("/get-statuses", [StatusController::class, "getAll"]);
    Route::post("/get-status/{id}", [StatusController::class, "get"]);
    Route::post("/update-status/{id}", [StatusController::class, "update"]);
});
