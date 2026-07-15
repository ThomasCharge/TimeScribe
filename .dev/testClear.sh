#!/usr/bin/env bash

set -euo pipefail

php artisan tinker --execute='
use App\Models\Project;
use App\Models\Timestamp;

Timestamp::withTrashed()
    ->whereBetween("started_at", [
        "2026-07-01 00:00:00",
        "2026-07-08 23:59:59",
    ])
    ->forceDelete();

Project::withTrashed()
    ->where("name", "Profile Types")
    ->forceDelete();

dump("Test timestamps and project cleared.");
'