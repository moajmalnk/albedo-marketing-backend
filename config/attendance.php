<?php

return [
    /**
     * Check-in at or after this local time (app timezone) counts as "late".
     * Format: H:i (24h).
     */
    'late_after' => env('ATTENDANCE_LATE_AFTER', '09:10'),
];
