<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Order;
use App\Models\Product;
use App\Models\VendorProfile;
use App\Models\Settlement;
use App\Models\AccountSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Illuminate\Support\Facades\Hash;

class VendorAndAccountTest extends TestCase
{
    use RefreshDatabase;

    protected $vendor;
    protected $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->vendor = User::factory()->create();
        $this->token = $this->vendor->createToken('test-token')->plainTextToken;
    }

    public function test_can_create_vendor_profile()
    {
        $response = $this->withToken($this->token)
            ->withHeader('Accept', 'application/json')
            ->postJson('/api/vendor-profiles', [
                'store_name' => 'Test Store',
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('vendor_profiles', ['store_name' => 'Test Store']);
    }

    public function test_can_get_vendor_orders()
    {
        $order = Order::factory()->create();
        $product = Product::factory()->for($this->vendor)->create();
        $order->items()->create(['product_id' => $product->id, 'quantity' => 1, 'price' => 10.00]);

        $response = $this->withToken($this->token)
            ->withHeader('Accept', 'application/json')
            ->getJson('/api/vendor/orders');

        $response->assertStatus(200);
        $response->assertJsonFragment(['id' => $order->id]);
    }

    public function test_can_update_order_status_and_create_settlement()
    {
        $order = Order::factory()->create();
        $product = Product::factory()->for($this->vendor)->create();
        $order->items()->create(['product_id' => $product->id, 'quantity' => 1, 'price' => 10.00]);

        $response = $this->withToken($this->token)
            ->withHeader('Accept', 'application/json')
            ->patchJson("/api/vendor/orders/{$order->id}", ['status' => 'done']);

        $response->assertStatus(200);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'done']);
        $this->assertDatabaseHas('settlements', ['order_id' => $order->id, 'status' => 'pending']);
    }

    public function test_can_update_settlement_and_order_status()
    {
        $order = Order::factory()->create(['status' => 'completed']);
        $product = Product::factory()->for($this->vendor)->create();
        $order->items()->create(['product_id' => $product->id, 'quantity' => 1, 'price' => 10.00]);
        $settlement = Settlement::create(['order_id' => $order->id, 'amount' => 10.00, 'status' => 'approved']);

        $response = $this->withToken($this->token)
            ->withHeader('Accept', 'application/json')
            ->patchJson("/api/settlements/{$settlement->id}", [
                'status' => 'paid',
                'transaction_id' => 'txn_123',
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('settlements', ['id' => $settlement->id, 'status' => 'paid']);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'completed & settled']);
    }

    public function test_can_update_account_settings()
    {
        $response = $this->withToken($this->token)
            ->withHeader('Accept', 'application/json')
            ->putJson('/api/account-settings', [
                'store_status' => 'inactive',
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('account_settings', ['user_id' => $this->vendor->id, 'store_status' => 'inactive']);
    }

    public function test_can_update_password()
    {
        $response = $this->withToken($this->token)
            ->withHeader('Accept', 'application/json')
            ->putJson('/api/account-settings/password', [
                'current_password' => 'password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ]);

        $response->assertStatus(200);
        $this->assertTrue(Hash::check('new-password', $this->vendor->fresh()->password));
    }
}
