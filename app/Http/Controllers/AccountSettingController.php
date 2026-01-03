<?php

namespace App\Http\Controllers;

use App\Models\AccountSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AccountSettingController extends Controller
{
    public function show()
    {
        $user = Auth::user();
        $accountSetting = $user->accountSetting()->firstOrCreate(['user_id' => $user->id]);

        return response()->json(['account_setting' => $accountSetting]);
    }

    public function update(Request $request)
    {
        $request->validate([
            'settlement_account_details' => 'nullable|array',
            'store_status' => 'nullable|string|in:active,inactive,suspended',
        ]);

        $user = Auth::user();
        $accountSetting = $user->accountSetting()->firstOrCreate(['user_id' => $user->id]);
        $accountSetting->update($request->all());

        return response()->json(['message' => 'Account settings updated successfully.', 'account_setting' => $accountSetting]);
    }

    public function updatePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required|string',
            'password' => 'required|string|confirmed|min:8',
        ]);

        $user = Auth::user();

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json(['message' => 'Current password does not match.'], 400);
        }

        $user->update(['password' => Hash::make($request->password)]);

        return response()->json(['message' => 'Password updated successfully.']);
    }
}
