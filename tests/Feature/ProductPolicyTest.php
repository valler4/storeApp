<?php

namespace Tests\Feature;

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

    private function makeProduct(User $owner, bool $isHidden = false): Product
    {
        return Product::create([
            'user_id'     => $owner->id,
            'name'        => 'Test',
            'description' => 'Desc',
            'price'       => 10,
            'stock'       => 5,
            'main_image'  => 'products/x.jpg',
            'is_active'   => true,
            'is_hidden'   => $isHidden,
        ]);
    }

    private function updatePayload(string $name = 'New'): array
    {
        return [
            'name'        => $name,
            'description' => 'D',
            'price'       => 5,
            'stock'       => 1,
            'is_active'   => 1,
            'is_hidden'   => 0,
        ];
    }

    public function test_user_can_create_product(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/products', [
            'name'        => 'Phone',
            'description' => 'Nice',
            'price'       => 100,
            'stock'       => 3,
            'main_image'  => UploadedFile::fake()->image('a.jpg'),
            'is_active'   => 1,
            'is_hidden'   => 0,
        ])->assertCreated();

        $this->assertDatabaseHas('products', [
            'name'    => 'Phone',
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

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
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
        $product = $this->makeProduct(User::factory()->create());
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->deleteJson("/api/v1/products/{$product->id}")->assertOk();

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
    }
}
