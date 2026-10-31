<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SellerOrderItemControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_gets_401_when_cancelling_seller_item(): void
    {
        $this->patchJson('/api/v1/seller/order-items/01ARZ3NDEKTSV4RRFFQ69G5FAV/cancel')
            ->assertUnauthorized();
    }

    public function test_seller_cancels_own_pending_item_and_restores_stock(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $fixture = $this->orderItemFixture($buyer, $seller, 'pending', 3, 5);
        Sanctum::actingAs($seller);

        $this->patchJson("/api/v1/seller/order-items/{$fixture['item']->id}/cancel")
            ->assertOk()
            ->assertJsonPath('status', 'cancelled');

        $this->assertDatabaseHas('order_items', ['id' => $fixture['item']->id, 'status' => 'cancelled']);
        $this->assertSame(8, (int) Product::query()->findOrFail($fixture['product']->id)->stock);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonPendingStatuses(): array
    {
        return [
            'shipped' => ['shipped'],
            'delivered' => ['delivered'],
            'cancelled' => ['cancelled'],
        ];
    }

    #[DataProvider('nonPendingStatuses')]
    public function test_seller_cannot_cancel_non_pending_item(string $status): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $fixture = $this->orderItemFixture($buyer, $seller, $status, 3, 5);
        Sanctum::actingAs($seller);

        $this->patchJson("/api/v1/seller/order-items/{$fixture['item']->id}/cancel")
            ->assertUnprocessable();

        $this->assertDatabaseHas('order_items', ['id' => $fixture['item']->id, 'status' => $status]);
        $this->assertSame(5, (int) Product::query()->findOrFail($fixture['product']->id)->stock);
    }

    public function test_another_seller_gets_404_and_item_is_unchanged(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $otherSeller = User::factory()->create();
        $fixture = $this->orderItemFixture($buyer, $seller);
        Sanctum::actingAs($otherSeller);

        $this->patchJson("/api/v1/seller/order-items/{$fixture['item']->id}/cancel")
            ->assertNotFound();

        $this->assertDatabaseHas('order_items', ['id' => $fixture['item']->id, 'status' => 'pending']);
        $this->assertSame(5, (int) Product::query()->findOrFail($fixture['product']->id)->stock);
    }

    public function test_buyer_who_is_not_seller_gets_404(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $fixture = $this->orderItemFixture($buyer, $seller);
        Sanctum::actingAs($buyer);

        $this->patchJson("/api/v1/seller/order-items/{$fixture['item']->id}/cancel")
            ->assertNotFound();

        $this->assertDatabaseHas('order_items', ['id' => $fixture['item']->id, 'status' => 'pending']);
        $this->assertSame(5, (int) Product::query()->findOrFail($fixture['product']->id)->stock);
    }

    public function test_cancel_returns_404_for_nonexistent_item(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->patchJson('/api/v1/seller/order-items/01ARZ3NDEKTSV4RRFFQ69G5FAV/cancel')
            ->assertNotFound();
    }

    /**
     * @return array{order: Order, product: Product, item: OrderItem}
     */
    private function orderItemFixture(
        User $buyer,
        User $seller,
        string $status = 'pending',
        int $quantity = 2,
        int $stock = 5,
    ): array {
        $order = Order::factory()->for($buyer)->create(['total_price' => '83.75']);
        $product = Product::factory()->for($seller)->create(['stock' => $stock]);
        $item = OrderItem::factory()->for($order)->for($product)->{$status}()->create([
            'seller_id' => $seller->id,
            'quantity' => $quantity,
            'price_at_purchase' => '12.50',
        ]);

        return compact('order', 'product', 'item');
    }
}
