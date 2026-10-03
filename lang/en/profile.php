<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Profile Completion Language Lines
    |--------------------------------------------------------------------------
    */

    // Steps
    'basic_info_saved'      => 'Basic information saved successfully.',
    'store_details_saved'   => 'Store details saved successfully.',
    'activity_saved'        => 'Activity details saved successfully.',
    'submitted_for_review'  => 'Your profile has been submitted for review.',
    'location_saved'        => 'Location saved successfully.',
    'branch_created'        => 'Branch added successfully.',
    'branch_updated'        => 'Branch updated successfully.',
    'branch_deleted'        => 'Branch deleted successfully.',
    'updated'               => 'Profile updated successfully.',
    'updated_pending_review' => 'Profile updated. The changed documents have been sent for review.',
    'main_branch'           => 'Main Branch',

    // Flow / state errors
    'account_type_mismatch' => 'The selected account type does not match your account (registered as :registered_as).',
    'not_applicable'        => 'This account type does not require a profile.',
    'role_not_allowed'      => 'This step is not available for your account type.',
    'basic_info_required'   => 'Please complete the basic information step first.',
    'incomplete'            => 'Your profile is incomplete. Please fill in the missing information before submitting.',
    'locked_pending'        => 'Your profile is under review and cannot be edited right now.',
    'locked_approved'       => 'Your profile is approved. Please contact support to change your basic information.',

    // Field errors
    'phone_must_match'          => 'The mobile number must match the number registered to your account.',
    'invalid_iban'              => 'Please enter a valid Saudi IBAN (SA followed by 22 digits).',
    'invalid_plate_number'      => 'The plate number may only contain letters, numbers, spaces and dashes.',
    'working_hours_all_days'    => 'Working hours must be provided for all seven days of the week.',
    'working_hours_unknown_day' => ':day is not a valid day of the week.',
    'working_hours_same_time'   => 'The closing time must be different from the opening time.',

    'days' => [
        'saturday'  => 'Saturday',
        'sunday'    => 'Sunday',
        'monday'    => 'Monday',
        'tuesday'   => 'Tuesday',
        'wednesday' => 'Wednesday',
        'thursday'  => 'Thursday',
        'friday'    => 'Friday',
    ],

];
