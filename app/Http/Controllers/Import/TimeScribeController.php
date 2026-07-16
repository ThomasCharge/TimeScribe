<?php

declare(strict_types=1);

namespace App\Http\Controllers\Import;

use App\Http\Controllers\Controller;
use App\Jobs\CalculateWeekBalance;
use App\Services\Import\TimeScribeImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Inertia\Inertia;
use Native\Desktop\Dialog;
use Native\Desktop\Facades\Alert;

class TimeScribeController extends Controller
{
    public function create()
    {
        return Inertia::modal('ImportExport/Import/TimeScribe/Create', [
            'submit_route' => route('import.timescribe.store'),
        ])->baseRoute('import-export.index');
    }

    public function store(Request $request): RedirectResponse|Redirector
    {
        $csvPath = Dialog::new()->asSheet()
            ->filter('TimeScribe CSV', ['csv'])
            ->files()
            ->button(__('app.import csv file'))
            ->open();

        if ($csvPath === null) {
            return back();
        }

        try {
            $overlapMode = $request->boolean('imported_data_wins')
                ? TimeScribeImportService::OVERLAP_REPLACE_EXISTING
                : TimeScribeImportService::OVERLAP_KEEP_EXISTING;

            $summary = (new TimeScribeImportService($csvPath))->import(
                false,
                $overlapMode
            );
        } catch (\Throwable) {
            Alert::error(
                __('app.import failed'),
                __('app.an error occurred while importing the file. please check the file format and try again.')
            );

            return to_route('import-export.index');
        }

        if ($summary['errors']) {
            Alert::error(
                __('app.import failed'),
                implode(PHP_EOL, $summary['errors'])
            );

            return to_route('import-export.index');
        }

        Alert::type('info')
            ->title(__('app.import successful'))
            ->show($this->summaryMessage($summary));

        dispatch(new CalculateWeekBalance);

        return to_route('import-export.index');
    }

    private function summaryMessage(array $summary): string
    {
        $lines = [
            'Rows read: '.$summary['rows_read'],
            'Timestamps created: '.$summary['timestamps_created'],
            'Timestamps skipped: '.$summary['timestamps_skipped'],
            'Duplicate rows skipped: '.$summary['duplicate_rows_skipped'],
            'Projects created: '.$summary['projects_created'],
            'Projects reused: '.$summary['projects_reused'],
            'Overlapping rows found: '.$summary['overlapping_rows_found'],
            'Imported rows adjusted: '.$summary['imported_rows_split'],
            'Existing rows split: '.$summary['existing_rows_split'],
            'Existing rows trimmed: '.$summary['existing_rows_trimmed'],
            'Existing rows deleted: '.$summary['existing_rows_deleted'],
        ];

        if ($summary['warnings']) {
            $lines[] = '';
            $lines[] = 'Warnings:';
            foreach ($summary['warnings'] as $warning) {
                $lines[] = '- '.$warning;
            }
        }

        if ($summary['unsupported_columns']) {
            $lines[] = '';
            $lines[] = 'Unsupported columns:';
            foreach ($summary['unsupported_columns'] as $column) {
                $lines[] = '- '.$column;
            }
        }

        return implode(PHP_EOL, $lines);
    }
}
