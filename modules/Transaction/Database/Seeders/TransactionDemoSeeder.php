<?php

declare(strict_types=1);

namespace Modules\Transaction\Database\Seeders;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;
use Modules\Accounting\Models\Account;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Contact;
use Modules\Core\Models\ProductVariant;
use Modules\Transaction\Enums\OrderChannel;
use Modules\Transaction\Enums\OrderStatus;
use Modules\Transaction\Enums\PaymentMethod;
use Modules\Transaction\Enums\PosShiftStatus;
use Modules\Transaction\Enums\PosTableStatus;
use Modules\Transaction\Models\Order;
use Modules\Transaction\Models\PosShift;
use Modules\Transaction\Models\PosTable;
use Modules\Transaction\Services\OrderCreationService;
use Modules\Transaction\Services\OrderPaymentService;
use Modules\Transaction\Services\PosShiftService;

/**
 * Idempotently seeds one POS table, one cashier shift, and one settled POS order for Toko Maju.
 */
class TransactionDemoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $tokoMaju = Company::query()->where('slug', 'toko-maju')->first();

        if (! $tokoMaju instanceof Company) {
            return;
        }

        $mainBranch = Branch::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $tokoMaju->getKey())
            ->where('code', 'MAIN')
            ->first();

        $owner = User::query()->where('email', 'owner@test.com')->first();
        $variant = ProductVariant::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $tokoMaju->getKey())
            ->where('sku', 'KOPI-ARB-250')
            ->first();

        if (! $mainBranch instanceof Branch || ! $owner instanceof User || ! $variant instanceof ProductVariant) {
            return;
        }

        $table = PosTable::query()
            ->withoutGlobalScope('company')
            ->updateOrCreate(
                [
                    'company_id' => $tokoMaju->getKey(),
                    'branch_id' => $mainBranch->getKey(),
                    'table_number' => 'T-01',
                ],
                [
                    'qr_token' => 'QR-TOKO-MAJU-T01',
                    'capacity' => 4,
                    'status' => PosTableStatus::Available,
                ],
            );

        /** Reuse an existing open shift or create one idempotently for the demo cashier. */
        $shift = PosShift::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $tokoMaju->getKey())
            ->where('branch_id', $mainBranch->getKey())
            ->where('user_id', $owner->getKey())
            ->where('status', PosShiftStatus::Open->value)
            ->first();

        if (! $shift instanceof PosShift) {
            $shift = app(PosShiftService::class)->openShift(
                company: $tokoMaju,
                branch: $mainBranch,
                user: $owner,
                openingCash: '200000.0000',
            );
        }

        $seedOrderExists = Order::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $tokoMaju->getKey())
            ->where('order_number', 'TRX-SEED-001')
            ->exists();

        if ($seedOrderExists) {
            return;
        }

        $customer = Contact::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $tokoMaju->getKey())
            ->where('code', 'CUST-001')
            ->first();

        $cashAccount = Account::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $tokoMaju->getKey())
            ->where('code', '1101')
            ->first();

        $order = app(OrderCreationService::class)->createOrder(
            company: $tokoMaju,
            branch: $mainBranch,
            channel: OrderChannel::Pos,
            items: [
                [
                    'product_variant_id' => (int) $variant->getKey(),
                    'qty' => '2.00',
                    'notes' => 'Demo POS cash order',
                ],
            ],
            contact: $customer,
            posShift: $shift,
            posTable: $table,
            createdBy: $owner,
            notes: 'Seeded demo transaction for Toko Maju',
            orderNumber: 'TRX-SEED-001',
            initialStatus: OrderStatus::Completed,
        );

        app(OrderPaymentService::class)->recordManualPayment(
            order: $order,
            method: PaymentMethod::Cash,
            amount: (string) $order->grand_total,
            account: $cashAccount,
            recordedBy: $owner,
            notes: 'Settled in cash during demo seeding',
        );
    }
}
