<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Models\Project;
use App\Models\Timestamp;
use App\Support\DateTimeFormat;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

class TimeScribeImportService
{
    public const OVERLAP_KEEP_EXISTING = 'keep_existing';
    public const OVERLAP_REPLACE_EXISTING = 'replace_existing';

    private const REQUIRED_COLUMNS = [
        'Type',
        'Start Date',
        'Start Time',
        'End Date',
        'End Time',
    ];

    private const SUPPORTED_COLUMNS = [
        'Type',
        'Description',
        'Metadata',
        'Project',
        'Import Source',
        'Start Date',
        'Start Time',
        'End Date',
        'End Time',
        'Duration',
        'Hourly Rate (h)',
        'Billable Amount',
        'Currency',
        'Paid',
    ];

    private array $summary = [
        'rows_read' => 0,
        'timestamps_parsed' => 0,
        'timestamps_created' => 0,
        'timestamps_skipped' => 0,
        'timestamps_updated' => 0,
        'duplicate_rows_skipped' => 0,
        'projects_detected' => [],
        'project_parse_warnings' => [],
        'projects_created' => 0,
        'projects_reused' => 0,
        'errors' => [],
        'warnings' => [],
        'unsupported_columns' => [],
        'preview' => [],
        'overlapping_rows_found' => 0,
        'imported_rows_split' => 0,
        'existing_rows_split' => 0,
        'existing_rows_trimmed' => 0,
        'existing_rows_deleted' => 0,
    ];

    private array $projectIdsByName = [];
    private array $seenFingerprints = [];

    public function __construct(private readonly string $csvPath)
    {
    }

    public function validate(): array
    {
        $csvFile = fopen($this->csvPath, 'r');

        if ($csvFile === false) {
            $this->summary['errors'][] = 'Could not open CSV file.';

            return $this->summary;
        }

        $headers = fgetcsv($csvFile, escape: '\\');

        fclose($csvFile);

        if ($headers === false) {
            $this->summary['errors'][] = 'CSV file is empty.';

            return $this->summary;
        }

        $this->validateHeaders($headers);

        return $this->summary;
    }

    public function import(
        bool $dryRun = true,
        string $overlapMode = self::OVERLAP_KEEP_EXISTING
    ): array
    {
        $validationSummary = $this->validate();

        if ($validationSummary['errors']) {
            return $validationSummary;
        }

        $csvFile = fopen($this->csvPath, 'r');

        if ($csvFile === false) {
            $this->summary['errors'][] = 'Could not open CSV file.';

            return $this->summary;
        }

        $headers = fgetcsv($csvFile, escape: '\\');

        $importRows = function () use (
            $csvFile,
            $headers,
            $dryRun,
            $overlapMode
        ): void {
            while (($row = fgetcsv($csvFile, escape: '\\')) !== false) {
                $this->summary['rows_read']++;

                $rowData = $this->rowToData($headers, $row);
                $timestamp = $this->parseTimestampRow($rowData);

                if ($timestamp === null) {
                    $this->summary['timestamps_skipped']++;

                    continue;
                }

                $fingerprint = $this->timestampFingerprint($timestamp);

                if (isset($this->seenFingerprints[$fingerprint])) {
                    $this->summary['duplicate_rows_skipped']++;
                    $this->summary['timestamps_skipped']++;

                    continue;
                }

                $this->seenFingerprints[$fingerprint] = true;

                if ($this->databaseDuplicateExists($timestamp)) {
                    $this->summary['duplicate_rows_skipped']++;
                    $this->summary['timestamps_skipped']++;

                    continue;
                }

                $this->summary['timestamps_parsed']++;

                if (count($this->summary['preview']) < 5) {
                    $this->summary['preview'][] = $timestamp;
                }

                if (! $dryRun) {
                    $this->saveTimestampWithOverlapMode($timestamp, $overlapMode);
                }
            }
        };

        if ($dryRun) {
            $importRows();
        } else {
            DB::transaction($importRows);
        }

        fclose($csvFile);

        return $this->summary;
    }

    private function validateHeaders(array $headers): void
    {
        foreach (self::REQUIRED_COLUMNS as $requiredColumn) {
            if (! in_array($requiredColumn, $headers, true)) {
                $this->summary['errors'][] = 'Missing required column: '.$requiredColumn;
            }
        }

        foreach ($headers as $header) {
            if (! in_array($header, self::SUPPORTED_COLUMNS, true)) {
                $this->summary['unsupported_columns'][] = $header;
            }
        }
    }

    private function rowToData(array $headers, array $row): array
    {
        $data = [];

        foreach ($headers as $index => $header) {
            $data[$header] = $row[$index] ?? null;
        }

        return $data;
    }

    private function parseTimestampRow(array $row): ?array
    {
        try {
            $startedAt = $this->parseDateTime(
                $row['Start Date'] ?? '',
                $row['Start Time'] ?? ''
            );

            $endedAt = $this->parseDateTime(
                $row['End Date'] ?? '',
                $row['End Time'] ?? ''
            );
        } catch (\Throwable $exception) {
            $this->summary['warnings'][] = 'Skipped row '
                .$this->summary['rows_read']
                .': invalid date/time.';

            return null;
        }

        $type = trim((string) ($row['Type'] ?? ''));

        if ($type === '') {
            $this->summary['warnings'][] = 'Skipped row '
                .$this->summary['rows_read']
                .': missing timestamp type.';

            return null;
        }

        $project = $this->parseProject(
            $this->nullableString($row['Project'] ?? null)
        );

        if ($project['name'] !== null) {
            $this->summary['projects_detected'][$project['name']] = [
                'name' => $project['name'],
                'icon' => $project['icon'],
                'metadata' => $this->nullableString($row['Metadata'] ?? null),
                'hourly_rate' => $this->nullableString($row['Hourly Rate (h)'] ?? null),
                'currency' => $this->nullableString($row['Currency'] ?? null),
            ];
        }

        return [
            'type' => $type,
            'description' => $this->nullableString($row['Description'] ?? null),
            'source' => $this->nullableString($row['Import Source'] ?? null),
            'project_name' => $project['name'],
            'project_icon' => $project['icon'],
            'project_metadata' => $this->nullableString($row['Metadata'] ?? null),
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'duration' => $this->nullableString($row['Duration'] ?? null),
            'hourly_rate' => $this->nullableString($row['Hourly Rate (h)'] ?? null),
            'billable_amount' => $this->nullableString($row['Billable Amount'] ?? null),
            'currency' => $this->nullableString($row['Currency'] ?? null),
            'paid' => $this->parsePaid($row['Paid'] ?? null),
        ];
    }

    private function parseProject(?string $project): array
    {
        if ($project === null) {
            return [
                'name' => null,
                'icon' => null,
            ];
        }

        $project = trim($project);

        if ($project === '') {
            return [
                'name' => null,
                'icon' => null,
            ];
        }

        $parts = preg_split('/\s+/u', $project, 2);

        if (
            is_array($parts) &&
            count($parts) === 2 &&
            $this->looksLikeIcon($parts[0])
        ) {
            return [
                'name' => trim($parts[1]),
                'icon' => trim($parts[0]),
            ];
        }

        $this->summary['project_parse_warnings'][] =
            'Could not confidently split project icon and name: '.$project;

        return [
            'name' => $project,
            'icon' => null,
        ];
    }

    private function looksLikeIcon(string $value): bool
    {
        return preg_match('/[\x{1F000}-\x{1FAFF}]/u', $value) === 1;
    }

    private function timestampFingerprint(array $timestamp): string
    {
        return sha1(json_encode([
            'type' => $timestamp['type'] ?? null,
            'description' => $timestamp['description'] ?? null,
            'source' => $timestamp['source'] ?? null,
            'project_name' => $timestamp['project_name'] ?? null,
            'started_at' => $timestamp['started_at'] ?? null,
            'ended_at' => $timestamp['ended_at'] ?? null,
            'duration' => $timestamp['duration'] ?? null,
            'paid' => $timestamp['paid'] ?? false,
        ], JSON_THROW_ON_ERROR));
    }

    private function databaseDuplicateExists(array $timestamp): bool
    {
        $query = Timestamp::query()
            ->where('type', $timestamp['type'])
            ->where('started_at', $timestamp['started_at'])
            ->where('ended_at', $timestamp['ended_at']);

        if ($timestamp['description'] === null) {
            $query->whereNull('description');
        } else {
            $query->where('description', $timestamp['description']);
        }

        if ($timestamp['source'] === null) {
            $query->whereNull('source');
        } else {
            $query->where('source', $timestamp['source']);
        }

        if (array_key_exists('paid', $timestamp)) {
            $query->where('paid', $timestamp['paid']);
        }

        if ($timestamp['project_name'] === null) {
            $query->whereNull('project_id');
        } else {
            $query->whereHas('project', function ($projectQuery) use ($timestamp): void {
                $projectQuery->where('name', $timestamp['project_name']);
            });
        }

        return $query->exists();
    }

    private function saveTimestamp(array $timestamp): void
    {
        $projectId = $this->resolveProjectId($timestamp);

        Timestamp::create([
            'type' => $timestamp['type'],
            'description' => $timestamp['description'],
            'source' => $timestamp['source'],
            'started_at' => $timestamp['started_at'],
            'ended_at' => $timestamp['ended_at'],
            'last_ping_at' => $timestamp['ended_at'],
            'project_id' => $projectId,
            'paid' => $timestamp['paid'],
            'created_at' => $timestamp['started_at'],
            'updated_at' => $timestamp['ended_at'],
        ]);

        $this->summary['timestamps_created']++;
    }

    private function resolveProjectId(array $timestamp): ?int
    {
        $projectName = $timestamp['project_name'] ?? null;

        if ($projectName === null) {
            return null;
        }

        if (array_key_exists($projectName, $this->projectIdsByName)) {
            return $this->projectIdsByName[$projectName];
        }

        $project = Project::withTrashed()
            ->where('name', $projectName)
            ->first();

        if ($project instanceof Project) {
            $this->projectIdsByName[$projectName] = $project->id;
            $this->summary['projects_reused']++;

            return $project->id;
        }

        $project = Project::create([
            'name' => $projectName,
            'description' => null,
            'metadata' => $timestamp['project_metadata'] ?? null,
            'color' => '#000000',
            'icon' => $timestamp['project_icon'] ?? null,
            'hourly_rate' => $this->parseDecimal(
                $timestamp['hourly_rate'] ?? null,
                'hourly rate'
            ),
            'currency' => $timestamp['currency'] ?? null,
        ]);

        $this->projectIdsByName[$projectName] = $project->id;
        $this->summary['projects_created']++;

        return $project->id;
    }

    private function parseDecimal(mixed $value, string $fieldName): ?float
    {
        $value = $this->nullableString($value);

        if ($value === null) {
            return null;
        }

        $normalised = str_replace(',', '', $value);

        if (! is_numeric($normalised)) {
            $this->summary['warnings'][] = 'Ignored invalid '.$fieldName.': '.$value;

            return null;
        }

        return round((float) $normalised, 2);
    }

    private function parseDateTime(string $date, string $time): string
    {
        $dateTime = Date::createFromFormat(
            'd/m/Y '.DateTimeFormat::TIME_VALUE,
            trim($date).' '.trim($time)
        );

        if ($dateTime === false) {
            throw new \Exception('Invalid date/time.');
        }

        return $dateTime->format(DateTimeFormat::DATE_TIME_VALUE);
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function parsePaid(mixed $value): bool
    {
        $value = strtolower(trim((string) $value));

        return in_array($value, ['yes', 'true', '1'], true);
    }

    private function saveTimestampWithOverlapMode(array $timestamp, string $overlapMode): void
    {
        if ($overlapMode === self::OVERLAP_REPLACE_EXISTING) {
            $this->trimExistingRowsAroundImportedRow($timestamp);
            $this->saveTimestamp($timestamp);

            return;
        }

        $segments = $this->getImportedSegmentsThatDoNotOverlapExistingRows($timestamp);

        if ($segments === []) {
            $this->summary['timestamps_skipped']++;

            return;
        }

        foreach ($segments as $segment) {
            $this->saveTimestamp($segment);
        }
    }

    private function getOverlappingExistingRows(array $timestamp)
    {
        return Timestamp::query()
            ->whereNotNull('ended_at')
            ->where('started_at', '<', $timestamp['ended_at'])
            ->where('ended_at', '>', $timestamp['started_at'])
            ->orderBy('started_at')
            ->get();
    }

    private function getImportedSegmentsThatDoNotOverlapExistingRows(array $timestamp): array
    {
        $segments = [$timestamp];
        $existingRows = $this->getOverlappingExistingRows($timestamp);

        if ($existingRows->isEmpty()) {
            return $segments;
        }

        $this->summary['overlapping_rows_found'] += $existingRows->count();

        foreach ($existingRows as $existingRow) {
            $newSegments = [];

            foreach ($segments as $segment) {
                $segmentStart = Date::parse($segment['started_at']);
                $segmentEnd = Date::parse($segment['ended_at']);
                $existingStart = $existingRow->started_at;
                $existingEnd = $existingRow->ended_at;

                if (
                    $existingEnd->lessThanOrEqualTo($segmentStart) ||
                    $existingStart->greaterThanOrEqualTo($segmentEnd)
                ) {
                    $newSegments[] = $segment;

                    continue;
                }

                if ($existingStart->greaterThan($segmentStart)) {
                    $beforeSegment = $segment;
                    $beforeSegment['ended_at'] = $existingStart
                        ->format(DateTimeFormat::DATE_TIME_VALUE);

                    $newSegments[] = $beforeSegment;
                }

                if ($existingEnd->lessThan($segmentEnd)) {
                    $afterSegment = $segment;
                    $afterSegment['started_at'] = $existingEnd
                        ->format(DateTimeFormat::DATE_TIME_VALUE);

                    $newSegments[] = $afterSegment;
                }

                $this->summary['imported_rows_split']++;
            }

            $segments = $newSegments;
        }

        return array_values(array_filter($segments, function (array $segment): bool {
            return $segment['started_at'] < $segment['ended_at'];
        }));
    }

    private function trimExistingRowsAroundImportedRow(array $timestamp): void
    {
        $existingRows = $this->getOverlappingExistingRows($timestamp);

        if ($existingRows->isEmpty()) {
            return;
        }

        $this->summary['overlapping_rows_found'] += $existingRows->count();

        $importStart = Date::parse($timestamp['started_at']);
        $importEnd = Date::parse($timestamp['ended_at']);

        foreach ($existingRows as $existingRow) {
            $existingStart = $existingRow->started_at;
            $existingEnd = $existingRow->ended_at;

            if (
                $existingStart->lessThan($importStart) &&
                $existingEnd->greaterThan($importEnd)
            ) {
                $copy = $existingRow->replicate();
                $copy->started_at = $importEnd;
                $copy->ended_at = $existingEnd;
                $copy->last_ping_at = $existingEnd;
                $copy->created_at = $importEnd;
                $copy->updated_at = $existingEnd;
                $copy->save();

                $existingRow->ended_at = $importStart;
                $existingRow->last_ping_at = $importStart;
                $existingRow->updated_at = $importStart;
                $existingRow->save();

                $this->summary['existing_rows_split']++;

                continue;
            }

            if (
                $existingStart->greaterThanOrEqualTo($importStart) &&
                $existingEnd->lessThanOrEqualTo($importEnd)
            ) {
                $existingRow->delete();

                $this->summary['existing_rows_deleted']++;

                continue;
            }

            if (
                $existingStart->lessThan($importStart) &&
                $existingEnd->greaterThan($importStart)
            ) {
                $existingRow->ended_at = $importStart;
                $existingRow->last_ping_at = $importStart;
                $existingRow->updated_at = $importStart;
                $existingRow->save();

                $this->summary['existing_rows_trimmed']++;

                continue;
            }

            if (
                $existingStart->lessThan($importEnd) &&
                $existingEnd->greaterThan($importEnd)
            ) {
                $existingRow->started_at = $importEnd;
                $existingRow->created_at = $importEnd;
                $existingRow->save();

                $this->summary['existing_rows_trimmed']++;
            }
        }
    }
}