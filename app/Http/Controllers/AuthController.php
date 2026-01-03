<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
use App\Mail\WelcomeEmail;
use App\Mail\OtpEmail;
use Carbon\Carbon;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        try {
            $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
                'password' => ['required', 'string', 'min:8', 'confirmed'],
                'role' => ['sometimes', 'string', 'in:customer,vendor'],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $e->errors(),
            ], 422);
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => $request->input('role', 'customer'), // Default role for new registrations
        ]);

        try {
            Mail::to($user->email)->send(new WelcomeEmail($user));
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to send welcome email: ' . $e->getMessage());
        }

        Auth::guard('web')->login($user);

        return response()->json([
            'message' => 'User registered successfully',
            'user' => $user,
            'role' => $user->role,
        ], 201);
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (Auth::guard('web')->attempt($credentials)) {
            $request->session()->regenerate();

            return response()->json([
                'message' => 'Logged in successfully',
                'user' => Auth::guard('web')->user(),
            ]);
        }

        return response()->json([
            'message' => 'The provided credentials do not match our records.',
        ], 401);
    }

    public function adminLogin(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (Auth::guard('web')->attempt($credentials)) {
            $user = Auth::guard('web')->user();

            if ($user->role !== 'admin') {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                return response()->json(['message' => 'Access denied. Admins only.'], 403);
            }

            $request->session()->regenerate();

            return response()->json([
                'message' => 'Welcome back, Admin',
                'user' => $user,
            ]);
        }

        return response()->json([
            'message' => 'The provided credentials do not match our records.',
        ], 401);

    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'message' => 'Logged out successfully',
        ]);
    }

    /**
     * Send OTP or Link for Password Reset.
     */
    public function forgotPassword(Request $request)
    {
        $user = null;

        // Check if user is logged in via Sanctum or Web
        if (Auth::guard('sanctum')->check()) {
            $user = Auth::guard('sanctum')->user();
        } elseif (Auth::guard('web')->check()) {
            $user = Auth::guard('web')->user();
        }

        if ($user) {
            // LOGGED IN: Send OTP
            // Generate OTP
            $otp = rand(100000, 999999);

            // Store OTP
            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $user->email],
                [
                    'email' => $user->email,
                    'token' => $otp,
                    'created_at' => Carbon::now()
                ]
            );

            // Send OTP Email
            try {
                Mail::to($user->email)->send(new OtpEmail($otp, 'Password Reset'));
            } catch (\Exception $e) {
                return response()->json(['message' => 'Unable to send OTP at this time.'], 500);
            }

            return response()->json(['message' => 'OTP sent to your email.'], 200);

        } else {
            // NOT LOGGED IN: Send Link
            $request->validate(['email' => 'required|email']);

            $user = User::where('email', $request->email)->first();

            if (!$user) {
                // Return fake success to avoid email enumeration
                return response()->json(['message' => 'If an account exists with this email, a reset link has been sent.'], 200);
            }

            // Generate Token
            $token = \Illuminate\Support\Str::random(60);

            // Store Token
            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $request->email],
                [
                    'email' => $request->email,
                    'token' => $token,
                    'created_at' => Carbon::now()
                ]
            );

            // Send Link Email
            // Assuming frontend URL is configurable or hardcoded. Using a placeholder or app_url
            // Ideally: config('app.frontend_url') . '/reset-password?token=' . $token . '&email=' . $request->email
            $frontendUrl = config('app.frontend_url', 'http://localhost:3000');
            $link = $frontendUrl . '/reset-password?token=' . $token . '&email=' . urlencode($request->email);

            try {
                Mail::to($user->email)->send(new \App\Mail\ResetPasswordLinkEmail($link));
            } catch (\Exception $e) {
                return response()->json(['message' => 'Unable to send reset link at this time.'], 500);
            }

            return response()->json(['message' => 'Password reset link sent to your email.'], 200);
        }
    }

    /**
     * Verify OTP or Token.
     */
    public function verifyOtp(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'token' => 'required' // Can be OTP or String Token
        ]);

        $record = DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->where('token', $request->token)
            ->first();

        if (!$record) {
            return response()->json(['message' => 'Invalid token or OTP.'], 400);
        }

        // Check if expired (15 minutes)
        if (Carbon::parse($record->created_at)->addMinutes(15)->isPast()) {
            DB::table('password_reset_tokens')->where('email', $request->email)->delete();
            return response()->json(['message' => 'Token/OTP has expired.'], 400);
        }

        return response()->json(['message' => 'Token/OTP verified successfully.']);
    }

    /**
     * Reset Password using OTP or Token.
     */
    public function resetPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'token' => 'required', // Unified field for OTP or Token
            'password' => 'required|string|min:8|confirmed'
        ]);

        // Verify Token/OTP
        $record = DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->where('token', $request->token)
            ->first();

        if (!$record) {
            return response()->json(['message' => 'Invalid or expired token/OTP.'], 400);
        }

        if (Carbon::parse($record->created_at)->addMinutes(15)->isPast()) {
            return response()->json(['message' => 'Token/OTP has expired.'], 400);
        }

        // Update Password
        $user = User::where('email', $request->email)->first();
        if ($user) {
            $user->password = Hash::make($request->password);
            $user->save();
        }

        // Delete the Token
        DB::table('password_reset_tokens')->where('email', $request->email)->delete();

        return response()->json(['message' => 'Password reset successfully. You can now login.']);
    }
}
