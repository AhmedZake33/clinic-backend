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
use App\Http\Controllers\PrintSettingsController;
use App\Http\Controllers\DoctorDiagnosisController;
use App\Http\Controllers\DoctorServiceController;
use App\Http\Controllers\ReservationServiceController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\WhatsAppTestController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

// Public routes
Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);

// Broadcasting auth route for Sanctum
Broadcast::routes(['middleware' => ['auth:sanctum']]);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    // Auth routes
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/my-permissions', [AuthController::class, 'myPermissions']);
    Route::post('/change-password', [AuthController::class, 'changePassword']);

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
        Route::get('/clients', [ClientController::class, 'index'])->middleware('clinic.permission:assistant.view-clients');
        Route::post('/clients', [ClientController::class, 'store'])->middleware('clinic.permission:assistant.create-clients');
        Route::get('/clients/{client}', [ClientController::class, 'show'])->middleware('clinic.permission:assistant.view-clients');
        Route::put('/clients/{client}', [ClientController::class, 'update'])->middleware('clinic.permission:assistant.edit-clients');
        Route::delete('/clients/{client}', [ClientController::class, 'destroy'])->middleware('clinic.permission:assistant.delete-clients');
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
        Route::middleware(['role:doctor'])->group(function () {
            Route::get('/assistants', [AssistantController::class, 'index']);
            Route::get('/assistants/permissions', [AssistantController::class, 'permissions']);
            Route::post('/assistants', [AssistantController::class, 'store']);
            Route::put('/assistants/{assistant}', [AssistantController::class, 'update']);
            Route::delete('/assistants/{assistant}', [AssistantController::class, 'destroy']);
        });

        // Sub-doctors management
        Route::get('/doctor/sub-doctors', [\App\Http\Controllers\SubDoctorController::class, 'index']);
        Route::post('/doctor/sub-doctors', [\App\Http\Controllers\SubDoctorController::class, 'store']);
        Route::put('/doctor/sub-doctors/{user}', [\App\Http\Controllers\SubDoctorController::class, 'update']);
        Route::delete('/doctor/sub-doctors/{user}', [\App\Http\Controllers\SubDoctorController::class, 'destroy']);
        // Permissions are managed by admin; no doctor-scoped permissions route
    });
    
    // Doctor, assistant and sub-doctor can create reservations
    Route::middleware(['role:doctor,assistant,sub-doctor'])->group(function () {
        Route::post('/reservations', [ReservationController::class, 'store'])
            ->middleware('clinic.permission:assistant.create-reservations');
    });

    // Assistant and sub-doctor can confirm reservations
    Route::middleware(['role:assistant,sub-doctor'])->group(function () {
        Route::post('/reservations/{reservation}/confirm', [ReservationController::class, 'confirm'])
            ->middleware('clinic.permission:assistant.confirm-reservations');
    });

    // Doctor, assistant and sub-doctor can complete reservations
    Route::middleware(['role:doctor,assistant,sub-doctor'])->group(function () {
        Route::post('/reservations/{reservation}/complete', [ReservationController::class, 'complete'])
            ->middleware('clinic.permission:assistant.complete-reservations');
    });

    // Doctor, assistant and sub-doctor can print prescriptions
    Route::middleware(['role:doctor,assistant,sub-doctor'])->group(function () {
        Route::get('/reservations/{reservation}/prescription', [ReservationController::class, 'generatePrescription']);
        Route::get('/reservations/{reservation}/medicines-prescription', [ReservationController::class, 'generateMedicinesPrescription']);
        Route::get('/reservations/{reservation}/details-pdf', [ReservationController::class, 'generateReservationDetailsPdf']);
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
        Route::post('/reservations/{reservation}/check-in', [CheckInController::class, 'checkIn'])
            ->middleware('clinic.permission:assistant.check-in-patients');
        Route::post('/reservations/{reservation}/undo-check-in', [CheckInController::class, 'undoCheckIn'])
            ->middleware('clinic.permission:assistant.check-in-patients');
    });

    Route::middleware(['role:doctor,assistant,sub-doctor'])->group(function () {
        Route::get('/waiting-queue', [CheckInController::class, 'waitingQueue'])
            ->middleware('clinic.permission:assistant.view-waiting-queue');
        Route::post('/waiting-queue/reorder', [CheckInController::class, 'reorderWaitingQueue'])
            ->middleware('clinic.permission:assistant.check-in-patients');
        Route::get('/waiting-queue/summary', [CheckInController::class, 'queueSummary'])
            ->middleware('clinic.permission:assistant.view-waiting-queue');
        Route::post('/whatsapp/test-message', [WhatsAppTestController::class, 'send']);
        Route::post('/whatsapp/test-image', [WhatsAppTestController::class, 'sendImage']);
    });

    Route::middleware(['role:doctor,assistant,sub-doctor'])->group(function () {
        Route::get('/reservations', [ReservationController::class, 'index'])
            ->middleware('clinic.permission:assistant.view-reservations');
        Route::get('/reservations/{reservation}', [ReservationController::class, 'show'])
            ->middleware('clinic.permission:assistant.view-reservations');
        Route::put('/reservations/{reservation}', [ReservationController::class, 'update'])
            ->middleware('clinic.permission:assistant.edit-reservations');
        Route::delete('/reservations/{reservation}', [ReservationController::class, 'destroy'])
            ->middleware('clinic.permission:assistant.delete-reservations');

    });

    Route::middleware(['role:doctor,sub-doctor'])->group(function () {
        Route::get('/print-settings', [PrintSettingsController::class, 'show']);
        Route::put('/print-settings', [PrintSettingsController::class, 'update']);
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
        Route::get('/financials', [FinancialController::class, 'index'])
            ->middleware('clinic.permission:assistant.view-financials');
        Route::get('/financials/summary', [FinancialController::class, 'summary'])
            ->middleware('clinic.permission:assistant.view-financials');
        Route::get('/financials/{financial}', [FinancialController::class, 'show'])
            ->middleware('clinic.permission:assistant.view-financials');
        // Transactions for a financial record
        Route::get('/financials/{financial}/transactions', [TransactionController::class, 'index'])
            ->middleware('clinic.permission:assistant.view-financials');
        Route::post('/financials/{financial}/transactions', [TransactionController::class, 'store'])
            ->middleware('clinic.permission:assistant.edit-financials');
        Route::post('/financials/{financial}/transactions/batch', [TransactionController::class, 'storeBatch'])
            ->middleware('clinic.permission:assistant.edit-financials');
        Route::delete('/financials/{financial}/transactions/{transaction}', [TransactionController::class, 'destroy'])
            ->middleware('clinic.permission:assistant.edit-financials');
        // All transactions for the doctor
        Route::get('/transactions', [TransactionController::class, 'allForDoctor'])
            ->middleware('clinic.permission:assistant.view-financials');
        // Purchases (view)
            Route::get('/purchases', [PurchaseController::class, 'index'])->middleware('clinic.permission:assistant.view-purchases');
            Route::get('/purchases/categories', [PurchaseController::class, 'categories'])->middleware('clinic.permission:assistant.view-purchases');
        // unified stats endpoint (accepts GET and POST payloads)
        Route::get('/purchases/stats', [PurchaseController::class, 'stats'])->middleware('clinic.permission:assistant.view-purchases');
        Route::post('/purchases/stats', [PurchaseController::class, 'stats'])->middleware('clinic.permission:assistant.view-purchases');
        Route::get('/purchases/{purchase}', [PurchaseController::class, 'show'])->middleware('clinic.permission:assistant.view-purchases');
    });

    Route::middleware(['role:assistant'])->group(function () {
        Route::post('/financials', [FinancialController::class, 'store'])
            ->middleware('clinic.permission:assistant.create-financials');
        Route::put('/financials/{financial}', [FinancialController::class, 'update'])
            ->middleware('clinic.permission:assistant.edit-financials');
        Route::delete('/financials/{financial}', [FinancialController::class, 'destroy'])
            ->middleware('clinic.permission:assistant.delete-financials');
    });

    Route::middleware(['role:doctor,assistant,sub-doctor'])->group(function () {
        // Purchases (create/update/delete)
        Route::post('/purchases', [PurchaseController::class, 'store'])
            ->middleware('clinic.permission:assistant.create-purchases');
        Route::put('/purchases/{purchase}', [PurchaseController::class, 'update'])
            ->middleware('clinic.permission:assistant.edit-purchases');
        Route::delete('/purchases/{purchase}', [PurchaseController::class, 'destroy'])
            ->middleware('clinic.permission:assistant.delete-purchases');
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

        Route::get('/doctor-diagnoses', [DoctorDiagnosisController::class, 'index']);
        Route::post('/doctor-diagnoses', [DoctorDiagnosisController::class, 'store']);
        Route::put('/doctor-diagnoses/{doctorDiagnosis}', [DoctorDiagnosisController::class, 'update']);
        Route::delete('/doctor-diagnoses/{doctorDiagnosis}', [DoctorDiagnosisController::class, 'destroy']);
    });

    // Services on a specific reservation — doctor, sub-doctor & assistant (permission-protected)
    Route::middleware(['role:doctor,assistant,sub-doctor'])->group(function () {
        Route::get('/doctors/{doctor}/doctor-services', [DoctorServiceController::class, 'forDoctor'])
            ->middleware('permission:reservation-services.view');
        Route::get('/reservations/{reservation}/doctor-services', [DoctorServiceController::class, 'forReservation'])
            ->middleware('permission:reservation-services.view');
        Route::get('/doctors/{doctor}/doctor-diagnoses', [DoctorDiagnosisController::class, 'forDoctor']);
        Route::get('/reservations/{reservation}/doctor-diagnoses', [DoctorDiagnosisController::class, 'forReservation']);
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
