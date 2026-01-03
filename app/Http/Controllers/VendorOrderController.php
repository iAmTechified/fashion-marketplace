<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use App\Models\Order;
use App\Models\Settlement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use App\Mail\OrderProgressEmail;

class VendorOrderController extends Controller
{
    use AuthorizesRequests;
    public function index()
    {
        $vendor = Auth::user();
        $perPage = request()->input('per_page', 15);
        $orders = Order::whereHas('items.product', function ($query) use ($vendor) {
            $query->where('user_id', $vendor->id);
        })->with('items.product')->paginate($perPage);

        return response()->json($orders);
    }

    public function show(Order $order)
    {
        $this->authorize('view', $order);

        $order->load('items.product', 'transactions');

        return response()->json(['order' => $order]);
    }

    public function update(Request $request, Order $order)
    {
        $this->authorize('update', $order);

        $request->validate([
            'status' => 'required|string|in:pending,processing,shipped,done,canceled',
            'tracking_number' => 'nullable|string',
        ]);

        $order->update($request->only('status', 'tracking_number'));

        if ($request->status === 'done') {
            Settlement::create([
                'order_id' => $order->id,
                'amount' => $order->total_amount,
                'status' => 'pending',
            ]);
        }

        Mail::to($order->user->email)->send(new OrderProgressEmail($order));

        return response()->json(['message' => 'Order updated successfully.', 'order' => $order]);
    }
}
