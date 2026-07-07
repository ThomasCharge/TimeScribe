#!/usr/bin/env bash
set -euo pipefail

# Install and run application
#APP="nativephp/electron/dist/TimeScribeDev-0.1.0-arm64.dmg"
# Run locally
APP="nativephp/electron/dist/mac-arm64/TimeScribeDev.app"

APP_BIN="$APP/Contents/MacOS/TimeScribeDev"
RUN_LOG="nativephp-run.log"
LARAVEL_LOG="$APP/Contents/Resources/app/storage/logs/laravel.log"

npm run build
php artisan native:build mac arm64

codesign --force --deep --sign - "$APP"
xattr -dr com.apple.quarantine "$APP"

echo "Starting app..."
echo "App output log: $RUN_LOG"

if [ -f "$LARAVEL_LOG" ]; then
    echo "Laravel log: $LARAVEL_LOG"
    tail -F "$LARAVEL_LOG" &
    TAIL_PID=$!
else
    echo "Laravel log not found at: $LARAVEL_LOG"
    TAIL_PID=""
fi

"$APP_BIN" 2>&1 | tee "$RUN_LOG"

if [ -n "${TAIL_PID:-}" ]; then
    kill "$TAIL_PID" 2>/dev/null || true
fi