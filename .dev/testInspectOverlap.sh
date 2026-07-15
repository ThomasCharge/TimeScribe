#!/usr/bin/env bash

set -euo pipefail

php artisan tinker --execute='
use App\Models\Timestamp;

$timestamps = Timestamp::query()
    ->with("project")
    ->where("started_at", "<", "2026-07-07 18:00:00")
    ->where("ended_at", ">", "2026-07-07 17:00:00")
    ->orderBy("started_at")
    ->get()
    ->map(function (Timestamp $timestamp): array {
        return [
            "id" => $timestamp->id,
            "type" => $timestamp->type->value,
            "start" => $timestamp->started_at->format("H:i:s"),
            "end" => $timestamp->ended_at?->format("H:i:s"),
            "description" => $timestamp->description,
            "project" => $timestamp->project?->name,
        ];
    })
    ->all();

dump($timestamps);
'