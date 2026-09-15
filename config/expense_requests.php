<?php

return [
    // Temporary historical-entry window. Set false to restore today-or-later
    // validation for every applicant after historical entries are complete.
    'allow_past_dates' => env('EXPENSE_REQUESTS_ALLOW_PAST_DATES', true),
];
