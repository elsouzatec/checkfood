<?php

use App\Http\Controllers\Caixa\CaixaController;
use App\Http\Controllers\WaiterSessionController;
use App\Http\Controllers\Admin\AdminCategoryController;
use App\Http\Controllers\Admin\AdminFeedbackController;
use App\Http\Controllers\Admin\AdminIngredientController;
use App\Http\Controllers\Admin\AdminPerformanceController;
use App\Http\Controllers\Admin\AdminPrintController;
use App\Http\Controllers\Admin\AdminProductController;
use App\Http\Controllers\Admin\AdminReportController;
use App\Http\Controllers\Admin\AdminRestaurantController;
use App\Http\Controllers\Admin\AdminTableController;
use App\Http\Controllers\Admin\AdminTeamPerformanceController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\FeedbackController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\MenuController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PrintAgentController;
use App\Http\Controllers\RestaurantSettingsController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\TableController;
use App\Http\Controllers\WaiterCallController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;


/*
|--------------------------------------------------------------------------
| PagFood API Routes
|--------------------------------------------------------------------------
| REGRA DE OURO: Rotas com nome fixo ANTES de wildcards {param}.
*/

// ── Públicas ──
Route::post('/sessions', [SessionController::class, 'store']);
Route::get('/restaurants/{restaurantId}/menu', [MenuController::class, 'index']);
Route::post('/auth/login', [LoginController::class, 'login']);

// Webhook Mercado Pago — público, sem auth
Route::post('/webhooks/mercadopago', [WebhookController::class, 'mercadopago'])
    ->name('webhooks.mercadopago');

Route::get('/login', function () {
    return response()->json(['message' => 'Não autenticado.'], 401);
})->name('login');

// ── Autenticadas ──
Route::middleware('auth:sanctum')->group(function () {

    // Auth
    Route::post('/auth/logout', [LoginController::class, 'logout']);

    // Sessions — NOME FIXO PRIMEIRO
    Route::get('/sessions/check', [SessionController::class, 'check']);
    Route::post('/sessions/{session}/close', [SessionController::class, 'close']);

    // Payments
    Route::get('/payments/{payment}',             [PaymentController::class, 'show']);
    Route::post('/payments/{payment}/pay',         [PaymentController::class, 'pay']);
    Route::post('/payments/{payment}/deep-link',   [PaymentController::class, 'deepLink']);
    Route::post('/payments/{payment}/confirm-pos', [PaymentController::class, 'confirmPos']);

    // Waiter Calls — NOMES FIXOS PRIMEIRO
    Route::get('/waiter-calls/status',                     [WaiterCallController::class, 'status']);
    Route::get('/waiter-calls/pending',                    [WaiterCallController::class, 'pending']);
    Route::post('/waiter-calls',                           [WaiterCallController::class, 'store']);
    Route::patch('/waiter-calls/{waiterCall}/dismiss',     [WaiterCallController::class, 'dismiss']);

    // Orders — NOMES FIXOS PRIMEIRO
    Route::get('/orders/my-session',                       [OrderController::class, 'mySession']);
    Route::get('/orders/pending',                          [OrderController::class, 'pending']);
    Route::get('/orders/kitchen',                          [OrderController::class, 'kitchen']);
    Route::get('/orders/ready',                            [OrderController::class, 'ready']);
    // Orders — WILDCARD POR ÚLTIMO
    Route::get('/orders/{order}',                          [OrderController::class, 'show']);
    Route::post('/orders',                                 [OrderController::class, 'store']);
    Route::patch('/orders/{order}/validate',               [OrderController::class, 'validateOrder']);
    Route::patch('/orders/{order}/quick-deliver',          [OrderController::class, 'quickDeliver']);
    Route::patch('/orders/{order}/start',                  [OrderController::class, 'start']);
    Route::patch('/orders/{order}/ready',                  [OrderController::class, 'markReady']);
    Route::patch('/orders/{order}/deliver',                [OrderController::class, 'deliver']);
    Route::patch('/orders/{order}/pick-and-deliver',       [OrderController::class, 'pickAndDeliver']);
    Route::patch('/orders/{order}/cancel',                 [OrderController::class, 'cancel']);
    Route::delete('/orders/{order}/items/{orderItem}',     [OrderController::class, 'removeItem']);

    // Waiter — sessões de guest e pedidos pelo garçom
    Route::post('/waiter/sessions',                          [WaiterSessionController::class, 'store']);
    Route::post('/waiter/sessions/{session}/orders',         [WaiterSessionController::class, 'storeOrder']);
    Route::post('/waiter/sessions/{session}/forward-cashier', [WaiterSessionController::class, 'forwardToCashier']);
    Route::get('/waiter/menu',                               [WaiterSessionController::class, 'menu']);

    // Tables (garçom/cozinha)
    Route::get('/tables',                  [TableController::class, 'index']);
    Route::get('/tables/{table}/orders',   [TableController::class, 'orders']);

    // Configurações do restaurante (staff — garçom, cozinha, gerente, caixa)
    Route::get('/restaurant/settings', [RestaurantSettingsController::class, 'staff']);

    // Feedbacks (cliente)
    Route::get('/feedbacks/check/{sessionId}', [FeedbackController::class, 'check']);
    Route::post('/feedbacks',                  [FeedbackController::class, 'store']);

    // ── App Caixa ── (roles: cashier, manager)
    Route::middleware('role:cashier,manager')->prefix('caixa')->group(function () {
        Route::get('/queue',                                  [CaixaController::class, 'queue']);
        Route::get('/sessions/{session}',                     [CaixaController::class, 'show']);
        Route::post('/sessions/{session}/confirm-payment',    [CaixaController::class, 'confirmPayment']);
    });

    // ── Admin (role: manager) ──
    Route::middleware('role:manager')->prefix('admin')->group(function () {

        Route::get('/restaurant',           [AdminRestaurantController::class, 'show']);
        Route::put('/restaurant',           [AdminRestaurantController::class, 'update']);
        Route::post('/restaurant/logo',     [AdminRestaurantController::class, 'uploadLogo']);
        Route::delete('/restaurant/logo',   [AdminRestaurantController::class, 'removeLogo']);

        // Configurações do restaurante (atualização parcial — admin)
        Route::patch('/restaurant/settings', [RestaurantSettingsController::class, 'update']);

        Route::apiResource('categories', AdminCategoryController::class);

        // ── Estoque (Ingredientes) ──
        // NOMES FIXOS ANTES de wildcards
        Route::get('/ingredients/alerts', [AdminIngredientController::class, 'alerts']);
        Route::apiResource('ingredients', AdminIngredientController::class)
            ->only(['index', 'store', 'update', 'destroy']);

        // Ficha técnica de produtos
        Route::get('/products/{product}/ingredients',                  [AdminIngredientController::class, 'listProductIngredients']);
        Route::post('/products/{product}/ingredients',                 [AdminIngredientController::class, 'attachIngredient']);
        Route::delete('/products/{product}/ingredients/{ingredient}',  [AdminIngredientController::class, 'detachIngredient']);

        Route::post('/products/{product}/image', [AdminProductController::class, 'uploadImage']);
        Route::apiResource('products', AdminProductController::class);

        Route::get('/tables/{table}/detail',         [AdminTableController::class, 'detail']);
        Route::post('/tables/{table}/force-release', [AdminTableController::class, 'forceRelease']);
        Route::post('/tables/bulk',                  [AdminTableController::class, 'bulk']);
        Route::get('/tables/{table}/qrcode',         [AdminTableController::class, 'qrcode']);
        Route::apiResource('tables', AdminTableController::class);

        Route::apiResource('users', AdminUserController::class);

        Route::get('/reports/today',  [AdminReportController::class, 'today']);
        Route::get('/reports/period', [AdminReportController::class, 'period']);

        Route::get('/performance', [AdminPerformanceController::class, 'index']);
        Route::get('/team-performance', [AdminTeamPerformanceController::class, 'index']);

        Route::get('/feedbacks', [AdminFeedbackController::class, 'index']);

        // ── Impressão térmica ──
        Route::get('/print/jobs',                   [AdminPrintController::class, 'jobs']);
        Route::delete('/print/jobs/cleanup',        [AdminPrintController::class, 'cleanup']);
        Route::post('/print/jobs/{job}/retry',       [AdminPrintController::class, 'retry']);
        Route::post('/print/test',                   [AdminPrintController::class, 'test']);
        Route::post('/print/agent-token',            [AdminPrintController::class, 'generateAgentToken']);
    });
});

// ── Print Agent (token Sanctum com habilidade 'print-agent') ──
Route::middleware(['auth:sanctum', 'abilities:print-agent'])
    ->prefix('print-agent')
    ->group(function () {
        Route::get('/jobs/pending',                [PrintAgentController::class, 'pending']);
        Route::patch('/jobs/{job}/printed',         [PrintAgentController::class, 'markPrinted']);
        Route::patch('/jobs/{job}/failed',          [PrintAgentController::class, 'markFailed']);
    });
