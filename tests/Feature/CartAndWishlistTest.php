<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CartAndWishlistTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->user->cart()->create();
        $this->token = $this->user->createToken('test-token')->plainTextToken;
    }

    public function test_product_can_be_added_to_cart()
    {
        $product = Product::factory()->create();

        $this->withToken($this->token)
            ->withHeader('Accept', 'application/json')
            ->patchJson("/api/cart/{$product->id}", ['quantity' => 2]);

        $this->assertDatabaseHas('cart_items', [
            'product_id' => $product->id,
            'quantity' => 2,
        ]);
    }

    public function test_product_can_be_removed_from_cart_and_added_to_wishlist()
    {
        $product = Product::factory()->create();

        $this->withToken($this->token)
            ->withHeader('Accept', 'application/json')
            ->patchJson("/api/cart/{$product->id}", ['quantity' => 1]);

        $this->withToken($this->token)
            ->withHeader('Accept', 'application/json')
            ->deleteJson("/api/cart/{$product->id}");

        $this->assertDatabaseMissing('cart_items', [
            'product_id' => $product->id,
        ]);

        $this->assertDatabaseHas('wishlists', [
            'user_id' => $this->user->id,
            'product_id' => $product->id,
        ]);
    }

    public function test_product_can_be_added_to_wishlist()
    {
        $product = Product::factory()->create();

        $this->withToken($this->token)
            ->withHeader('Accept', 'application/json')
            ->postJson("/api/wishlist/{$product->id}");

        $this->assertDatabaseHas('wishlists', [
            'user_id' => $this->user->id,
            'product_id' => $product->id,
        ]);
    }
}
