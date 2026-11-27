<?php

declare(strict_types=1);

/** Minimum calendar days between application day and first leave day (today + N). */
function leave_advance_days(mysqli $conn): int
{
    $days = 2;
    if (function_exists('db_column_exists') && db_column_exists($conn, 'settings', 'leave_advance_days')) {
        $r = $conn->query('SELECT leave_advance_days FROM settings LIMIT 1');
        if ($r && ($row = $r->fetch_assoc())) {
            $days = max(0, (int) ($row['leave_advance_days'] ?? 2));
        }
    }

    return $days;
}

/** First date staff may select for leave (YYYY-MM-DD). */
function leave_earliest_selectable_date(mysqli $conn): string
{
    $advance = leave_advance_days($conn);

    return date('Y-m-d', strtotime('+' . $advance . ' days'));
}

/**
 * Server-side validation for leave request dates.
 * Returns error message or null if OK.
 */
function leave_validate_request_dates(mysqli $conn, string $startDate, string $endDate): ?string
{
    $earliest = leave_earliest_selectable_date($conn);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
        return 'Invalid date format.';
    }
    if ($startDate < $earliest) {
        return 'Leave must be applied at least ' . leave_advance_days($conn) . ' days in advance. Earliest date: '
            . date('d M Y', strtotime($earliest)) . '.';
    }
    if ($endDate < $startDate) {
        return 'End date cannot be before start date.';
    }
    if ($endDate < $earliest) {
        return 'End date must be on or after ' . date('d M Y', strtotime($earliest)) . '.';
    }

    return null;
}
