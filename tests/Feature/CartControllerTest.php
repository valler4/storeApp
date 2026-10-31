<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CartControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_view_cart(): void
    {
        $this->getJson('/api/v1/cart')->assertUnauthorized();
    }

    public function test_user_without_cart_gets_empty_cart_without_creating_one(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/cart')
            ->assertOk()
            ->assertExactJson([
                'items' => [],
                'total_items' => 0,
                'total_price' => '0.00',
                'unavailable_count' => 0,
            ]);

        $this->assertDatabaseMissing('carts', ['user_id' => $user->id]);
    }

    /**
     * @return array<string, array{array<string, bool|int>, int, bool, ?string}>
     */
    public static function availabilityCases(): array
    {
        return [
            'hidden product' => [['is_hidden' => true], 1, false, 'unavailable'],
            'inactive product' => [['is_active' => false], 1, false, 'closed'],
            'zero stock' => [['stock' => 0], 1, false, 'out of stock'],
            'insufficient stock' => [['stock' => 2], 3, false, 'only 2 left'],
            'available product' => [['stock' => 5], 2, true, null],
        ];
    }

    #[DataProvider('availabilityCases')]
    public function test_cart_item_reports_availability(
        array $productAttributes,
        int $quantity,
        bool $available,
        ?string $reason,
    ): void {
        $user = User::factory()->create();
        $cart = Cart::factory()->for($user)->create();
        $product = Product::factory()->create($productAttributes);
        CartItem::factory()->for($cart)->for($product)->create(['quantity' => $quantity]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonCount(1, 'cart_items')
            ->assertJsonPath('cart_items.0.available', $available)
            ->assertJsonPath('cart_items.0.reason', $reason);
    }

    public function test_user_only_sees_their_own_cart_items(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $cart = Cart::factory()->for($user)->create();
        $otherCart = Cart::factory()->for($otherUser)->create();
        $product = Product::factory()->create();
        $otherProduct = Product::factory()->create();
        CartItem::factory()->for($cart)->for($product)->create();
        CartItem::factory()->for($otherCart)->for($otherProduct)->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonCount(1, 'cart_items')
            ->assertJsonPath('cart_items.0.product_id', $product->id)
            ->assertJsonMissing(['product_id' => $otherProduct->id]);
    }

    public function test_guest_cannot_add_cart_item(): void
    {
        $product = Product::factory()->create();

        $this->postJson('/api/v1/cart/items', [
            'product_id' => $product->id,
        ])->assertUnauthorized();
    }

    public function test_adding_product_creates_cart_and_cart_item(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/cart/items', [
            'product_id' => $product->id,
        ])
            ->assertCreated()
            ->assertJsonPath('product_id', $product->id)
            ->assertJsonPath('quantity', 1);

        $this->assertDatabaseHas('carts', ['user_id' => $user->id]);
        $this->assertDatabaseHas('cart_items', [
            'product_id' => $product->id,
            'quantity' => 1,
        ]);
        $this->assertDatabaseCount('cart_items', 1);
    }

    public function test_adding_same_product_again_returns_existing_cart_item(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();
        Sanctum::actingAs($user);
        $payload = ['product_id' => $product->id];

        $this->postJson('/api/v1/cart/items', $payload)->assertCreated();
        $this->postJson('/api/v1/cart/items', $payload)
            ->assertOk()
            ->assertJsonPath('quantity', 1);

        $this->assertDatabaseCount('cart_items', 1);
    }

    public function test_missing_product_id_returns_422(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/cart/items', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('product_id');
    }

    public function test_nonexistent_product_id_returns_422(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/cart/items', [
            'product_id' => '01J00000000000000000000000X',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('product_id');
    }

    public function test_hidden_product_cannot_be_added(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['is_hidden' => true]);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/cart/items', [
            'product_id' => $product->id,
        ])->assertNotFound();
    }

    public function test_inactive_product_can_be_added(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['is_active' => false]);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/cart/items', [
            'product_id' => $product->id,
        ])->assertCreated();

        $this->assertDatabaseHas('cart_items', [
            'product_id' => $product->id,
            'quantity' => 1,
        ]);
    }

    /**
     * @return array<string, array{array<string, int|string>}>
     */
    public static function invalidUpdateQuantities(): array
    {
        return [
            'zero' => [['quantity' => 0]],
            'over maximum' => [['quantity' => 100]],
            'missing' => [[]],
            'non-numeric' => [['quantity' => 'abc']],
        ];
    }

    public function test_guest_gets_401_when_updating_cart_item(): void
    {
        $cartItem = $this->makeCartItem(User::factory()->create());

        $this->patchJson("/api/v1/cart/items/{$cartItem->id}", ['quantity' => 3])
            ->assertUnauthorized();
    }

    public function test_update_sets_final_quantity_in_response_and_database(): void
    {
        $user = User::factory()->create();
        $cartItem = $this->makeCartItem($user, [], 2);
        Sanctum::actingAs($user);

        $this->patchJson("/api/v1/cart/items/{$cartItem->id}", ['quantity' => 5])
            ->assertOk()
            ->assertJsonPath('quantity', 5);

        $this->assertDatabaseHas('cart_items', [
            'id' => $cartItem->id,
            'quantity' => 5,
        ]);
    }

    /**
     * @param  array<string, int|string>  $payload
     */
    #[DataProvider('invalidUpdateQuantities')]
    public function test_update_returns_422_for_invalid_quantity(array $payload): void
    {
        $user = User::factory()->create();
        $cartItem = $this->makeCartItem($user, [], 2);
        Sanctum::actingAs($user);

        $this->patchJson("/api/v1/cart/items/{$cartItem->id}", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('quantity');
    }

    public function test_update_returns_404_for_missing_cart_item(): void
    {
        $user = User::factory()->create();
        Cart::factory()->for($user)->create();
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/cart/items/01ARZ3NDEKTSV4RRFFQ69G5FAV', ['quantity' => 3])
            ->assertNotFound();
    }

    public function test_update_returns_404_when_user_has_no_cart(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/cart/items/01ARZ3NDEKTSV4RRFFQ69G5FAV', ['quantity' => 3])
            ->assertNotFound();
    }

    public function test_update_returns_404_for_another_users_cart_item(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $cartItem = $this->makeCartItem($owner, [], 2);
        Sanctum::actingAs($otherUser);

        $this->patchJson("/api/v1/cart/items/{$cartItem->id}", ['quantity' => 5])
            ->assertNotFound();

        $this->assertDatabaseHas('cart_items', [
            'id' => $cartItem->id,
            'quantity' => 2,
        ]);
    }

    public function test_update_allows_quantity_above_stock_for_inactive_product(): void
    {
        $user = User::factory()->create();
        $cartItem = $this->makeCartItem($user, ['stock' => 1, 'is_active' => false], 1);
        Sanctum::actingAs($user);

        $this->patchJson("/api/v1/cart/items/{$cartItem->id}", ['quantity' => 2])
            ->assertOk()
            ->assertJsonPath('quantity', 2);

        $this->assertDatabaseHas('cart_items', [
            'id' => $cartItem->id,
            'quantity' => 2,
        ]);
    }

    public function test_guest_gets_401_when_deleting_cart_item(): void
    {
        $cartItem = $this->makeCartItem(User::factory()->create());

        $this->deleteJson("/api/v1/cart/items/{$cartItem->id}")
            ->assertUnauthorized();
    }

    public function test_delete_removes_cart_item_and_returns_success(): void
    {
        $user = User::factory()->create();
        $cartItem = $this->makeCartItem($user);
        Sanctum::actingAs($user);

        $this->deleteJson("/api/v1/cart/items/{$cartItem->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('cart_items', ['id' => $cartItem->id]);
    }

    public function test_delete_does_not_affect_other_items_in_the_cart(): void
    {
        $user = User::factory()->create();
        $cart = Cart::factory()->for($user)->create();
        $deletedProduct = Product::factory()->create();
        $remainingProduct = Product::factory()->create();
        $deletedItem = CartItem::factory()->for($cart)->for($deletedProduct)->create();
        $remainingItem = CartItem::factory()->for($cart)->for($remainingProduct)->create();
        Sanctum::actingAs($user);

        $this->deleteJson("/api/v1/cart/items/{$deletedItem->id}")->assertOk();

        $this->assertDatabaseMissing('cart_items', ['id' => $deletedItem->id]);
        $this->assertDatabaseHas('cart_items', ['id' => $remainingItem->id]);
    }

    public function test_delete_keeps_cart_when_removing_its_last_item(): void
    {
        $user = User::factory()->create();
        $cartItem = $this->makeCartItem($user);
        $cartId = $cartItem->cart_id;
        Sanctum::actingAs($user);

        $this->deleteJson("/api/v1/cart/items/{$cartItem->id}")->assertOk();

        $this->assertDatabaseMissing('cart_items', ['id' => $cartItem->id]);
        $this->assertDatabaseHas('carts', ['id' => $cartId]);
    }

    public function test_delete_returns_404_for_missing_cart_item(): void
    {
        $user = User::factory()->create();
        Cart::factory()->for($user)->create();
        Sanctum::actingAs($user);

        $this->deleteJson('/api/v1/cart/items/01ARZ3NDEKTSV4RRFFQ69G5FAV')
            ->assertNotFound();
    }

    public function test_delete_returns_404_when_user_has_no_cart(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->deleteJson('/api/v1/cart/items/01ARZ3NDEKTSV4RRFFQ69G5FAV')
            ->assertNotFound();
    }

    public function test_delete_returns_404_for_another_users_cart_item(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $cartItem = $this->makeCartItem($owner);
        Cart::factory()->for($otherUser)->create();
        Sanctum::actingAs($otherUser);

        $this->deleteJson("/api/v1/cart/items/{$cartItem->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('cart_items', ['id' => $cartItem->id]);
    }

    /**
     * @param  array<string, bool|int>  $productAttributes
     */
    private function makeCartItem(User $user, array $productAttributes = [], int $quantity = 1): CartItem
    {
        $cart = Cart::factory()->for($user)->create();
        $product = Product::factory()->create($productAttributes);

        return CartItem::factory()
            ->for($cart)
            ->for($product)
            ->create(['quantity' => $quantity]);
    }
}
