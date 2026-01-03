<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use App\Models\VendorProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class VendorProfileController extends Controller
{
    use AuthorizesRequests;

    /**
     * Display a listing of the vendors.
     */
    public function index(Request $request)
    {
        $perPage = $request->input('per_page', 15);
        $vendors = VendorProfile::paginate($perPage);

        return response()->json($vendors);
    }

    public function store(Request $request)
    {
        $request->validate([
            'store_name' => 'required|string|unique:vendor_profiles',
            'store_description' => 'nullable|string',
            'store_logo' => 'nullable|string',
            'contact_email' => 'nullable|email',
            'phone_number' => 'nullable|string',
            'address' => 'nullable|string',
        ]);

        $user = Auth::user();

        if ($user->vendorProfile) {
            return response()->json(['message' => 'You already have a vendor profile.'], 400);
        }

        $vendorProfile = $user->vendorProfile()->create($request->all());

        return response()->json(['message' => 'Vendor profile created successfully.', 'vendor_profile' => $vendorProfile]);
    }

    public function show(VendorProfile $vendorProfile)
    {
        return response()->json(['vendor_profile' => $vendorProfile]);
    }

    public function update(Request $request, VendorProfile $vendorProfile)
    {
        $this->authorize('update', $vendorProfile);

        $request->validate([
            'store_name' => 'required|string|unique:vendor_profiles,store_name,' . $vendorProfile->id,
            'store_description' => 'nullable|string',
            'store_logo' => 'nullable|string',
            'contact_email' => 'nullable|email',
            'phone_number' => 'nullable|string',
            'address' => 'nullable|string',
        ]);

        $vendorProfile->update($request->all());

        return response()->json(['message' => 'Vendor profile updated successfully.', 'vendor_profile' => $vendorProfile]);
    }
}
