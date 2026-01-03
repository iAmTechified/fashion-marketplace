<?php

namespace App\Http\Controllers;

use App\Events\TransactionCompleted;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use App\Mail\OrderConfirmationEmail;

class TransactionController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function adminIndex(Request $request)
    {
        $perPage = $request->input('per_page', 15);
        $query = Transaction::with(['order.user']);

        // Transactions list
        $transactions = $query->orderBy('created_at', 'desc')->paginate($perPage);

        // Stats
        $monthlyVolume = Transaction::where('status', 'success')
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->sum('amount');

        $yearlyVolume = Transaction::where('status', 'success')
            ->whereYear('created_at', now()->year)
            ->sum('amount');

        $pendingTransactions = Transaction::where('status', 'pending')->count();

        return response()->json([
            'transactions' => $transactions,
            'stats' => [
                'monthly_transaction_volume' => $monthlyVolume,
                'yearly_transaction_volume' => $yearlyVolume,
                'pending_transactions' => $pendingTransactions
            ]
        ]);
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        // Check if user is admin or getting their own transactions
        // For now, let's assume this return all transactions for the authenticated user (orders user) or vendors?
        // Usually transactions are linked to Orders.
        // Let's implement getting transactions for the current user's orders.

        $user = auth()->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $perPage = $request->input('per_page', 15);

        // If user is admin (check role if applicable), maybe show all? 
        // But for safe default, let's show transactions related to user's orders.

        $transactions = Transaction::whereHas('order', function ($q) use ($user) {
            $q->where('user_id', $user->id);
        })->with('order')->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json($transactions);
    }

    public function update(Request $request, Transaction $transaction)
    {
        $request->validate([
            'status' => 'required|string|in:pending,completed,failed',
        ]);

        $transaction->update(['status' => $request->status]);

        if ($request->status === 'completed') {
            event(new TransactionCompleted($transaction));
            Mail::to($transaction->order->user->email)->send(new OrderConfirmationEmail($transaction->order));
        }

        return response()->json(['message' => 'Transaction updated successfully.', 'transaction' => $transaction]);
    }
}
