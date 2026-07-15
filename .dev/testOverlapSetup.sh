#!/usr/bin/env bash

set -euo pipefail

./.dev/testClear.sh

php artisan tinker --execute='
use App\Models\Timestamp;

Timestamp::create([
    "type" => "work",
    "started_at" => "2026-07-07 17:00:00",
    "ended_at" => "2026-07-07 18:00:00",
    "last_ping_at" => "2026-07-07 18:00:00",
    "description" => "Existing overlap test",
    "created_at" => "2026-07-07 17:00:00",
    "updated_at" => "2026-07-07 18:00:00",
]);

dump("Created existing timestamp from 17:00:00 to 18:00:00.");
'