<?php

use App\Models\ActivityLog;
use App\Models\EmailChangeRequest;
use App\Models\LoginToken;
use App\Models\MailLog;
use App\Models\UserNotification;
use Illuminate\Support\Facades\Schedule;
use Modules\Einsatzbericht\src\Models\DiveraRequest;

// Scheduled commands write to /dev/null by default. In the container their output and
// errors go to the container log instead, otherwise to storage/logs/schedule.log.
$output = is_writable('/proc/1/fd/2') ? '/proc/1/fd/2' : storage_path('logs/schedule.log');

// Prune expired login tokens, e-mail change requests and mail log, activity log and notification entries older than 180 days, DIVERA requests after 90 days daily
Schedule::command('model:prune', ['--model' => [LoginToken::class, EmailChangeRequest::class, MailLog::class, ActivityLog::class, DiveraRequest::class, UserNotification::class]])->daily()->appendOutputTo($output);
