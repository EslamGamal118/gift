<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Language Lines
    |--------------------------------------------------------------------------
    */

    'failed'   => 'These credentials do not match our records.',
    'password' => 'The provided password is incorrect.',
    'throttle' => 'Too many login attempts. Please try again in :seconds seconds.',

    // OTP
    'otp_sent'           => 'Verification code sent successfully.',
    'otp_send_failed'    => 'We could not send the verification code. Please try again later.',
    'otp_cooldown'       => 'Please wait before requesting a new verification code.',
    'invalid_otp'        => 'The verification code is incorrect.',
    'otp_expired'        => 'The verification code has expired. Please request a new one.',
    'otp_already_used'   => 'This verification code has already been used. Please request a new one.',
    'too_many_attempts'  => 'Too many failed attempts. Please try again later.',
    'too_many_requests'  => 'Too many requests. Please slow down.',
    'invalid_account_type' => 'The selected account type is invalid.',
    'invalid_action'     => 'The requested action is invalid. Allowed values: login or register.',
    'invalid_phone'      => 'Please enter a valid mobile number.',

    // Login / registration
    'logged_in'          => 'Logged in successfully.',
    'registered'         => 'Account created successfully.',
    'account_exists'     => 'An account already exists for this number and account type. Please log in.',
    'account_not_found'  => 'No account was found for this number and account type. Please register first.',
    'account_blocked'    => 'Your account has been blocked. Please contact support.',
    'unauthenticated'    => 'Unauthenticated.',
    'logged_out'         => 'Logged out successfully.',

    'attributes' => [
        'mobile'       => 'mobile number',
        'otp'          => 'verification code',
        'account_type' => 'account type',
        'user_type'    => 'account type',
        'action'       => 'action',
        'country_code' => 'country code',
        'fcm_token'    => 'notification token',
        'name'         => 'name',
    ],

];
