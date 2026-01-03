<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\VendorProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use App\Mail\VendorWelcomeEmail;
use Illuminate\Validation\ValidationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class AdminVendorController extends Controller
{
    use AuthorizesRequests;

    /**
     * Display a listing of vendors (Admin only).
     */
    public function index(Request $request)
    {
        if ($request->user()->role !== 'admin') {
            return response()->json(['message' => 'Unauthorized. Only admins can view vendors.'], 403);
        }

        $perPage = $request->input('per_page', 15);

        // Fetch paginated vendors with user and product count
        $vendors = VendorProfile::with([
            'user' => function ($query) {
                $query->withCount('products');
            }
        ])->paginate($perPage);

        // Transform to include total_products directly
        $vendors->getCollection()->transform(function ($vendor) {
            $vendor->total_products = $vendor->user ? $vendor->user->products_count : 0;
            return $vendor;
        });

        // Calculate Stats
        $stats = [
            'total_vendors' => VendorProfile::count(),
            'monthly_new_vendors' => VendorProfile::whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->count(),
            'yearly_new_vendors' => VendorProfile::whereYear('created_at', now()->year)->count(),
        ];

        return response()->json([
            'data' => $vendors,
            'stats' => $stats
        ]);
    }

    /**
     * Create a new vendor with Paystack subaccount (Admin only).
     */
    public function store(Request $request)
    {
        \Illuminate\Support\Facades\Log::info('Vendor Store Request Input:', $request->except('password', 'password_confirmation'));
        \Illuminate\Support\Facades\Log::info('Vendor Store Request Files:', $request->allFiles());

        $this->authorize('create', VendorProfile::class); // Ensure user is admin (requires Policy update or Middleware)
        // Alternatively, check role here if Policy isn't set up for "create vendor by admin"
        if ($request->user()->role !== 'admin') {
            return response()->json(['message' => 'Unauthorized. Only admins can create vendors.'], 403);
        }

        // Fix for frontend potentially sending 'store_logo' as a string/text when no file is selected
        // if ($request->has('store_logo') && !$request->hasFile('store_logo')) {
        //     $request->request->remove('store_logo');
        // }

        $request->validate([
            // User Details
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',

            // Vendor Details
            'store_name' => 'required|string|unique:vendor_profiles',
            'store_description' => 'nullable|string',
            'store_logo' => 'nullable|image|max:2048',
            'phone_number' => 'nullable|string',
            'address' => 'nullable|string',

            // Paystack / Bank Details
            'account_number' => 'required|string',
            'settlement_bank' => 'required|string', // Bank Code (e.g., "058")
            'bank_name' => 'required|string', // Bank Name (e.g., "GTBank")
            'account_name' => 'required|string',
            'percentage_charge' => 'nullable|numeric|min:0|max:100',
        ]);

        try {
            return DB::transaction(function () use ($request) {
                // 1. Create User
                $user = User::create([
                    'name' => $request->name,
                    'email' => $request->email,
                    'password' => Hash::make($request->password),
                    'role' => 'vendor',
                ]);

                // 2. Create Paystack Subaccount
                $subaccountCode = null;
                $paystackSecret = env('PAYSTACK_SECRET_KEY');

                if (!$paystackSecret) {
                    throw new \Exception('Paystack Secret Key not configured.');
                }

                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $paystackSecret,
                    'Content-Type' => 'application/json',
                ])->withOptions(['verify' => false])->post('https://api.paystack.co/subaccount', [
                            'business_name' => $request->store_name,
                            'settlement_bank' => $request->settlement_bank,
                            'account_number' => $request->account_number,
                            'percentage_charge' => $request->percentage_charge ?? 0,
                            'description' => $request->store_description ?? 'Vendor on Fashion Marketplace',
                            'primary_contact_email' => $user->email,
                            'primary_contact_name' => $user->name,
                        ]);

                $result = $response->json();

                if (!$response->successful() || !($result['status'] ?? false)) {
                    // Start manually throwing validation exception to show field error if possible, 
                    // or just generic error.
                    $msg = $result['message'] ?? 'Unknown error from Paystack';
                    throw new \Exception("Paystack Error: " . $msg);
                }

                $subaccountCode = $result['data']['subaccount_code'];

                // Handle Image Upload
                $logoPath = null;
                if ($request->hasFile('store_logo')) {
                    $logoPath = $request->file('store_logo')->store('vendor_logos', 'public');
                }

                // 3. Create Vendor Profile
                $vendor = VendorProfile::create([
                    'user_id' => $user->id,
                    'store_name' => $request->store_name,
                    'store_description' => $request->store_description,
                    'store_logo' => $logoPath,
                    'contact_email' => $request->email, // Use user email if not provided separately, or $request->input('contact_email', $user->email)
                    'phone_number' => $request->phone_number,
                    'address' => $request->address,
                    'subaccount_code' => $subaccountCode,
                    'bank_name' => $request->bank_name,
                    'account_number' => $request->account_number,
                    'account_name' => $request->account_name,
                    'settlement_bank' => $request->settlement_bank,
                    'percentage_charge' => $request->percentage_charge,
                ]);

                // 4. Send Welcome Email
                try {
                    Mail::to($user->email)->send(new VendorWelcomeEmail($user));
                } catch (\Exception $e) {
                    // Log email error, but don't fail transaction
                    \Illuminate\Support\Facades\Log::error('Failed to send vendor welcome email: ' . $e->getMessage());
                }

                return response()->json([
                    'message' => 'Vendor created successfully with Paystack subaccount.',
                    'vendor' => $vendor->load('user'),
                ], 201);
            });

        } catch (\Exception $e) {
            // Handle transaction failure
            return response()->json([
                'message' => 'Failed to create vendor.',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Update vendor details (Admin only).
     * Handles Paystack subaccount updates if bank details change.
     */
    public function update(Request $request, VendorProfile $vendorProfile)
    {
        // $this->authorize('update', $vendorProfile); // Or specific admin permission check
        if ($request->user()->role !== 'admin') {
            return response()->json(['message' => 'Unauthorized. Only admins can update vendors.'], 403);
        }

        $request->validate([
            'store_name' => 'required|string|unique:vendor_profiles,store_name,' . $vendorProfile->id,
            'store_description' => 'nullable|string',
            'store_logo' => 'nullable|image|max:2048',
            'phone_number' => 'nullable|string',
            'address' => 'nullable|string',
            // Bank details validation
            'account_number' => 'sometimes|string',
            'settlement_bank' => 'sometimes|string',
            'bank_name' => 'sometimes|string',
            'account_name' => 'sometimes|string',
            'percentage_charge' => 'nullable|numeric|min:0|max:100',
        ]);

        try {
            return DB::transaction(function () use ($request, $vendorProfile) {
                $paystackSecret = env('PAYSTACK_SECRET_KEY');

                // Check if Paystack update is needed
                $requiresPaystackUpdate = false;
                $paystackData = [];

                if ($request->has('store_name') && $request->store_name !== $vendorProfile->store_name) {
                    $paystackData['business_name'] = $request->store_name;
                    $requiresPaystackUpdate = true;
                }

                if ($request->has('settlement_bank') && $request->settlement_bank !== $vendorProfile->settlement_bank) {
                    $paystackData['settlement_bank'] = $request->settlement_bank;
                    $requiresPaystackUpdate = true;
                }

                if ($request->has('account_number') && $request->account_number !== $vendorProfile->account_number) {
                    $paystackData['account_number'] = $request->account_number;
                    $requiresPaystackUpdate = true;
                }

                if ($request->has('percentage_charge') && $request->percentage_charge != $vendorProfile->percentage_charge) { // Loose comparison for float/string
                    $paystackData['percentage_charge'] = $request->percentage_charge;
                    $requiresPaystackUpdate = true;
                }

                // Always update description if provided, though not critical
                if ($request->has('store_description')) {
                    $paystackData['description'] = $request->store_description;
                }

                if ($requiresPaystackUpdate && $vendorProfile->subaccount_code) {
                    if (!$paystackSecret) {
                        throw new \Exception('Paystack Secret Key not configured.');
                    }

                    // Update Subaccount on Paystack
                    $response = Http::withHeaders([
                        'Authorization' => 'Bearer ' . $paystackSecret,
                        'Content-Type' => 'application/json',
                    ])->withOptions(['verify' => false])->put("https://api.paystack.co/subaccount/{$vendorProfile->subaccount_code}", $paystackData);

                    $result = $response->json();

                    if (!$response->successful() && isset($result['status']) && !$result['status']) {
                        // Some fields might not be updatable seamlessly (e.g. if account verification fails)
                        // But Paystack PUT /subaccount/{id} usually allows updating bank details.
                        $msg = $result['message'] ?? 'Unknown error from Paystack update';
                        throw new \Exception("Paystack Update Error: " . $msg);
                    }
                }

                // Update local profile
                $data = $request->all();

                if ($request->hasFile('store_logo')) {
                    if ($vendorProfile->store_logo) {
                        \Illuminate\Support\Facades\Storage::disk('public')->delete($vendorProfile->store_logo);
                    }
                    $data['store_logo'] = $request->file('store_logo')->store('vendor_logos', 'public');
                }

                $vendorProfile->update($data);

                return response()->json([
                    'message' => 'Vendor updated successfully.',
                    'vendor' => $vendorProfile
                ]);
            });
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to update vendor.',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Get a specific vendor's details (Admin only).
     */
    public function show(VendorProfile $vendorProfile)
    {
        return response()->json([
            'vendor' => $vendorProfile->load('user'),
        ]);
    }

    /**
     * Get list of banks for usage in dropdowns.
     */
    public function getBanks()
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . env('PAYSTACK_SECRET_KEY'),
            ])->withOptions(['verify' => false])->get('https://api.paystack.co/bank', [
                        'currency' => 'NGN' // Default to Nigeria, can be dynamic
                    ]);

            return response()->json($response->json());
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error($e->getMessage());
            return response()->json(['message' => 'Failed to fetch banks'], 500);
        }
    }


    /**
     * Resolve account number using Paystack.
     */
    public function resolveAccount(Request $request)
    {
        $request->validate([
            'account_number' => 'required|string',
            'bank_code' => 'required|string',
        ]);

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . env('PAYSTACK_SECRET_KEY'),
            ])->withOptions(['verify' => false])->get('https://api.paystack.co/bank/resolve', [
                        'account_number' => $request->account_number,
                        'bank_code' => $request->bank_code,
                    ]);

            return response()->json($response->json());
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to resolve account: ' . $e->getMessage()
            ], 500);
        }
    }
}
