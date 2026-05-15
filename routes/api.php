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
use App\Http\Controllers\AssistantCallController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\ArchiveController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\SpecializationController;
use App\Http\Controllers\FeatureController;
use App\Http\Controllers\DoctorServiceController;
use App\Http\Controllers\ReservationServiceController;
use App\Http\Controllers\TransactionController;
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
    Route::get('/my-permissions', [AuthController::class, 'myPermissions']);

    // Admin routes
    Route::middleware(['role:admin'])->group(function () {
        Route::get('/admin/doctors', [AdminController::class, 'indexDoctors']);
        Route::post('/admin/doctors', [AdminController::class, 'storeDoctor']);
        Route::get('/admin/doctors/{doctor}', [AdminController::class, 'showDoctor']);
        Route::put('/admin/doctors/{doctor}', [AdminController::class, 'updateDoctor']);
        Route::delete('/admin/doctors/{doctor}', [AdminController::class, 'destroyDoctor']);
        Route::get('/admin/subscription-stats', [AdminController::class, 'subscriptionStats']);

        // Roles & Permissions management
        Route::apiResource('roles', RoleController::class);
        Route::get('/permissions', [PermissionController::class, 'index']);
        Route::post('/permissions', [PermissionController::class, 'store']);
        Route::delete('/permissions/{permission}', [PermissionController::class, 'destroy']);

        // Assign role to user
        Route::post('/users/{user}/assign-role', function (Request $request, \App\Models\User $user) {
            $request->validate(['role' => 'required|string|exists:roles,name']);
            $user->syncRoles([$request->role]);
            // Also update the legacy role column
            $user->update(['role' => $request->role]);
            return response()->json([
                'message' => 'Role assigned successfully.',
                'roles' => $user->getRoleNames(),
                'permissions' => $user->getAllPermissions()->pluck('name'),
            ]);
        });
    });

    // Client routes - accessible by doctor, assistant and sub-doctor
    Route::middleware(['role:doctor,assistant,sub-doctor'])->group(function () {
        Route::get('/clients/options', [ClientController::class, 'options']);
        Route::apiResource('clients', ClientController::class);
    });

    // Reservation routes - doctors list (tenant-scoped)
    Route::middleware(['role:doctor,assistant,sub-doctor'])->group(function () {
        Route::get('/doctors', [ReservationController::class, 'doctors']);
    });

    // Doctor schedule routes
    // Assistants and sub-doctors can view doctor schedules
    Route::middleware(['role:assistant,doctor,sub-doctor'])->group(function () {
        Route::get('/doctors/{doctor}/availability', [DoctorScheduleController::class, 'getAvailability']);
        Route::get('/doctors/{doctor}/holidays', [DoctorScheduleController::class, 'getHolidays']);
        Route::get('/doctors/{doctor}/available-times', [DoctorScheduleController::class, 'getAvailableTimes']);
    });

    // Doctors manage their own schedule and assistants
    Route::middleware(['role:doctor,sub-doctor'])->group(function () {
        Route::put('/doctors/{doctor}/availability', [DoctorScheduleController::class, 'updateAvailability']);
        Route::post('/doctors/{doctor}/holidays', [DoctorScheduleController::class, 'addHoliday']);
        Route::delete('/doctors/{doctor}/holidays/{holiday}', [DoctorScheduleController::class, 'deleteHoliday']);

        // Assistant management
        Route::get('/assistants', [AssistantController::class, 'index']);
        Route::post('/assistants', [AssistantController::class, 'store']);
        Route::put('/assistants/{assistant}', [AssistantController::class, 'update']);
        Route::delete('/assistants/{assistant}', [AssistantController::class, 'destroy']);

        // Sub-doctors management
        Route::get('/doctor/sub-doctors', [\App\Http\Controllers\SubDoctorController::class, 'index']);
        Route::post('/doctor/sub-doctors', [\App\Http\Controllers\SubDoctorController::class, 'store']);
        Route::put('/doctor/sub-doctors/{user}', [\App\Http\Controllers\SubDoctorController::class, 'update']);
        Route::delete('/doctor/sub-doctors/{user}', [\App\Http\Controllers\SubDoctorController::class, 'destroy']);
        // Permissions are managed by admin; no doctor-scoped permissions route
    });
    
    // Assistant and sub-doctor can create and confirm reservations
    Route::middleware(['role:assistant,sub-doctor'])->group(function () {
        Route::post('/reservations', [ReservationController::class, 'store']);
        Route::post('/reservations/{reservation}/confirm', [ReservationController::class, 'confirm']);
    });

    // Doctor and sub-doctor can complete reservations
    Route::middleware(['role:doctor,sub-doctor'])->group(function () {
        Route::post('/reservations/{reservation}/complete', [ReservationController::class, 'complete']);
    });

    // Doctor, assistant and sub-doctor can print prescriptions
    Route::middleware(['role:doctor,assistant,sub-doctor'])->group(function () {
        Route::get('/reservations/{reservation}/prescription', [ReservationController::class, 'generatePrescription']);
    });

    // OpenFDA drug search (accessible by doctors and sub-doctors)
    Route::middleware(['role:doctor,sub-doctor'])->group(function () {
        Route::get('/openfda/drugs', [OpenFdaController::class, 'searchDrugs']);
        Route::get('/openfda/drug-details', [OpenFdaController::class, 'drugDetails']);

        // Egypt drug database
        Route::get('/egypt-drugs/search', [OpenFdaController::class, 'searchEgyptDrugs']);
        Route::get('/egypt-drugs/filters', [OpenFdaController::class, 'egyptDrugFilters']);
    });

    // Both doctor and assistant can view reservations
    // Check-in & Waiting Queue routes
    // Assistant and sub-doctor can check in patients
    Route::middleware(['role:assistant,sub-doctor'])->group(function () {
        Route::post('/reservations/{reservation}/check-in', [CheckInController::class, 'checkIn']);
        Route::post('/reservations/{reservation}/undo-check-in', [CheckInController::class, 'undoCheckIn']);
    });

    Route::middleware(['role:doctor,assistant,sub-doctor'])->group(function () {
        Route::get('/waiting-queue', [CheckInController::class, 'waitingQueue']);
        Route::post('/waiting-queue/reorder', [CheckInController::class, 'reorderWaitingQueue']);
        Route::get('/waiting-queue/summary', [CheckInController::class, 'queueSummary']);
    });

    Route::middleware(['role:doctor,assistant,sub-doctor'])->group(function () {
        Route::get('/reservations', [ReservationController::class, 'index']);
        Route::get('/reservations/{reservation}', [ReservationController::class, 'show']);
        Route::put('/reservations/{reservation}', [ReservationController::class, 'update']);
        Route::delete('/reservations/{reservation}', [ReservationController::class, 'destroy']);

        Route::get('/reports/summary', [ReportController::class, 'summary']);
        Route::get('/reports/pdf', [ReportController::class, 'exportPdf']);
    });

    // Assistant Call routes
    Route::middleware(['role:doctor'])->group(function () {
        Route::post('/assistant-calls', [AssistantCallController::class, 'createCall']);
    });
    Route::middleware(['role:doctor,assistant,sub-doctor'])->group(function () {
        Route::get('/assistant-calls/active', [AssistantCallController::class, 'getActiveCalls']);
    });
    Route::middleware(['role:assistant'])->group(function () {
        Route::post('/assistant-calls/{call}/accept', [AssistantCallController::class, 'acceptCall']);
    });
    Route::middleware(['role:doctor,assistant,sub-doctor'])->group(function () {
        Route::post('/assistant-calls/{call}/complete', [AssistantCallController::class, 'completeCall']);
    });

    // Financial routes
    Route::middleware(['role:doctor,assistant,sub-doctor'])->group(function () {
        Route::get('/financials', [FinancialController::class, 'index']);
        Route::get('/financials/summary', [FinancialController::class, 'summary']);
        Route::get('/financials/{financial}', [FinancialController::class, 'show']);
        // Transactions for a financial record
        Route::get('/financials/{financial}/transactions', [TransactionController::class, 'index']);
        Route::post('/financials/{financial}/transactions', [TransactionController::class, 'store']);
        Route::post('/financials/{financial}/transactions/batch', [TransactionController::class, 'storeBatch']);
        Route::delete('/financials/{financial}/transactions/{transaction}', [TransactionController::class, 'destroy']);
        // All transactions for the doctor
        Route::get('/transactions', [TransactionController::class, 'allForDoctor']);
        // Purchases (view)
            Route::get('/purchases', [PurchaseController::class, 'index']);
            Route::get('/purchases/categories', [PurchaseController::class, 'categories']);
        // unified stats endpoint (accepts GET and POST payloads)
        Route::get('/purchases/stats', [PurchaseController::class, 'stats']);
        Route::post('/purchases/stats', [PurchaseController::class, 'stats']);
        Route::get('/purchases/{purchase}', [PurchaseController::class, 'show']);
    });

    Route::middleware(['role:assistant'])->group(function () {
        Route::post('/financials', [FinancialController::class, 'store']);
        Route::put('/financials/{financial}', [FinancialController::class, 'update']);
        Route::delete('/financials/{financial}', [FinancialController::class, 'destroy']);
        // Purchases (create/update/delete)
        Route::post('/purchases', [PurchaseController::class, 'store']);
        Route::put('/purchases/{purchase}', [PurchaseController::class, 'update']);
        Route::delete('/purchases/{purchase}', [PurchaseController::class, 'destroy']);
    });

    // Archive / file system routes
    Route::middleware(['role:admin,doctor,assistant,sub-doctor'])->group(function () {
        Route::post('/archive', [ArchiveController::class, 'archives']);
        Route::get('/archive/{archive}', [ArchiveController::class, 'show']);
        Route::put('/archive/{archive?}', [ArchiveController::class, 'put']);
        Route::delete('/archive/{archive}', [ArchiveController::class, 'destroy']);
        Route::post('/archive/upload/{archive?}', [ArchiveController::class, 'upload']);
        Route::post('/archive/update/{archive}', [ArchiveController::class, 'update']);
    });

    // Specializations & Features (all authenticated)
    Route::get('/specializations', [SpecializationController::class, 'index']);
    Route::get('/features', [FeatureController::class, 'index']);

    // Doctor Services catalog — only doctor & sub-doctor can manage their own catalog
    Route::middleware(['role:doctor,sub-doctor'])->group(function () {
        Route::get('/doctor-services', [DoctorServiceController::class, 'index']);
        Route::post('/doctor-services', [DoctorServiceController::class, 'store']);
        Route::put('/doctor-services/{doctorService}', [DoctorServiceController::class, 'update']);
        Route::delete('/doctor-services/{doctorService}', [DoctorServiceController::class, 'destroy']);
    });

    // Services on a specific reservation — doctor, sub-doctor & assistant (permission-protected)
    Route::middleware(['role:doctor,assistant,sub-doctor'])->group(function () {
        Route::get('/reservations/{reservation}/services', [ReservationServiceController::class, 'index'])
            ->middleware('permission:reservation-services.view');
        Route::post('/reservations/{reservation}/services', [ReservationServiceController::class, 'store'])
            ->middleware('permission:reservation-services.create');
        Route::put('/reservations/{reservation}/services/{reservationService}', [ReservationServiceController::class, 'update'])
            ->middleware('permission:reservation-services.edit');
        Route::delete('/reservations/{reservation}/services/{reservationService}', [ReservationServiceController::class, 'destroy'])
            ->middleware('permission:reservation-services.delete');
    });
});

Route::middleware('auth:sanctum')->get('/archive/download/{archive}/{nocache?}', [ArchiveController::class, 'download'])
    ->name('archive.download');
Route::middleware('auth:sanctum')->get('/archive/preview/{archive}', [ArchiveController::class, 'preview'])
    ->name('archive.preview');
