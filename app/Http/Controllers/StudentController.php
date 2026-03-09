<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Student;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Models\EmailVerification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use App\Services\EmailVerificationService;
use Illuminate\Support\Facades\RateLimiter;
use App\Notifications\StudentEmailVerification;


class StudentController extends Controller
{
    /**
     * Summary of store
     **/
    public function store(Request $request)
    {
        // 1. Validate input
        $validator = Validator::make($request->all(), [
            'email' => 'nullable|email|unique:students,email|required_without:tel',
            'tel' => [
                'nullable',
                'string',
                'unique:students,tel',
                'required_without:email',
                'regex:/^(\+234|234|0)(70|80|81|90|91)\d{8}$/',
            ],
            'password' => [
                'required',
                'string',
                'min:8',
                'same:confirmPassword',
                'regex:/[a-z]/',
                'regex:/[A-Z]/',
                'regex:/[0-9]/',
                'regex:/[@$!%*#?&]/',
            ],
            'confirmPassword' => [
                'required',
                'string',
                'min:8',
                'same:password',
                'regex:/[a-z]/',
                'regex:/[A-Z]/',
                'regex:/[0-9]/',
                'regex:/[@$!%*#?&]/',
            ],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors(),
                'message' => 'Registration failed.',
            ], 422);
        }

        DB::beginTransaction();

        try {
            // 2. Create student (NOT committed yet)
            $student = Student::create([
                'email' => $request->email,
                'tel' => $request->tel,
                'password' => Hash::make($request->password),
            ]);

            // 3. Verification logic (must succeed)
            if ($student->email) {
                // $this->sendEmailVerification($student); // must throw on failure
                app(EmailVerificationService::class)->send($student);
            }

            if ($student->tel) {
                $this->sendPhoneOtp($student->tel); // must throw on failure
            }

            // 4. All good → commit
            DB::commit();

            return response()->json([
                'message' => 'Registration successful. Verification required.',
                'student' => $student,
            ], 201);

        } catch (\Throwable $e) {
            // 5. Something failed → rollback
            DB::rollBack();

            return response()->json([
                'message' => 'Registration failed. Verification could not be sent.',
                'errors' => config('app.debug') ? $e->getMessage() : null,
                // 'errors' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Summary of sendEmailVerification
     **/
    protected function sendEmailVerification(Model $user): void
    {
        // Remove existing verification records
        EmailVerification::where('verifiable_type', get_class($user))
            ->where('verifiable_id', $user->id)
            ->delete();

        $token = Str::uuid();

        EmailVerification::create([
            'verifiable_type' => get_class($user),
            'verifiable_id' => $user->id,
            'token' => $token,
            'expires_at' => now()->addMinutes(30),
        ]);

        $user->notify(new StudentEmailVerification($token));
    }

    /**
     * Summary of verifyEmail
     **/
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

            $user = $record->verifiable;

            if (!$user) {
                return response()->json([
                    'message' => 'User not found for this verification token.',
                ], 404);
            }

            $user->update([
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
     * Summary of resendEmailVerification
     **/
    public function resendEmailVerification(Request $request)
    {
        DB::beginTransaction();
        try {
            $request->validate([
                'email' => 'required|email|exists:students,email',
            ]);

            $student = Student::where('email', $request->email)->first();

            if (!$student) {
                return response()->json([
                    'message' => 'Student not found.',
                ], 404);
            }

            if ($student->email_verified_at) {
                return response()->json([
                    'message' => 'Email is already verified.',
                ], 400);
            }


            EmailVerification::where('verifiable_type', Student::class)
                ->where('verifiable_id', $student->id)
                ->delete();

            app(EmailVerificationService::class)->send($student);

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
     * Summary of sendPhoneOtp
     **/
    protected function sendPhoneOtp(string $tel): void
    {
        DB::beginTransaction();

        try {
            // 1. Delete any existing OTPs for this phone
            DB::table('phone_otps')
                ->where('tel', $tel)
                ->delete();

            // 2. Generate OTP
            $code = random_int(100000, 999999);

            $message = "Your verification code is {$code}. It expires in 10 minutes.";

            /**
             * 3. Send SMS (SIMULATED)
             * Replace this block when integrating real SMS provider
             */
            $smsSent = true; // simulate success

            // Example real usage later:
            // $smsSent = SmsService::send($tel, $message);

            if (!$smsSent) {
                throw new \Exception('SMS sending failed');
            }

            // 4. Save OTP ONLY if SMS was sent
            DB::table('phone_otps')->insert([
                'tel' => $tel,
                'code' => Hash::make($code),
                'expires_at' => Carbon::now()->addMinutes(10),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::commit();

            // TEMP: log instead of sending SMS
            logger()->info("OTP for {$tel} is {$code}");

        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e; // Let controller decide response
        }
    }

    /**
     * Summary of verifyPhoneOtp
     **/
    public function verifyPhoneOtp(Request $request)
    {
        try {
            $request->validate([
                'tel' => 'required|string|exists:students,tel',
                'otp' => 'required|string',
            ]);

            $student = Student::where('tel', $request->tel)->first();

            if ($student->tel_verified_at) {
                return response()->json([
                    'message' => 'Phone number already verified.',
                ], 400);
            }

            $otpRecord = DB::table('phone_otps')->where('tel', $student->tel)->latest()->first();

            if (!$otpRecord) {
                return response()->json([
                    'message' => 'OTP not found.',
                ], 400);
            }

            if (Carbon::parse($otpRecord->expires_at)->isPast()) {
                return response()->json([
                    'message' => 'OTP expired.',
                ], 400);
            }

            // if ($otpRecord->expires_at->isPast()) {
            //     return response()->json([
            //         'message' => 'OTP expired.',
            //     ], 400);
            // }

            if (!Hash::check($request->otp, $otpRecord->code)) {
                return response()->json([
                    'message' => 'Invalid OTP.',
                ], 400);
            }

            DB::transaction(function () use ($student, $otpRecord) {
                $student->update([
                    'tel_verified_at' => now(),
                ]);

                DB::table('phone_otps')->where('tel', $otpRecord->tel)->delete();
            });

            return response()->json([
                'message' => 'Phone number verified successfully.',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Phone verification failed.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Summary of resendPhoneOtp
     **/
    public function resendPhoneOtp(Request $request)
    {
        try {
            $request->validate([
                'tel' => 'required|string|exists:students,tel',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Validation failed.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 422);
        }

        $student = Student::where('tel', $request->tel)->first();
        if (!$student) {
            return response()->json([
                'message' => 'Student not found.',
            ], 404);
        }

        if ($student->tel_verified_at) {
            return response()->json([
                'message' => 'Phone number already verified.',
            ], 400);
        }



        try {
            DB::beginTransaction();

            $this->sendPhoneOtp($student->tel);
            DB::commit();

            return response()->json([
                'message' => 'OTP sent successfully.',
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Failed to send OTP. Try again.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Summary of completeBiodata
     **/
    public function biodata(Request $request)
    {
        // 1. Validate input
        $validator = Validator::make($request->all(), [
            'firstname' => 'required|string|max:50',
            'surname' => 'required|string|max:50',

            'email' => 'nullable|email|exists:students,email|required_without:tel',
            'tel' => [
                'nullable',
                'string',
                'exists:students,tel',
                'required_without:email',
                'regex:/^(\+234|234|0)(70|80|81|90|91)\d{8}$/',
            ],

            'gender' => 'required|string|in:male,female,others',
            'profile_picture' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'date_of_birth' => 'required|date|before:today',
            'location' => 'required|string',
            'address' => 'nullable|string',
            'department' => 'required|string',
        ]);

        // 2. Check if validation failed
        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();

        try {
            // 3. Find the student by email or tel
            if ($request->email) {
                $student = Student::where('email', $request->email)->first();
            } elseif ($request->tel) {
                $student = Student::where('tel', $request->tel)->first();
            } else {
                return response()->json([
                    'message' => 'Email or phone number is required to identify the student.',
                ], 400);
            }

            // 4. Check if student exists
            if (!$student) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Student not found.',
                ], 404);
            }

            // 5. Check if either email or phone is verified
            if ($student->email_verified_at === null && $student->tel_verified_at === null) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Please verify your email or phone number before completing biodata.',
                ], 400);
            }

            $data = $validator->validated();

            /**
             * 6. Enforce uniqueness on update
             */

            if (isset($data['email']) && $data['email'] !== $student->email) {
                if (Student::where('email', $data['email'])->exists()) {
                    DB::rollBack();
                    return response()->json([
                        'message' => 'Email already in use.',
                    ], 422);
                }
                $data['email_verified_at'] = null;
            }

            if (isset($data['tel']) && $data['tel'] !== $student->tel) {
                if (Student::where('tel', $data['tel'])->exists()) {
                    DB::rollBack();
                    return response()->json([
                        'message' => 'Phone number already in use.',
                    ], 422);
                }
                $data['tel_verified_at'] = null;
            }

            // 7. Handle profile picture upload if provided
            if ($request->hasFile('profile_picture')) {
                $file = $request->file('profile_picture');
                $path = $file->store('student_profile_pictures', 'public');
                $data['profile_picture'] = $path;
            }

            // 8. Update student biodata
            $student->update([
                'firstname' => $data['firstname'] ?? $student->firstname,
                'surname' => $data['surname'] ?? $student->surname,
                'email' => $data['email'] ?? $student->email,
                'tel' => $data['tel'] ?? $student->tel,
                'gender' => $data['gender'] ?? $student->gender,
                'profile_picture' => $data['profile_picture'] ?? $student->profile_picture,
                'date_of_birth' => $data['date_of_birth'] ?? $student->date_of_birth,
                'location' => $data['location'] ?? $student->location,
                'address' => $data['address'] ?? $student->address,
                'department' => $data['department'] ?? $student->department,
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Biodata updated successfully.',
                'student' => $student->fresh(),
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Failed to update biodata.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
    * Login
    **/
    public function login(Request $request)
    {
        // 1️⃣ Validate input
        $validator = Validator::make($request->all(), [
            'entry' => 'required|string',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Login Fails',
                'errors' => $validator->errors(),
            ], 400);
        }

        try {
            // Create unique throttle key
            $throttleKey = Str::lower($request->input('entry')) . '|' . $request->ip();

            // Check if user is rate limited
            if (RateLimiter::tooManyAttempts($throttleKey, 5)) {

                $seconds = RateLimiter::availableIn($throttleKey);

                return response()->json([
                    'message' => "Too many login attempts. Please try again in {$seconds} seconds."
                ], 429);
            }
            //  Fetch student
            $student = Student::where('email', $request->entry)->orWhere('tel', $request->entry)->first();

            if (!$student) {
                return response()->json([
                    'message' => 'Login Fails',
                    'errors' => 'Student not found, please register ' . $request->entry,
                ], 403);
            }

            // Check password
            if (!Hash::check($request->password, $student->password)) {
                RateLimiter::hit($throttleKey, 60); // lock attempt for 60 seconds
                return response()->json([
                    'message' => 'Login Fails',
                    'errors' => 'Invalid Login Credentials',
                ], 403);
            }

            // Restrict login if email not verified
            if (is_null($student->email_verified_at) && is_null($student->tel_verified_at)) {
                return response()->json([
                    'message' => 'Please verify your email address before logging in.',
                    'verification_required' => 'email',
                ], 403);
            }

            // 8. Clear rate limiter after successful login
            RateLimiter::clear($throttleKey);
            
            //Clearing the token
            $student->tokens()->delete();

            // Create Sanctum token
            $token = $student->createToken('student-token')->plainTextToken;

            return response()->json([
                'message' => 'Login successful.',
                'token' => $token,
                'student' => $student,
            ],200);

        } catch (\Exception $error) {
            return response()->json([
                'message' => 'Login Fails',
                'errors' => $error->getMessage(),
            ], 500);
        }
    }

    /**
    * logout.
    **/
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }
}