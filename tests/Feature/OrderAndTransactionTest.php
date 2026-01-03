<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Product;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderAndTransactionTest extends TestCase
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

    public function test_can_create_order_from_cart()
    {
        $product = Product::factory()->create();
        $this->user->cart->items()->create(['product_id' => $product->id, 'quantity' => 2]);

        $response = $this->withToken($this->token)
            ->withHeader('Accept', 'application/json')
            ->postJson('/api/orders', [
                'shipping_address' => '123 Test St',
                'billing_address' => '123 Test St',
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('orders', ['user_id' => $this->user->id]);
        $this->assertDatabaseHas('order_items', ['product_id' => $product->id, 'quantity' => 2]);
        $this->assertDatabaseHas('transactions', ['order_id' => $response->json('order.id')]);
        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_can_get_order_details()
    {
        $order = Order::factory()->for($this->user)->create();
        OrderItem::factory()->for($order)->create();
        $order->transactions()->create(['amount' => 10.00]);

        $response = $this->withToken($this->token)
            ->withHeader('Accept', 'application/json')
            ->getJson("/api/orders/{$order->id}");

        $response->assertStatus(200);
        $response->assertJsonFragment(['id' => $order->id]);
    }

    public function test_order_status_updates_when_transaction_is_completed()
    {
        $order = Order::factory()->for($this->user)->create(['status' => 'pending']);
        $transaction = $order->transactions()->create(['amount' => 10.00]);

        $this->withToken($this->token)
            ->withHeader('Accept', 'application/json')
            ->patchJson("/api/transactions/{$transaction->id}", ['status' => 'completed']);

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'completed']);
    }
}
