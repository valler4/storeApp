<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function makeProduct(User $owner, bool $isHidden = false, bool $isActive = true): Product
    {
        return Product::create([
            'user_id' => $owner->id,
            'name' => 'Test',
            'description' => 'Desc',
            'price' => 10,
            'stock' => 5,
            'main_image' => 'products/x.jpg',
            'is_active' => $isActive,
            'is_hidden' => $isHidden,
        ]);
    }

    private function updatePayload(string $name = 'New'): array
    {
        return [
            'name' => $name,
            'description' => 'D',
            'price' => 5,
            'stock' => 1,
            'is_active' => 1,
            'is_hidden' => 0,
        ];
    }

    public function test_user_can_create_product(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/products', [
            'name' => 'Phone',
            'description' => 'Nice',
            'price' => 100,
            'stock' => 3,
            'main_image' => UploadedFile::fake()->image('a.jpg'),
            'is_active' => 1,
            'is_hidden' => 0,
        ])->assertCreated();

        $this->assertDatabaseHas('products', [
            'name' => 'Phone',
            'user_id' => $user->id,
        ]);
    }

    public function test_public_product_list_excludes_hidden_products(): void
    {
        $visibleProduct = $this->makeProduct(User::factory()->create());
        $hiddenProduct = $this->makeProduct(User::factory()->create(), true);

        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonFragment(['id' => $visibleProduct->id])
            ->assertJsonMissing(['id' => $hiddenProduct->id]);
    }

    public function test_public_product_details_are_available(): void
    {
        $product = $this->makeProduct(User::factory()->create());

        $this->getJson("/api/v1/products/{$product->id}")
            ->assertOk()
            ->assertJsonFragment(['id' => $product->id]);
    }

    public function test_hidden_product_is_not_found_for_public_visitors(): void
    {
        $product = $this->makeProduct(User::factory()->create(), true);

        $this->getJson("/api/v1/products/{$product->id}")->assertNotFound();
    }

    public function test_owner_can_view_hidden_product(): void
    {
        $owner = User::factory()->create();
        $product = $this->makeProduct($owner, true);
        Sanctum::actingAs($owner);

        $this->getJson("/api/v1/products/{$product->id}")
            ->assertOk()
            ->assertJsonFragment(['id' => $product->id]);
    }

    public function test_owner_can_update(): void
    {
        $owner = User::factory()->create();
        $product = $this->makeProduct($owner);
        Sanctum::actingAs($owner);

        $this->putJson("/api/v1/products/{$product->id}", $this->updatePayload())
            ->assertOk();
    }

    public function test_other_user_cannot_update(): void
    {
        $product = $this->makeProduct(User::factory()->create());
        Sanctum::actingAs(User::factory()->create());

        $this->putJson("/api/v1/products/{$product->id}", $this->updatePayload('Hack'))
            ->assertForbidden();

        $this->assertDatabaseMissing('products', ['name' => 'Hack']);
    }

    public function test_owner_can_delete(): void
    {
        $owner = User::factory()->create();
        $product = $this->makeProduct($owner);
        Sanctum::actingAs($owner);

        $this->deleteJson("/api/v1/products/{$product->id}")->assertOk();

        $this->assertSoftDeleted('products', ['id' => $product->id]);

        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonMissing(['id' => $product->id]);
        $this->getJson("/api/v1/products/{$product->id}")->assertNotFound();
    }

    public function test_other_user_cannot_delete(): void
    {
        $product = $this->makeProduct(User::factory()->create());
        Sanctum::actingAs(User::factory()->create());

        $this->deleteJson("/api/v1/products/{$product->id}")->assertForbidden();

        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    public function test_admin_can_delete(): void
    {
        $seller = User::factory()->create();
        $buyer = User::factory()->create();
        $product = $this->makeProduct($seller);
        $order = Order::factory()->for($buyer)->create();
        $orderItem = OrderItem::factory()
            ->for($order)
            ->for($product)
            ->create([
                'seller_id' => $seller->id,
                'product_name' => 'Seller Product Snapshot',
                'price_at_purchase' => '23.40',
            ]);
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->deleteJson("/api/v1/products/{$product->id}")->assertOk();

        $this->assertSoftDeleted('products', ['id' => $product->id]);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'user_id' => $buyer->id]);
        $this->assertDatabaseHas('order_items', [
            'id' => $orderItem->id,
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => 'Seller Product Snapshot',
            'price_at_purchase' => '23.40',
        ]);

        Sanctum::actingAs($buyer);
        $this->getJson("/api/v1/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('order_items.0.product_name', 'Seller Product Snapshot')
            ->assertJsonPath('order_items.0.price_at_purchase', 23.40);
    }

    public function test_deleting_product_removes_it_from_every_users_cart(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $product = $this->makeProduct($owner);
        $ownerCart = Cart::factory()->for($owner)->create();
        $otherCart = Cart::factory()->for($otherUser)->create();
        $ownerCartItem = CartItem::factory()->for($ownerCart)->for($product)->create();
        $otherCartItem = CartItem::factory()->for($otherCart)->for($product)->create();
        Sanctum::actingAs($owner);

        $this->deleteJson("/api/v1/products/{$product->id}")->assertOk();

        $this->assertSoftDeleted('products', ['id' => $product->id]);
        $this->assertDatabaseMissing('cart_items', ['id' => $ownerCartItem->id]);
        $this->assertDatabaseMissing('cart_items', ['id' => $otherCartItem->id]);

        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonMissing(['product_id' => $product->id]);

        Sanctum::actingAs($otherUser);
        $this->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonMissing(['product_id' => $product->id]);
    }

    public function test_product_with_order_item_can_be_deleted_and_order_item_is_preserved(): void
    {
        $seller = User::factory()->create();
        $buyer = User::factory()->create();
        $product = $this->makeProduct($seller);
        $order = Order::factory()->for($buyer)->create();
        $orderItem = OrderItem::factory()
            ->for($order)
            ->for($product)
            ->create([
                'seller_id' => $seller->id,
                'product_name' => 'Original Product Name',
                'price_at_purchase' => '18.75',
            ]);
        Sanctum::actingAs($seller);

        $this->deleteJson("/api/v1/products/{$product->id}")->assertOk();

        $this->assertSoftDeleted('products', ['id' => $product->id]);
        $this->assertDatabaseHas('order_items', [
            'id' => $orderItem->id,
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => 'Original Product Name',
            'price_at_purchase' => '18.75',
        ]);

        Sanctum::actingAs($buyer);
        $this->getJson("/api/v1/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('id', $order->id)
            ->assertJsonPath('order_items.0.product_name', 'Original Product Name')
            ->assertJsonPath('order_items.0.price_at_purchase', 18.75);
    }

    public function test_order_item_product_relation_includes_soft_deleted_product(): void
    {
        $seller = User::factory()->create();
        $buyer = User::factory()->create();
        $product = $this->makeProduct($seller);
        $order = Order::factory()->for($buyer)->create();
        $orderItem = OrderItem::factory()
            ->for($order)
            ->for($product)
            ->create(['seller_id' => $seller->id]);
        Sanctum::actingAs($seller);

        $this->deleteJson("/api/v1/products/{$product->id}")->assertOk();

        $this->assertSoftDeleted('products', ['id' => $product->id]);
        $this->assertSame($product->id, $orderItem->fresh()->product->id);
    }

    public function test_guest_gets_401_when_listing_own_products(): void
    {
        $this->getJson('/api/v1/my/products')->assertUnauthorized();
    }

    public function test_mine_returns_own_hidden_and_inactive_products_newest_first_and_paginated(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $ownedProducts = [];

        foreach (range(1, 16) as $position) {
            $isNewest = $position === 16;
            $product = $this->makeProduct(
                $owner,
                $isNewest || $position % 2 === 0,
                ! $isNewest && $position % 3 !== 0,
            );
            $product->forceFill(['created_at' => now()->subMinutes(16 - $position)])->save();
            $ownedProducts[] = $product;
        }

        $otherUsersHiddenProduct = $this->makeProduct($otherUser, true, false);
        $deletedProduct = $this->makeProduct($owner, true, false);
        $deletedProduct->delete();
        Sanctum::actingAs($owner);

        $this->getJson('/api/v1/my/products')
            ->assertOk()
            ->assertJsonCount(15, 'data')
            ->assertJsonPath('total', 16)
            ->assertJsonPath('per_page', 15)
            ->assertJsonPath('data.0.id', $ownedProducts[15]->id)
            ->assertJsonFragment(['id' => $ownedProducts[15]->id])
            ->assertJsonMissing(['id' => $otherUsersHiddenProduct->id])
            ->assertJsonMissing(['id' => $deletedProduct->id]);
    }

    public function test_user_with_no_products_gets_empty_list(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/my/products')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('total', 0);
    }
}
