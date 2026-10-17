<?php

use App\Http\Controllers\AdminInspectionController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HousePriceNegotiationController;
use App\Http\Controllers\MaintenanceRequestController;
use App\Http\Controllers\OwnerHouseController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\RentalRequestController;
use App\Http\Controllers\StudentHousingController;
use App\Http\Controllers\WorkerInspectionController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::prefix('api')->group(function () {
    Route::get('/csrf-cookie', fn () => response()->noContent());

    Route::prefix('auth')->group(function () {
        Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
        Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

        Route::middleware('auth')->group(function () {
            Route::get('/user', [AuthController::class, 'user']);
            Route::post('/logout', [AuthController::class, 'logout']);
        });
    });

    Route::middleware('auth')->group(function () {
        Route::get('/maintenance-notifications', [MaintenanceRequestController::class, 'notifications']);
        Route::post('/maintenance-notifications/{notification}/read', [MaintenanceRequestController::class, 'readNotification']);

        Route::prefix('student')->middleware('role:student')->group(function () {
            Route::get('/dashboard', [StudentHousingController::class, 'dashboard']);
            Route::get('/houses', [StudentHousingController::class, 'index']);
            Route::get('/houses/{house}', [StudentHousingController::class, 'show']);
            Route::get('/houses/{house}/photos/{photo}', [StudentHousingController::class, 'photo'])
                ->name('student.houses.photos.show');
            Route::get('/rental-requests', [RentalRequestController::class, 'studentIndex']);
            Route::get('/payments', [PaymentController::class, 'studentIndex']);
            Route::post('/rental-requests', [RentalRequestController::class, 'store'])
                ->middleware('throttle:20,1');
            Route::post('/rental-requests/{rentalRequest}/cancel', [RentalRequestController::class, 'cancel'])
                ->middleware('throttle:20,1');
            Route::post('/rental-requests/{rentalRequest}/confirm-secured', [RentalRequestController::class, 'confirmStudent'])
                ->middleware('throttle:20,1');
            Route::post('/rental-requests/{rentalRequest}/payment', [PaymentController::class, 'prepareStudentFee'])
                ->middleware('throttle:10,1');
            Route::get('/maintenance-requests', [MaintenanceRequestController::class, 'studentIndex']);
            Route::post('/maintenance-requests', [MaintenanceRequestController::class, 'studentStore'])
                ->middleware('throttle:10,1');
            Route::get('/maintenance-requests/{maintenanceRequest}/photos/{photo}', [MaintenanceRequestController::class, 'photo'])
                ->name('student.maintenance.photos.show');
        });

        Route::get('/owner/dashboard', [DashboardController::class, 'owner'])
            ->middleware('role:owner');

        Route::prefix('owner')->middleware('role:owner')->group(function () {
            Route::get('/houses', [OwnerHouseController::class, 'index']);
            Route::post('/houses', [OwnerHouseController::class, 'store']);
            Route::get('/houses/{house}', [OwnerHouseController::class, 'show']);
            Route::put('/houses/{house}', [OwnerHouseController::class, 'update']);
            Route::get('/houses/{house}/photos/{photo}', [OwnerHouseController::class, 'photo'])
                ->name('owner.houses.photos.show');
            Route::get('/rental-requests', [RentalRequestController::class, 'ownerIndex']);
            Route::post('/subscriptions/{subscription}/payment', [PaymentController::class, 'prepareOwnerSubscription'])
                ->middleware('throttle:10,1');
            Route::post('/rental-requests/{rentalRequest}/decision', [RentalRequestController::class, 'respond'])
                ->middleware('throttle:20,1');
            Route::post('/rental-requests/{rentalRequest}/confirm-secured', [RentalRequestController::class, 'confirmOwner'])
                ->middleware('throttle:20,1');
            Route::get('/maintenance-requests', [MaintenanceRequestController::class, 'ownerIndex']);
            Route::post('/maintenance-requests/{maintenanceRequest}/acknowledge', [MaintenanceRequestController::class, 'ownerAcknowledge'])
                ->middleware('throttle:20,1');
            Route::post('/maintenance-requests/{maintenanceRequest}/notes', [MaintenanceRequestController::class, 'ownerNote'])
                ->middleware('throttle:20,1');
            Route::post('/maintenance-requests/{maintenanceRequest}/estimate-decision', [MaintenanceRequestController::class, 'ownerApproveEstimate'])
                ->middleware('throttle:20,1');
            Route::post('/maintenance-requests/{maintenanceRequest}/confirm-completion', [MaintenanceRequestController::class, 'ownerConfirmCompletion'])
                ->middleware('throttle:20,1');
            Route::post('/maintenance-requests/{maintenanceRequest}/dispute-completion', [MaintenanceRequestController::class, 'ownerDisputeCompletion'])
                ->middleware('throttle:20,1');
            Route::get('/maintenance-requests/{maintenanceRequest}/photos/{photo}', [MaintenanceRequestController::class, 'photo'])
                ->name('owner.maintenance.photos.show');
        });

        Route::prefix('worker')->middleware('role:worker')->group(function () {
            Route::get('/dashboard', [WorkerInspectionController::class, 'dashboard']);
            Route::get('/inspections', [WorkerInspectionController::class, 'index']);
            Route::get('/inspections/{inspection}', [WorkerInspectionController::class, 'show']);
            Route::post('/inspections/{inspection}/complete', [WorkerInspectionController::class, 'complete'])
                ->middleware('throttle:20,1');
            Route::get('/inspections/{inspection}/photos/{photo}', [WorkerInspectionController::class, 'photo'])
                ->name('worker.inspections.photos.show');
            Route::get('/maintenance-requests', [MaintenanceRequestController::class, 'workerIndex']);
            Route::post('/maintenance-requests/{maintenanceRequest}/updates', [MaintenanceRequestController::class, 'workerUpdate'])
                ->middleware('throttle:20,1');
            Route::get('/maintenance-requests/{maintenanceRequest}/photos/{photo}', [MaintenanceRequestController::class, 'photo'])
                ->name('worker.maintenance.photos.show');
        });

        Route::prefix('admin')->middleware('role:admin')->group(function () {
            Route::get('/workers', [AdminInspectionController::class, 'workers']);
            Route::get('/inspection-reviews', [AdminInspectionController::class, 'pendingReviews']);
            Route::get('/price-negotiations', [HousePriceNegotiationController::class, 'index']);
            Route::get('/houses/{house}/photos/{photo}', [HousePriceNegotiationController::class, 'photo'])
                ->name('admin.houses.photos.show');
            Route::post('/houses/{house}/submission-decision', [HousePriceNegotiationController::class, 'reviewSubmission'])
                ->middleware('throttle:20,1');
            Route::patch('/houses/{house}/price-negotiation', [HousePriceNegotiationController::class, 'update'])
                ->middleware('throttle:20,1');
            Route::post('/houses/{house}/inspection-assignment', [AdminInspectionController::class, 'assign'])
                ->middleware('throttle:20,1');
            Route::post('/inspections/{inspection}/decision', [AdminInspectionController::class, 'decide'])
                ->middleware('throttle:20,1');
            Route::get('/inspections/{inspection}/photos/{photo}', [AdminInspectionController::class, 'reviewPhoto'])
                ->name('admin.inspections.photos.show');
            Route::post('/maintenance-requests/{maintenanceRequest}/assignment', [MaintenanceRequestController::class, 'adminAssign'])
                ->middleware('throttle:20,1');
            Route::get('/maintenance-requests/{maintenanceRequest}/photos/{photo}', [MaintenanceRequestController::class, 'photo'])
                ->name('admin.maintenance.photos.show');
        });
    });
});
