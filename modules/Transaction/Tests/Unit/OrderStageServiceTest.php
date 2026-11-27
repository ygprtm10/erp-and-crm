<?php

declare(strict_types=1);

namespace Modules\Transaction\Tests\Unit;

use App\Models\Company;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Enums\CategoryType;
use Modules\Core\Enums\ProductType;
use Modules\Core\Enums\ProductUnit;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Category;
use Modules\Core\Models\Product;
use Modules\Core\Models\ProductVariant;
use Modules\Transaction\Enums\OrderChannel;
use Modules\Transaction\Services\OrderCreationService;
use Modules\Transaction\Services\OrderStageService;
use Tests\TestCase;

/**
 * Unit tests verifying OrderStageService stage transitions, audit logs, and completion guards.
 */
class OrderStageServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ensure starting and completing an order stage writes audit logs and blocks double completion.
     */
    public function test_starts_and_completes_stage_with_logs_and_prevents_double_completion(): void
    {
        /** Arrange a laundry order and an actor user. */
        $company = Company::factory()->create(['business_type' => 'laundry']);
        $branch = Branch::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'code' => 'MAIN',
            'name' => 'Main',
            'is_main' => true,
        ]);
        $category = Category::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'name' => 'Laundry',
            'type' => CategoryType::Service,
        ]);
        $product = Product::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'category_id' => $category->getKey(),
            'name' => 'Cuci',
            'slug' => 'cuci',
            'type' => ProductType::Service,
        ]);
        $variant = ProductVariant::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'product_id' => $product->getKey(),
            'name' => 'Kiloan',
            'sku' => 'CUCI-KG',
            'unit' => ProductUnit::Kg,
            'cost_price' => '2000.0000',
            'selling_price' => '8000.0000',
            'is_active' => true,
        ]);

        $actor = User::factory()->create();
        $order = app(OrderCreationService::class)->createOrder(
            company: $company,
            branch: $branch,
            channel: OrderChannel::Laundry,
            items: [['product_variant_id' => $variant->getKey(), 'qty' => '2.50']],
        );

        $stageService = app(OrderStageService::class);
        $stage = $stageService->startStage($order, 'Washing', 101, 'Cold wash', $actor);

        $this->assertSame('Washing', $stage->stage_name);
        $this->assertSame(101, $stage->handled_by_employee_id);
        $this->assertNull($stage->completed_at);

        $completed = $stageService->completeStage($stage, 'Wash finished', $actor);
        $this->assertNotNull($completed->completed_at);

        $events = $order->logs()->withoutGlobalScope('company')->pluck('event')->all();
        $this->assertContains('stage_started', $events);
        $this->assertContains('stage_completed', $events);

        $this->expectException(DomainException::class);
        $stageService->completeStage($completed);
    }
}
