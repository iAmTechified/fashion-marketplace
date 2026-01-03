<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\VendorProfile;
use App\Models\Product;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Facades\DB;

class AdminDashboardController extends Controller
{
    /**
     * Get overall statistics for the admin dashboard.
     */
    public function index(Request $request)
    {
        if ($request->user()->role !== 'admin') {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $stats = [
            'users' => [
                'total' => User::count(),
                'customers' => User::where('role', 'customer')->count(), // Assuming 'customer' role exists or defaults
                'vendors' => User::where('role', 'vendor')->count(),
                'new_this_month' => User::whereMonth('created_at', now()->month)->count(),
            ],
            'products' => [
                'total' => Product::count(),
                'active' => Product::where('status', 'available')->count(),
                'out_of_stock' => Product::where('stock', 0)->count(),
                'low_stock' => Product::where('stock', '<', 6)->count(),
            ],
            'orders' => [
                'total' => Order::count(),
                'pending' => Order::where('status', 'pending')->count(),
                'completed' => Order::where('status', 'completed')->count(),
                'total_revenue' => Order::where('status', 'completed')->sum('total_amount'), // Assuming 'total_amount' column
            ],
            'sales' => [
                'total_volume' => OrderItem::sum('quantity'),
                'monthly_volume' => OrderItem::whereMonth('created_at', now()->month)->sum('quantity'),
            ],
        ];

        return response()->json($stats);
    }
}
