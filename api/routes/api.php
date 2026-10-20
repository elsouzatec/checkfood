<?php

use App\Http\Controllers\Caixa\CaixaController;
use App\Http\Controllers\Caixa\CashierShiftController;
use App\Http\Controllers\Caixa\DeliveryController;
use App\Http\Controllers\WaiterSessionController;
use App\Http\Controllers\Admin\AdminCategoryController;
use App\Http\Controllers\Admin\AdminClientController;
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
use App\Http\Controllers\Client\ClientAccountController;
use App\Http\Controllers\Client\ClientAuthController;
use App\Http\Controllers\Client\ClientDeliveryController;
use App\Http\Controllers\FeedbackController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\MenuController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\Platform\PlatformBillingController;
use App\Http\Controllers\Platform\PlatformRestaurantController;
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
// NOME FIXO antes de wildcard
Route::get('/restaurants/by-slug/{slug}',      [MenuController::class, 'bySlug']);
Route::get('/restaurants/{restaurantId}/menu', [MenuController::class, 'index']);
Route::post('/auth/login', [LoginController::class, 'login'])
    ->middleware('throttle:login');

// Conta do cliente (por restaurante) — cadastro e login públicos
Route::post('/client/register', [ClientAuthController::class, 'register'])
    ->middleware('throttle:client-register');
Route::post('/client/login', [ClientAuthController::class, 'login'])
    ->middleware('throttle:login');

// Webhook Mercado Pago — público, sem auth
Route::post('/webhooks/mercadopago', [WebhookController::class, 'mercadopago'])
    ->middleware('throttle:webhook')
    ->name('webhooks.mercadopago');

Route::get('/login', function () {
    return response()->json(['message' => 'Não autenticado.'], 401);
})->name('login');

// ── Autenticadas ──
Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {

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

    // Rotas de operação exigem papel: os controllers só conferem o restaurante,
    // e o cliente da mesa também tem token deste restaurante. Sem o papel, ele
    // listaria pedidos de outras mesas e validaria/cancelaria pedidos.
    // garcom app: waiter|manager · cozinha app: kitchen|manager
    $salao   = 'role:waiter,manager';
    $cozinha = 'role:kitchen,manager';
    $staff   = 'role:waiter,kitchen,cashier,manager';

    // Waiter Calls — NOMES FIXOS PRIMEIRO
    Route::get('/waiter-calls/status',                     [WaiterCallController::class, 'status']);
    Route::get('/waiter-calls/pending',                    [WaiterCallController::class, 'pending'])->middleware($salao);
    Route::post('/waiter-calls',                           [WaiterCallController::class, 'store']);
    Route::patch('/waiter-calls/{waiterCall}/dismiss',     [WaiterCallController::class, 'dismiss'])->middleware($salao);

    // Orders — NOMES FIXOS PRIMEIRO
    Route::get('/orders/my-session',                       [OrderController::class, 'mySession']);
    Route::get('/orders/pending',                          [OrderController::class, 'pending'])->middleware($salao);
    Route::get('/orders/kitchen',                          [OrderController::class, 'kitchen'])->middleware($cozinha);
    Route::get('/orders/ready',                            [OrderController::class, 'ready'])->middleware('role:waiter,kitchen,manager');
    // Orders — WILDCARD POR ÚLTIMO
    Route::get('/orders/{order}',                          [OrderController::class, 'show'])->middleware($staff);
    Route::post('/orders',                                 [OrderController::class, 'store']);
    Route::patch('/orders/{order}/validate',               [OrderController::class, 'validateOrder'])->middleware($salao);
    Route::patch('/orders/{order}/quick-deliver',          [OrderController::class, 'quickDeliver'])->middleware($salao);
    Route::patch('/orders/{order}/start',                  [OrderController::class, 'start'])->middleware($cozinha);
    Route::patch('/orders/{order}/ready',                  [OrderController::class, 'markReady'])->middleware($cozinha);
    Route::patch('/orders/{order}/deliver',                [OrderController::class, 'deliver'])->middleware($salao);
    Route::patch('/orders/{order}/pick-and-deliver',       [OrderController::class, 'pickAndDeliver'])->middleware($salao);
    Route::patch('/orders/{order}/cancel',                 [OrderController::class, 'cancel'])->middleware($salao);
    Route::delete('/orders/{order}/items/{orderItem}',     [OrderController::class, 'removeItem'])->middleware($salao);

    // Waiter — sessões de guest e pedidos pelo garçom
    Route::middleware($salao)->group(function () {
        Route::post('/waiter/sessions',                          [WaiterSessionController::class, 'store']);
        Route::post('/waiter/sessions/{session}/orders',         [WaiterSessionController::class, 'storeOrder']);
        Route::post('/waiter/sessions/{session}/forward-cashier', [WaiterSessionController::class, 'forwardToCashier']);
        Route::get('/waiter/menu',                               [WaiterSessionController::class, 'menu']);
    });

    // Tables (garçom/cozinha)
    Route::get('/tables',                  [TableController::class, 'index'])->middleware($staff);
    Route::get('/tables/{table}/orders',   [TableController::class, 'orders'])->middleware($staff);

    // Configurações do restaurante (staff — garçom, cozinha, gerente, caixa)
    Route::get('/restaurant/settings', [RestaurantSettingsController::class, 'staff'])->middleware($staff);

    // Feedbacks (cliente)
    Route::get('/feedbacks/check/{sessionId}', [FeedbackController::class, 'check']);
    Route::post('/feedbacks',                  [FeedbackController::class, 'store']);

    // ── Conta do cliente ── (role: client) — /me, /orders e /tables/link
    // exigem conta; /account converte o cliente anônimo da mesa em conta.
    Route::middleware('role:client')->prefix('client')->group(function () {
        Route::post('/account',     [ClientAuthController::class, 'upgrade']);
        Route::get('/me',           [ClientAccountController::class, 'show']);
        Route::put('/me',           [ClientAccountController::class, 'update']);
        Route::delete('/me',        [ClientAccountController::class, 'destroy']);
        Route::get('/orders',       [ClientAccountController::class, 'orders']);
        Route::post('/tables/link', [ClientAccountController::class, 'linkTable']);

        // Entrega (restaurante com delivery_enabled)
        Route::get('/delivery',         [ClientDeliveryController::class, 'show']);
        Route::post('/delivery/quote',  [ClientDeliveryController::class, 'quote']);
        Route::post('/delivery-orders', [ClientDeliveryController::class, 'store']);
    });

    // ── App Caixa ── (roles: cashier, manager)
    Route::middleware('role:cashier,manager')->prefix('caixa')->group(function () {
        Route::get('/queue',                                  [CaixaController::class, 'queue']);
        Route::get('/sessions/{session}',                     [CaixaController::class, 'show']);
        Route::post('/sessions/{session}/confirm-payment',    [CaixaController::class, 'confirmPayment']);
        Route::post('/sessions/{session}/pix',                [CaixaController::class, 'initiatePix']);
        Route::post('/sessions/{session}/pix/cancel',         [CaixaController::class, 'cancelPix']);
        Route::get('/history',  [CaixaController::class, 'history']);
        Route::get('/summary',  [CaixaController::class, 'summary']);

        // Entregas (pedido sem mesa): aceitar → saiu → entregue → acerto no caixa
        Route::get('/deliveries',                     [DeliveryController::class, 'index']);
        Route::post('/deliveries/{session}/accept',    [DeliveryController::class, 'accept']);
        Route::post('/deliveries/{session}/dispatch',  [DeliveryController::class, 'dispatch']);
        Route::post('/deliveries/{session}/delivered', [DeliveryController::class, 'delivered']);
        Route::post('/deliveries/{session}/cancel',    [DeliveryController::class, 'cancel']);

        // Turno de caixa por operador
        Route::get('/shift',        [CashierShiftController::class, 'current']);
        Route::post('/shift/open',  [CashierShiftController::class, 'open']);
        Route::post('/shift/close', [CashierShiftController::class, 'close']);
    });

    // ── Plataforma (role: super_admin) — gestão cross-tenant ──
    Route::middleware('role:super_admin')->prefix('platform')->group(function () {
        Route::get('/restaurants',                              [PlatformRestaurantController::class, 'index']);
        Route::post('/restaurants',                             [PlatformRestaurantController::class, 'store']);
        Route::get('/restaurants/{restaurant}',                 [PlatformRestaurantController::class, 'show']);
        Route::put('/restaurants/{restaurant}',                 [PlatformRestaurantController::class, 'update']);
        Route::patch('/restaurants/{restaurant}/toggle-active', [PlatformRestaurantController::class, 'toggleActive']);
        Route::post('/restaurants/{restaurant}/managers',       [PlatformRestaurantController::class, 'storeManager']);

        // Mensalidades
        Route::get('/restaurants/{restaurant}/billing',      [PlatformBillingController::class, 'show']);
        Route::put('/restaurants/{restaurant}/billing',      [PlatformBillingController::class, 'update']);
        Route::post('/restaurants/{restaurant}/billing/pay', [PlatformBillingController::class, 'pay']);
    });

    // ── Admin (role: manager) ──
    Route::middleware('role:manager')->prefix('admin')->group(function () {

        Route::get('/restaurant',                 [AdminRestaurantController::class, 'show']);
        Route::put('/restaurant',                 [AdminRestaurantController::class, 'update']);
        Route::put('/restaurant/payment-gateway', [AdminRestaurantController::class, 'updatePaymentGateway']);
        Route::post('/restaurant/logo',           [AdminRestaurantController::class, 'uploadLogo']);
        Route::delete('/restaurant/logo',         [AdminRestaurantController::class, 'removeLogo']);

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

        // Clientes (contas) — gerente redefine acesso ("esqueci a senha")
        Route::get('/clients',                      [AdminClientController::class, 'index']);
        Route::post('/clients/{user}/reset-account', [AdminClientController::class, 'resetAccount']);

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
Route::middleware(['auth:sanctum', 'abilities:print-agent', 'throttle:print-agent'])
    ->prefix('print-agent')
    ->group(function () {
        Route::get('/jobs/pending',                [PrintAgentController::class, 'pending']);
        Route::patch('/jobs/{job}/printed',         [PrintAgentController::class, 'markPrinted']);
        Route::patch('/jobs/{job}/failed',          [PrintAgentController::class, 'markFailed']);
    });
