<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Staff;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Models\EmailVerification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use App\Services\EmailVerificationService;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class StaffController extends Controller
{
    /**
     * Staff login.
     */
    public function login(Request $request){
        try {
            // 1. Validate input
            $request->validate([
                'login' => 'required|string',
                'password' => 'required|string',
            ]);

            // 2. Create unique throttle key
            $throttleKey = Str::lower($request->input('login')) . '|' . $request->ip();

            // 3. Check if user is rate limited
            if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
                $seconds = RateLimiter::availableIn($throttleKey);
                return response()->json([
                    'message' => "Too many login attempts. Please try again in {$seconds} seconds."
                ], 429);
            }

            // 4. Find staff by email or staff_id
            $staff = Staff::where('email', $request->login)->orWhere('staff_id', $request->login)->first();

            // 5. Validate credentials
            if (!$staff || !Hash::check($request->password, $staff->password)) {

                RateLimiter::hit($throttleKey, 60); // lock attempt for 60 seconds

                throw ValidationException::withMessages([
                    'login' => ['Invalid login credentials.'],
                ]);
            }

            // 6. Email verification check
            if (!$staff->email_verified_at) {
                return response()->json([
                    'message' => 'Please verify your email before logging in.'
                ], 403);
            }

            // 7. Phone verification check
            if (!$staff->tel_verified_at) {
                return response()->json([
                    'message' => 'Please verify your phone number before logging in.'
                ], 403);
            }

            // 8. Clear rate limiter after successful login
            RateLimiter::clear($throttleKey);

            // 9. Remove all existing tokens
            $staff->tokens()->delete();

            // 10. Create new token
            $token = $staff->createToken('staff-token')->plainTextToken;

            return response()->json([
                'message' => 'Login successful.',
                'token' => $token,
                'staff' => $staff,
                'role' => $staff->role,
            ], 200);

        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Login failed.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }
    
    public function store(Request $request)
    {
        // 1. Validate input
        $validator = Validator::make($request->all(), [
            'firstname' => 'required|string|max:50',
            'middlename' => 'nullable|string|max:50',
            'surname' => 'required|string|max:50',

            'email' => 'required|email|unique:staffs,email',
            'tel' => [
                'required',
                'string',
                'unique:staffs,tel',
                'regex:/^(\+234|234|0)(70|80|81|90|91)\d{8}$/',
            ],

            'gender' => 'required|in:male,female,others',
            'profile_picture' => 'required|image|mimes:jpeg,png,jpg|max:2048',
            'date_of_birth' => 'required|date|before:today',

            'location' => 'required|string',
            'address' => 'required|string',

            'role' => 'required|in:admin,tutor,advisor',

            'inducted_by' => 'nullable|exists:staffs,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors(),
            ], 422);
        }

        // 2. Ensure inductor is admin (if provided)
        if ($request->inducted_by) {
            $inductor = Staff::where('id', $request->inducted_by)
                ->where('role', 'admin')
                ->first();

            if (!$inductor) {
                return response()->json([
                    'message' => 'Inductor must be an admin staff.',
                ], 400);
            }
        }

        DB::beginTransaction();

        try {
            // 3. Upload profile picture
            $picturePath = $request->file('profile_picture')
                ->store('staff_profile_pictures', 'public');

            // 4. Generate staff ID
            $staffCount = Staff::withTrashed()->count() + 1;
            $staffId = 'TC' . now()->format('ym') . str_pad($staffCount, 4, '0', STR_PAD_LEFT);

            // 5. Auto-generate password
            // $plainPassword = Str::random(12);

            // 6. Create staff (NOT committed yet)
            $staff = Staff::create([
                'staff_id' => $staffId,
                'firstname' => $request->firstname,
                'middlename' => $request->middlename,
                'surname' => $request->surname,
                'email' => $request->email,
                'tel' => $request->tel,
                'password' => Hash::make($staffId),
                'gender' => $request->gender,
                'profile_picture' => $picturePath,
                'date_of_birth' => $request->date_of_birth,
                'location' => $request->location,
                'address' => $request->address,
                'role' => $request->role,
                'inducted_by' => $request->inducted_by,
            ]);

            // 7. Send verifications (MUST succeed)
            app(EmailVerificationService::class)->send($staff);
            $this->sendPhoneOtp($staff->tel);

            DB::commit();

            return response()->json([
                'message' => 'Staff registered successfully. Verification required.',
                'staff' => $staff,
                'temporary_password' => $staffId, // remove later in production
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Staff registration failed.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Verify staff email.
     */
    public function verifyEmail(Request $request)
    {
        try {
            $request->validate([
                'token' => 'required|string',
            ]);

            $record = EmailVerification::where('token', $request->token)
                ->where('expires_at', '>', now())
                ->first();

            if (!$record) {
                return response()->json([
                    'message' => 'Invalid or expired verification link.',
                ], 400);
            }

            $staff = $record->verifiable;

            if (!$staff) {
                return response()->json([
                    'message' => 'Staff not found.',
                ], 404);
            }

            $staff->update([
                'email_verified_at' => now(),
            ]);

            $record->delete();

            return response()->json([
                'message' => 'Email verified successfully.',
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Email verification failed.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Resend staff email verification.
     */
    public function resendEmailVerification(Request $request)
    {
        try {
            $request->validate([
                'email' => 'required|email|exists:staffs,email',
            ]);

            $staff = Staff::where('email', $request->email)->first();

            if ($staff->email_verified_at) {
                return response()->json([
                    'message' => 'Email already verified.',
                ], 400);
            }
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to process request.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }

        DB::beginTransaction();
        try {
            app(EmailVerificationService::class)->send($staff);

            DB::commit();

            return response()->json([
                'message' => 'Verification email resent successfully.',
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Failed to resend verification email.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Send phone OTP to staff.
     */
    protected function sendPhoneOtp(string $tel): void
    {
        DB::beginTransaction();

        try {
            DB::table('phone_otps')->where('tel', $tel)->delete();

            $code = random_int(100000, 999999);

            $smsSent = true; // integrate real SMS later

            if (!$smsSent) {
                throw new \Exception('SMS sending failed');
            }

            DB::table('phone_otps')->insert([
                'tel' => $tel,
                'code' => Hash::make($code),
                'expires_at' => now()->addMinutes(10),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::commit();

            logger()->info("Staff OTP for {$tel}: {$code}");

        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Verify staff phone OTP.
     */
    public function verifyPhoneOtp(Request $request)
    {
        try {
            $request->validate([
                'tel' => 'required|string|exists:staffs,tel',
                'otp' => 'required|string',
            ]);

            $staff = Staff::where('tel', $request->tel)->first();

            if ($staff->tel_verified_at) {
                return response()->json([
                    'message' => 'Phone already verified.',
                ], 400);
            }

            $otp = DB::table('phone_otps')
                ->where('tel', $staff->tel)
                ->latest()
                ->first();

            if (!$otp || Carbon::parse($otp->expires_at)->isPast()) {
                return response()->json([
                    'message' => 'Invalid or expired OTP.',
                ], 400);
            }

            if (!Hash::check($request->otp, $otp->code)) {
                return response()->json([
                    'message' => 'Invalid OTP.',
                ], 400);
            }

            DB::transaction(function () use ($staff, $otp) {
                $staff->update([
                    'tel_verified_at' => now(),
                ]);

                DB::table('phone_otps')->where('tel', $otp->tel)->delete();
            });

            return response()->json([
                'message' => 'Phone verified successfully.',
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Phone verification failed.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Resend staff phone OTP.
     */
    public function resendPhoneOtp(Request $request)
    {
        try {
            $request->validate([
                'tel' => 'required|string|exists:staffs,tel',
            ]);

            $staff = Staff::where('tel', $request->tel)->first();

            if ($staff->tel_verified_at) {
                return response()->json([
                    'message' => 'Phone already verified.',
                ], 400);
            }

            // try {
            DB::beginTransaction();

            $this->sendPhoneOtp($staff->tel);

            DB::commit();

            return response()->json([
                'message' => 'OTP resent successfully.',
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Failed to resend OTP.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Staff logout.
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }
}
