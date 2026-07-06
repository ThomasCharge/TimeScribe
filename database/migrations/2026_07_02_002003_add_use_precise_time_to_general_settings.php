<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('general.usePreciseTime', true);
    }

    public function down(): void
    {
        $this->migrator->delete('general.usePreciseTime');
    }
};