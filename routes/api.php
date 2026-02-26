<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\AssistantController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\ReservationController;
use App\Http\Controllers\DoctorScheduleController;
use App\Http\Controllers\FinancialController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\OpenFdaController;
use App\Http\Controllers\CheckInController;
use App\Http\Controllers\AdminController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

// Public routes
Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);

// Broadcasting auth route for Sanctum
Broadcast::routes(['middleware' => ['auth:sanctum']]);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    // Auth routes
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // Admin routes
    Route::middleware(['role:admin'])->group(function () {
        Route::get('/admin/doctors', [AdminController::class, 'indexDoctors']);
        Route::post('/admin/doctors', [AdminController::class, 'storeDoctor']);
        Route::get('/admin/doctors/{doctor}', [AdminController::class, 'showDoctor']);
        Route::put('/admin/doctors/{doctor}', [AdminController::class, 'updateDoctor']);
        Route::delete('/admin/doctors/{doctor}', [AdminController::class, 'destroyDoctor']);
        Route::get('/admin/subscription-stats', [AdminController::class, 'subscriptionStats']);
    });

    // Client routes - accessible by doctor and assistant
    Route::middleware(['role:doctor,assistant'])->group(function () {
        Route::apiResource('clients', ClientController::class);
    });

    // Reservation routes - doctors list (tenant-scoped)
    Route::middleware(['role:doctor,assistant'])->group(function () {
        Route::get('/doctors', [ReservationController::class, 'doctors']);
    });

    // Doctor schedule routes
    // Assistants can view doctor schedules to book appropriately
    Route::middleware(['role:assistant,doctor'])->group(function () {
        Route::get('/doctors/{doctor}/availability', [DoctorScheduleController::class, 'getAvailability']);
        Route::get('/doctors/{doctor}/holidays', [DoctorScheduleController::class, 'getHolidays']);
        Route::get('/doctors/{doctor}/available-times', [DoctorScheduleController::class, 'getAvailableTimes']);
    });

    // Doctors manage their own schedule and assistants
    Route::middleware(['role:doctor'])->group(function () {
        Route::put('/doctors/{doctor}/availability', [DoctorScheduleController::class, 'updateAvailability']);
        Route::post('/doctors/{doctor}/holidays', [DoctorScheduleController::class, 'addHoliday']);
        Route::delete('/doctors/{doctor}/holidays/{holiday}', [DoctorScheduleController::class, 'deleteHoliday']);

        // Assistant management
        Route::get('/assistants', [AssistantController::class, 'index']);
        Route::post('/assistants', [AssistantController::class, 'store']);
        Route::put('/assistants/{assistant}', [AssistantController::class, 'update']);
        Route::delete('/assistants/{assistant}', [AssistantController::class, 'destroy']);
    });
    
    // Assistant can create and confirm reservations
    Route::middleware(['role:assistant'])->group(function () {
        Route::post('/reservations', [ReservationController::class, 'store']);
        Route::post('/reservations/{reservation}/confirm', [ReservationController::class, 'confirm']);
    });

    // Doctor can complete reservations
    Route::middleware(['role:doctor'])->group(function () {
        Route::post('/reservations/{reservation}/complete', [ReservationController::class, 'complete']);
        Route::get('/reservations/{reservation}/prescription', [ReservationController::class, 'generatePrescription']);
    });

    // OpenFDA drug search (accessible by doctors)
    Route::middleware(['role:doctor'])->group(function () {
        Route::get('/openfda/drugs', [OpenFdaController::class, 'searchDrugs']);
        Route::get('/openfda/drug-details', [OpenFdaController::class, 'drugDetails']);

        // Egypt drug database
        Route::get('/egypt-drugs/search', [OpenFdaController::class, 'searchEgyptDrugs']);
        Route::get('/egypt-drugs/filters', [OpenFdaController::class, 'egyptDrugFilters']);
    });

    // Both doctor and assistant can view reservations
    // Check-in & Waiting Queue routes
    Route::middleware(['role:assistant'])->group(function () {
        Route::post('/reservations/{reservation}/check-in', [CheckInController::class, 'checkIn']);
        Route::post('/reservations/{reservation}/undo-check-in', [CheckInController::class, 'undoCheckIn']);
    });

    Route::middleware(['role:doctor,assistant'])->group(function () {
        Route::get('/waiting-queue', [CheckInController::class, 'waitingQueue']);
        Route::get('/waiting-queue/summary', [CheckInController::class, 'queueSummary']);
    });

    Route::middleware(['role:doctor,assistant'])->group(function () {
        Route::get('/reservations', [ReservationController::class, 'index']);
        Route::get('/reservations/{reservation}', [ReservationController::class, 'show']);
        Route::put('/reservations/{reservation}', [ReservationController::class, 'update']);
        Route::delete('/reservations/{reservation}', [ReservationController::class, 'destroy']);

        Route::get('/reports/summary', [ReportController::class, 'summary']);
        Route::get('/reports/pdf', [ReportController::class, 'exportPdf']);
    });

    // Financial routes
    Route::middleware(['role:doctor,assistant'])->group(function () {
        Route::get('/financials', [FinancialController::class, 'index']);
        Route::get('/financials/summary', [FinancialController::class, 'summary']);
        Route::get('/financials/{financial}', [FinancialController::class, 'show']);
    });

    Route::middleware(['role:assistant'])->group(function () {
        Route::post('/financials', [FinancialController::class, 'store']);
        Route::put('/financials/{financial}', [FinancialController::class, 'update']);
        Route::delete('/financials/{financial}', [FinancialController::class, 'destroy']);
    });
});
