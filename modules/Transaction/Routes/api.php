<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Transaction\Http\Controllers\MidtransWebhookController;

/**
 * API webhook routes for dual-mode Midtrans transaction settlements.
 */
Route::post('/webhooks/midtrans/platform', [MidtransWebhookController::class, 'platform'])
    ->name('webhooks.midtrans.transaction.platform');

Route::post('/webhooks/midtrans/tenant/{company:slug}', [MidtransWebhookController::class, 'tenant'])
    ->name('webhooks.midtrans.transaction.tenant');
