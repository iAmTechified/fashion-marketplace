<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Transaction;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use App\Mail\NewOrderEmail;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Mail\OrderConfirmationEmail;
use App\Mail\PaymentFailedEmail;

class OrderController extends Controller
{
    /**
     * Display a listing of all orders (Admin).
     */
    public function adminIndex(Request $request)
    {
        $perPage = $request->input('per_page', 15);
        $query = Order::with(['items.product.user', 'transactions', 'user']);

        // Optional: Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        $orders = $query->orderBy('created_at', 'desc')->paginate($perPage);

        // Stats
        $monthlyRevenue = Order::where(function ($q) {
            $q->where('status', 'paid')->orWhere('status', 'completed')->orWhere('status', 'completed & settled');
        })
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->sum('total_amount');

        $monthlySettlements = \App\Models\Settlement::where('status', 'paid')
            ->whereMonth('updated_at', now()->month)
            ->whereYear('updated_at', now()->year)
            ->sum('amount');

        $yearlyRevenue = Order::where(function ($q) {
            $q->where('status', 'paid')->orWhere('status', 'completed')->orWhere('status', 'completed & settled');
        })
            ->whereYear('created_at', now()->year)
            ->sum('total_amount');

        $yearlySettlements = \App\Models\Settlement::where('status', 'paid')
            ->whereYear('updated_at', now()->year)
            ->sum('amount');

        $pendingOrders = Order::where('status', 'pending')->count();

        return response()->json([
            'orders' => $orders,
            'stats' => [
                'monthly_revenue' => $monthlyRevenue,
                'monthly_settlements' => $monthlySettlements,
                'yearly_revenue' => $yearlyRevenue,
                'yearly_settlements' => $yearlySettlements,
                'pending_orders' => $pendingOrders
            ]
        ]);
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $user = auth('sanctum')->user();
        $guestId = $request->header('X-Guest-ID') ?? $request->input('guest_id');
        $perPage = $request->input('per_page', 15);

        $query = Order::query();

        if ($user) {
            // Sync logic: Claim guest orders associated with the guest ID
            if ($guestId) {
                Order::where('guest_id', $guestId)
                    ->whereNull('user_id')
                    ->update(['user_id' => $user->id, 'guest_id' => null]);
            }

            $query->where('user_id', $user->id);
        } elseif ($guestId) {
            $query->where('guest_id', $guestId)->whereNull('user_id');
        } else {
            // If neither authenticated nor guest ID provided, return empty
            return response()->json(['data' => []]);
        }

        $orders = $query->with(['items.product', 'transactions'])
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return response()->json($orders);
    }

    public function store(Request $request)
    {
        $user = auth('sanctum')->user();

        $request->validate([
            'shipping_address' => 'required|string',
            'billing_address' => 'required|string',
            'email' => $user ? 'nullable|email' : 'required|email',
        ]);

        $guestId = $request->header('X-Guest-ID') ?? $request->input('guest_id');
        $cartId = $request->header('X-Cart-ID') ?? $request->input('cart_id');

        // Retrieve Cart
        $cart = null;
        if ($user) {
            $cart = $user->cart;
        } elseif ($cartId) {
            $cart = \App\Models\Cart::where('id', $cartId)->whereNull('user_id')->first();
        }

        if (!$cart || $cart->items->isEmpty()) {
            return response()->json(['message' => 'Your cart is empty.'], 400);
        }

        return DB::transaction(function () use ($request, $user, $cart, $guestId) {
            $orderItemsData = [];
            $totalAmount = 0;

            foreach ($cart->items as $item) {
                // Lock product for update to prevent race conditions
                $product = Product::where('id', $item->product_id)->lockForUpdate()->first();

                // 1. Validate Status
                if (!$product || $product->status !== 'available' || $product->approval_status !== 'approved') {
                    throw ValidationException::withMessages([
                        'cart' => ["Product '{$item->product->name}' is no longer available."]
                    ]);
                }

                // 2. Validate & Adjust Stock
                $quantity = $item->quantity;
                if ($quantity > $product->stock) {
                    $quantity = $product->stock;
                }

                if ($quantity <= 0) {
                    throw ValidationException::withMessages([
                        'cart' => ["Product '{$product->name}' is out of stock."]
                    ]);
                }

                // 3. Deduct Stock Immediately
                $product->stock -= $quantity; // Use direct property or decrement
                $product->save();

                $totalAmount += $product->price * $quantity;

                $orderItemsData[] = [
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'price' => $product->price,
                ];
            }

            $order = Order::create([
                'user_id' => $user ? $user->id : null,
                'guest_id' => $user ? null : $guestId,
                'email' => $user ? $user->email : $request->email,
                'total_amount' => $totalAmount,
                'status' => 'pending',
                'shipping_address' => $request->shipping_address,
                'billing_address' => $request->billing_address,
            ]);

            foreach ($orderItemsData as $data) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $data['product_id'],
                    'quantity' => $data['quantity'],
                    'price' => $data['price'],
                ]);
            }

            // Generate unique transaction reference
            $reference = 'ORD-' . Str::random(10) . '-' . time();

            // Create Transaction record
            $transaction = $order->transactions()->create([
                'transaction_id' => $reference,
                'status' => 'pending',
                'amount' => $totalAmount,
            ]);

            $cart->items()->delete();
            // Optional: Delete cart itself if it's no longer needed, or keep empty
            // $cart->delete(); 

            // Send new order email to vendors
            // Recalculate vendors based on actual items ordered
            $productIds = array_column($orderItemsData, 'product_id');
            $vendors = Product::whereIn('id', $productIds)->with('user')->get()->map(function ($p) {
                return $p->user;
            })->unique('id');

            try {
                foreach ($vendors as $vendor) {
                    Mail::to($vendor->email)->send(new NewOrderEmail($order));
                }
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Failed to send order email: ' . $e->getMessage());
            }

            return response()->json([
                'message' => 'Order created successfully.',
                'order' => $order,
                'transaction' => $transaction,
                'paystack' => [
                    'key' => env('PAYSTACK_PUBLIC_KEY'),
                    'email' => $order->email, // Use order email (which is either user email or guest email)
                    'amount' => $totalAmount * 100, // Amount in kobo
                    'ref' => $reference,
                ]
            ]);
        });
    }

    public function verifyPayment(Request $request)
    {
        $request->validate([
            'reference' => 'required|string',
        ]);

        $reference = $request->reference;
        $transaction = Transaction::where('transaction_id', $reference)->with('order')->firstOrFail();

        if ($transaction->status === 'success') {
            return response()->json(['message' => 'Transaction already verified.', 'order' => $transaction->order]);
        }

        // Verify with Paystack
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . env('PAYSTACK_SECRET_KEY'),
            'Cache-Control' => 'no-cache',
        ])->get("https://api.paystack.co/transaction/verify/{$reference}");

        $result = $response->json();

        if ($result['status'] && $result['data']['status'] === 'success') {
            // Payment successful
            DB::transaction(function () use ($transaction) {
                $transaction->update(['status' => 'success']);
                $transaction->order->update(['status' => 'paid']);
            });

            try {
                $recipientEmail = $transaction->order->email; // Use stored email or user email from order
                Mail::to($recipientEmail)->send(new OrderConfirmationEmail($transaction->order));
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Failed to send order confirmation email: ' . $e->getMessage());
            }

            return response()->json([
                'message' => 'Payment successful',
                'status' => 'success',
                'order' => $transaction->order->fresh(),
            ]);
        } else {
            // Payment failed
            DB::transaction(function () use ($transaction) {
                $transaction->update(['status' => 'failed']);
                $transaction->order->update(['status' => 'failed']);

                // Restore Stock
                $transaction->order->load('items.product');
                foreach ($transaction->order->items as $item) {
                    if ($item->product) {
                        $item->product->increment('stock', $item->quantity);
                    }
                }
            });

            try {
                $recipientEmail = $transaction->order->email;
                Mail::to($recipientEmail)->send(new PaymentFailedEmail($transaction->order));
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Failed to send payment failed email: ' . $e->getMessage());
            }

            return response()->json([
                'message' => 'Payment verification failed',
                'status' => 'failed',
                'data' => $result
            ], 400);
        }
    }

    public function show(Request $request, Order $order)
    {
        $user = auth('sanctum')->user();
        $guestId = $request->header('X-Guest-ID') ?? $request->input('guest_id');

        // Check authorization
        if ($user) {
            if ($order->user_id !== $user->id) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
        } else {
            if (!$order->guest_id || $order->guest_id !== $guestId) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
        }

        $order->load('items.product', 'transactions');

        return response()->json(['order' => $order]);
    }

    public function adminShow(Order $order)
    {
        $order->load('items.product', 'transactions', 'user');
        return response()->json(['order' => $order]);
    }
}
