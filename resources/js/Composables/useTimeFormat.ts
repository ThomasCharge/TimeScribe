import { usePage } from '@inertiajs/vue3'
import { computed } from 'vue'
import moment from 'moment'
import { secToFormat } from '@/lib/utils'
import {
    VALUE_TIME_FORMAT,
    VALUE_DATE_TIME_FORMAT,
    DISPLAY_TIME_FORMAT,
    DISPLAY_TIME_FORMAT_PRECISE,
    DISPLAY_DATE_TIME_FORMAT,
    DISPLAY_DATE_TIME_FORMAT_PRECISE,
    DISPLAY_TIME_LOCALIZED,
    DISPLAY_TIME_LOCALIZED_PRECISE,
} from '@/lib/dateTimeFormats'

type TimeFormatOptions = {
    withoutHours?: boolean
    forcePreciseTime?: boolean
    noLeadingZero?: boolean
    withAbs?: boolean
}

export function useTimeFormat() {
    const page = usePage()

    const usePreciseTime = computed(() => {
        return page.props.use_precise_time ?? true
    })

    const shouldShowSeconds = (forcePreciseTime?: boolean) => {
        return usePreciseTime.value || !!forcePreciseTime
    }

    const formatSeconds = (
        seconds: number,
        options: TimeFormatOptions = {}
    ) => {
        return secToFormat(
            seconds,
            options.withoutHours,
            !shouldShowSeconds(options.forcePreciseTime),
            options.noLeadingZero,
            options.withAbs
        )
    }

    const formatTime = (
        value: string,
        forcePreciseTime = false
    ) => {
        return moment(value, VALUE_TIME_FORMAT).format(
            shouldShowSeconds(forcePreciseTime)
                ? DISPLAY_TIME_FORMAT_PRECISE
                : DISPLAY_TIME_FORMAT
        )
    }

    const formatDateTime = (
        value: string,
        forcePreciseTime = false
    ) => {
        return moment(value, VALUE_DATE_TIME_FORMAT).format(
            shouldShowSeconds(forcePreciseTime)
                ? DISPLAY_DATE_TIME_FORMAT_PRECISE
                : DISPLAY_DATE_TIME_FORMAT
        )
    }
    
    const formatTimestampTime = (
        value: moment.MomentInput,
        forcePreciseTime = false
    ) => {
        return moment(value).format(
            shouldShowSeconds(forcePreciseTime)
                ? DISPLAY_TIME_LOCALIZED_PRECISE
                : DISPLAY_TIME_LOCALIZED
        )
    }

    return {
        usePreciseTime,
        formatSeconds,
        formatTime,
        formatDateTime,
        formatTimestampTime,
    }
}