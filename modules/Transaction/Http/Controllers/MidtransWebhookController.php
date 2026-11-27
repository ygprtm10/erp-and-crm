<?php

declare(strict_types=1);

namespace Modules\Transaction\Http\Controllers;

use App\Models\Company;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Modules\Transaction\Exceptions\InvalidMidtransSignatureException;
use Modules\Transaction\Services\OrderPaymentService;

/**
 * Thin HTTP controller delegating incoming Midtrans transaction webhooks to OrderPaymentService.
 */
class MidtransWebhookController
{
    /**
     * Inject OrderPaymentService for webhook verification and settlement.
     */
    public function __construct(
        private readonly OrderPaymentService $orderPaymentService,
    ) {}

    /**
     * Handle Midtrans webhook callbacks for orders routed via Platform Midtrans credentials.
     */
    public function platform(Request $request): JsonResponse
    {
        return $this->processWebhook($request);
    }

    /**
     * Handle Midtrans webhook callbacks for orders routed via tenant-owned Midtrans credentials.
     */
    public function tenant(Request $request, Company $company): JsonResponse
    {
        return $this->processWebhook($request, $company);
    }

    /**
     * Delegate payload verification and translate domain exceptions to appropriate HTTP status codes.
     */
    private function processWebhook(Request $request, ?Company $company = null): JsonResponse
    {
        try {
            $payment = $this->orderPaymentService->handleMidtransWebhook(
                payload: $request->all(),
                scopedTenantCompany: $company,
            );

            return response()->json([
                'status' => 'ok',
                'payment_id' => $payment->getKey(),
                'payment_status' => $payment->status->value,
            ]);
        } catch (InvalidMidtransSignatureException $exception) {
            return response()->json(['message' => $exception->getMessage()], 403);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 404);
        }
    }
}
