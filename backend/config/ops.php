<?php

/*
 * Operations: health checks and alerts for whoever runs the system.
 */
return [

    // Where alerts go (failed jobs, health check failures, trust
    // reconciliation problems). Empty: alerts are only logged.
    'alert_email' => env('OPS_ALERT_EMAIL'),

    // The same alert is sent at most once per this many minutes.
    'alert_throttle_minutes' => (int) env('OPS_ALERT_THROTTLE_MINUTES', 60),

    // With this token (Authorization: Bearer ...), /api/health also returns
    // each check's detail. Without it, only ok/warning/failing per check.
    'health_token' => env('HEALTH_TOKEN'),

    // True where a queue worker and the scheduler are supposed to run (the
    // Docker stack). Their heartbeats are then checked.
    'expect_workers' => (bool) env('HEALTH_EXPECT_WORKERS', false),

    // Heartbeats older than this are a failure.
    'heartbeat_stale_minutes' => (int) env('HEALTH_HEARTBEAT_STALE_MINUTES', 5),

    // Status file written by the backup container (see docker/backup).
    'backup_status_file' => env('BACKUP_STATUS_FILE'),
    'backup_max_age_hours' => (int) env('BACKUP_MAX_AGE_HOURS', 26),
    'backup_verify_max_age_days' => (int) env('BACKUP_VERIFY_MAX_AGE_DAYS', 8),

];
