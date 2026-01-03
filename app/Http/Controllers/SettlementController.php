<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use App\Models\Settlement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use App\Mail\SettlementUpdateEmail;

class SettlementController extends Controller
{
    use AuthorizesRequests;

    public function adminIndex(Request $request)
    {
        $perPage = $request->input('per_page', 15);

        // Eager load order, customer (via order.user), vendor (via order.items...), settlement account (via vendor profile)
        $query = Settlement::with(['order.user', 'order.items.product.user.vendorProfile']);

        $settlements = $query->orderBy('created_at', 'desc')->paginate($perPage);

        // Stats
        $monthlyRevenue = \App\Models\Order::where(function ($q) {
            $q->where('status', 'paid')->orWhere('status', 'completed')->orWhere('status', 'completed & settled');
        })
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->sum('total_amount');

        $monthlySettlements = Settlement::where('status', 'paid')
            ->whereMonth('updated_at', now()->month)
            ->whereYear('updated_at', now()->year)
            ->sum('amount');

        $yearlyRevenue = \App\Models\Order::where(function ($q) {
            $q->where('status', 'paid')->orWhere('status', 'completed')->orWhere('status', 'completed & settled');
        })
            ->whereYear('created_at', now()->year)
            ->sum('total_amount');

        $yearlySettlements = Settlement::where('status', 'paid')
            ->whereYear('updated_at', now()->year)
            ->sum('amount');

        $pendingSettlements = Settlement::where('status', '!=', 'paid')->count();

        return response()->json([
            'settlements' => $settlements,
            'stats' => [
                'monthly_revenue' => $monthlyRevenue,
                'monthly_settlements' => $monthlySettlements,
                'yearly_revenue' => $yearlyRevenue,
                'yearly_settlements' => $yearlySettlements,
                'pending_settlements' => $pendingSettlements
            ]
        ]);
    }

    public function index()
    {
        $vendor = Auth::user();
        $perPage = request()->input('per_page', 15);
        $settlements = Settlement::whereHas('order.items.product', function ($query) use ($vendor) {
            $query->where('user_id', $vendor->id);
        })->with('order')->paginate($perPage);

        return response()->json($settlements);
    }

    public function update(Request $request, Settlement $settlement)
    {
        $this->authorize('update', $settlement);

        $request->validate([
            'status' => 'required|string|in:paid',
            'transaction_id' => 'required|string',
        ]);

        if ($settlement->order->status === 'completed' && $settlement->status === 'approved') {
            $settlement->update([
                'status' => 'paid',
                'transaction_id' => $request->transaction_id,
            ]);

            $settlement->order->update(['status' => 'completed & settled']);

            Mail::to($settlement->order->items->first()->product->user->email)->send(new SettlementUpdateEmail($settlement));

            return response()->json(['message' => 'Settlement paid successfully.', 'settlement' => $settlement]);
        }

        return response()->json(['message' => 'Settlement cannot be paid at this time.'], 400);
    }
}
