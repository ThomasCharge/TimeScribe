#!/usr/bin/env bash

set -euo pipefail

CSV_PATH="$(
    find "/Users/thomascharge/Documents/timescribe/archive" \
        -maxdepth 1 \
        -type f \
        -name "TimeScribe-Export*2026-07-01*2026-07-08.csv" \
        -print \
        -quit
)"

if [[ -z "$CSV_PATH" ]]; then
    echo "Could not find the TimeScribe export CSV."
    exit 1
fi

CSV_PATH="$CSV_PATH" php artisan tinker --execute='
use App\Services\Import\TimeScribeImportService;

$path = getenv("CSV_PATH");

$summary = (new TimeScribeImportService($path))->import(
    false,
    TimeScribeImportService::OVERLAP_REPLACE_EXISTING
);

dump($summary);
'