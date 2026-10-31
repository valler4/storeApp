<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrderControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_gets_401_when_cancelling_order_item(): void
    {
        $this->patchJson('/api/v1/orders/items/01ARZ3NDEKTSV4RRFFQ69G5FAV/cancel')
            ->assertUnauthorized();
    }

    public function test_buyer_cancels_own_pending_item_and_restores_stock_without_changing_order_total(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $fixture = $this->orderItemFixture($buyer, $seller, 'pending', 3, 5);
        Sanctum::actingAs($buyer);

        $this->patchJson("/api/v1/orders/items/{$fixture['item']->id}/cancel")
            ->assertOk()
            ->assertJsonPath('status', 'cancelled');

        $this->assertDatabaseHas('order_items', ['id' => $fixture['item']->id, 'status' => 'cancelled']);
        $this->assertSame(8, (int) Product::query()->findOrFail($fixture['product']->id)->stock);
        $this->assertSame('83.75', $fixture['order']->fresh()->total_price);
    }

    public function test_second_buyer_cancel_returns_422_without_restoring_stock_again(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $fixture = $this->orderItemFixture($buyer, $seller, 'pending', 2, 5);
        Sanctum::actingAs($buyer);
        $path = "/api/v1/orders/items/{$fixture['item']->id}/cancel";

        $this->patchJson($path)->assertOk();
        $this->patchJson($path)->assertUnprocessable();

        $this->assertDatabaseHas('order_items', ['id' => $fixture['item']->id, 'status' => 'cancelled']);
        $this->assertSame(7, (int) Product::query()->findOrFail($fixture['product']->id)->stock);
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
    public function test_buyer_cannot_cancel_non_pending_item(string $status): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $fixture = $this->orderItemFixture($buyer, $seller, $status, 3, 5);
        Sanctum::actingAs($buyer);

        $this->patchJson("/api/v1/orders/items/{$fixture['item']->id}/cancel")->assertUnprocessable();

        $this->assertDatabaseHas('order_items', ['id' => $fixture['item']->id, 'status' => $status]);
        $this->assertSame(5, (int) Product::query()->findOrFail($fixture['product']->id)->stock);
    }

    public function test_buyer_gets_404_for_another_users_order_item(): void
    {
        $buyer = User::factory()->create();
        $otherBuyer = User::factory()->create();
        $seller = User::factory()->create();
        $fixture = $this->orderItemFixture($otherBuyer, $seller);
        Sanctum::actingAs($buyer);

        $this->patchJson("/api/v1/orders/items/{$fixture['item']->id}/cancel")->assertNotFound();

        $this->assertDatabaseHas('order_items', ['id' => $fixture['item']->id, 'status' => 'pending']);
        $this->assertSame(5, (int) Product::query()->findOrFail($fixture['product']->id)->stock);
    }

    public function test_seller_gets_404_from_buyer_cancel_route(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $fixture = $this->orderItemFixture($buyer, $seller);
        Sanctum::actingAs($seller);

        $this->patchJson("/api/v1/orders/items/{$fixture['item']->id}/cancel")->assertNotFound();

        $this->assertDatabaseHas('order_items', ['id' => $fixture['item']->id, 'status' => 'pending']);
        $this->assertSame(5, (int) Product::query()->findOrFail($fixture['product']->id)->stock);
    }

    public function test_buyer_cancel_returns_404_for_nonexistent_item(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->patchJson('/api/v1/orders/items/01ARZ3NDEKTSV4RRFFQ69G5FAV/cancel')->assertNotFound();
    }

    public function test_buyer_can_cancel_item_for_soft_deleted_product_and_restore_trashed_stock(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $fixture = $this->orderItemFixture($buyer, $seller, 'pending', 3, 5);
        $fixture['product']->delete();
        Sanctum::actingAs($buyer);

        $this->patchJson("/api/v1/orders/items/{$fixture['item']->id}/cancel")
            ->assertOk()
            ->assertJsonPath('status', 'cancelled');

        $this->assertDatabaseHas('order_items', ['id' => $fixture['item']->id, 'status' => 'cancelled']);
        $this->assertSame(8, (int) Product::withTrashed()->findOrFail($fixture['product']->id)->stock);
        $this->assertSoftDeleted('products', ['id' => $fixture['product']->id]);
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
