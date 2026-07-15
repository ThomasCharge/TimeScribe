<?php

declare(strict_types=1);

namespace App\Http\Controllers\Import;

use App\Http\Controllers\Controller;
use App\Jobs\CalculateWeekBalance;
use App\Services\Import\TimeScribeImportService;
use Illuminate\Http\RedirectResponse;
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

    public function store(): RedirectResponse|Redirector
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
            $summary = new TimeScribeImportService($csvPath)
                ->import(false);
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
