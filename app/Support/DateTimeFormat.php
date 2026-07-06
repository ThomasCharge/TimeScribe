<?php

declare(strict_types=1);

namespace App\Support;

final class DateTimeFormat
{
    public const DATE_VALUE = 'Y-m-d';
    public const TIME_VALUE = 'H:i:s';
    public const DATE_TIME_VALUE = 'Y-m-d H:i:s';

    public const TIME_DISPLAY = 'H:i';
    public const TIME_DISPLAY_PRECISE = 'H:i:s';

    public const END_OF_DAY_TIME = '23:59:59';
}