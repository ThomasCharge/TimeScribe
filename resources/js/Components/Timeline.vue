<script lang="ts" setup>
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/Components/ui/tooltip'
import { VALUE_DATE_TIME_FORMAT } from '@/lib/dateTimeFormats'
import { Timestamp } from '@/types'
import { router } from '@inertiajs/vue3'
import { BriefcaseBusiness, Coffee } from '@lucide/vue'
import moment from 'moment/min/moment-with-locales'
import { ref, watch } from 'vue'
import { useTimeFormat } from '@/Composables/useTimeFormat'

const { formatTimestampTime } = useTimeFormat()

const props = withDefaults(
    defineProps<{
        date: string
        timestamps: Timestamp[]
        overtime?: number
        workTime?: number
    }>(),
    {
        overtime: 0,
        workTime: 0
    }
)

const timeline = ref<Record<string, Timestamp | undefined>>({})
const isToday = moment().isSame(moment(props.date, 'YYYY-MM-DD'), 'day')
const isFuture = moment().isBefore(moment(props.date, 'YYYY-MM-DD'), 'day')

const createDayMoment = () => moment(props.date, 'YYYY-MM-DD')

const timeToMoment = (time?: Timestamp['started_at']) => {
    if (!time) {
        return undefined
    }

    if (time.date) {
        const parsedDate = moment(time.date)

        if (parsedDate.isValid()) {
            return parsedDate
        }
    }

    if (!time.formatted) {
        return undefined
    }

    const digits = time.formatted.replace(/\D/g, '')

    if (!digits) {
        return undefined
    }

    const padded = digits.length <= 4
        ? digits.padStart(4, '0')
        : digits.padStart(6, '0')

    const hours = digits.length <= 4
        ? parseInt(padded.slice(0, 2))
        : parseInt(padded.slice(0, 2))

    const minutes = digits.length <= 4
        ? parseInt(padded.slice(2, 4))
        : parseInt(padded.slice(2, 4))

    const seconds = digits.length <= 4
        ? 0
        : parseInt(padded.slice(4, 6))

    if (
        isNaN(hours) ||
        isNaN(minutes) ||
        isNaN(seconds) ||
        hours < 0 ||
        hours > 23 ||
        minutes < 0 ||
        minutes > 59 ||
        seconds < 0 ||
        seconds > 59
    ) {
        return undefined
    }

    return createDayMoment()
        .hour(hours)
        .minute(minutes)
        .second(seconds)
}

const parseTimestamps = () => {
    props.timestamps.forEach((timestamp) => {
        const start = timeToMoment(timestamp.started_at)
        const end = timeToMoment(timestamp.ended_at ?? timestamp.last_ping_at)

        if (!start || !end || end.isSameOrBefore(start)) {
            return
        }

        const dayStart = createDayMoment().startOf('day')
        const dayEnd = createDayMoment().endOf('day')

        const safeStart = moment.max(start, dayStart)
        const safeEnd = moment.min(end, dayEnd)

        let slot = safeStart.clone()
            .minute(Math.floor(safeStart.minute() / 10) * 10)
            .second(0)

        while (slot.isSameOrBefore(safeEnd)) {
            const slotEnd = slot.clone().add(10, 'minutes')

            if (safeStart.isBefore(slotEnd) && safeEnd.isAfter(slot)) {
                const index = slot.hour() * 100 + slot.minute()
                timeline.value[index.toString()] = { ...timestamp }
            }

            slot.add(10, 'minutes')
        }
    })

    markOvertime()
}

const markOvertime = () => {
    let overtimeCount = Math.ceil(props.overtime / 600)
    for (const [key, value] of Object.entries(timeline.value).reverse()) {
        if (value?.type === 'work' && (overtimeCount > 0 || props.overtime === props.workTime)) {
            timeline.value[key] = { ...value, type: 'overtime' }
            overtimeCount--
        } else if (overtimeCount <= 0) {
            break
        }
    }
}

const createTimeline = () => {
    timeline.value = {}

    for (let i = 0; i < 2400; i += 100) {
        for (let j = 0; j < 60; j += 10) {
            timeline.value[(j + i).toString()] = undefined
        }
    }

    parseTimestamps()
}

createTimeline()

const drag = ref(false)
const dragType = ref<'work' | 'break'>()
const startDragIndex = ref<number | undefined>(undefined)
const currentDragIndex = ref<number | undefined>(undefined)

const ifSelected = (index: string) => {
    if (startDragIndex.value === undefined || currentDragIndex.value === undefined) {
        return false
    }
    const min = Math.min(startDragIndex.value, currentDragIndex.value)
    const max = Math.max(startDragIndex.value, currentDragIndex.value)
    return parseInt(index) >= min && parseInt(index) <= max
}

const dragStart = (index: string, type?: 'work' | 'break') => {
    dragReset()
    drag.value = true
    dragType.value = type
    startDragIndex.value = parseInt(index)
}
const dragOver = (index: string, type?: 'work' | 'break') => {
    if (!drag.value || startDragIndex.value === undefined) {
        return
    }

    const indexNumber = parseInt(index)

    if (dragType.value !== type) {
        dragStop()
        return
    }

    if (isToday && createDateTimeFromIndex(indexNumber, 10).isAfter(moment())) {
        dragStop()
        return
    }

    currentDragIndex.value = indexNumber
}

const dragStop = () => {
    if (startDragIndex.value !== undefined && currentDragIndex.value !== undefined && drag.value === true) {
        let startDatetime = createDateTimeFromIndex(startDragIndex.value).format(VALUE_DATE_TIME_FORMAT)
        let endDatetime = createDateTimeFromIndex(currentDragIndex.value, 10).format(VALUE_DATE_TIME_FORMAT)
        if (startDragIndex.value > currentDragIndex.value) {
            startDatetime = createDateTimeFromIndex(currentDragIndex.value).format(VALUE_DATE_TIME_FORMAT)
            endDatetime = createDateTimeFromIndex(startDragIndex.value, 10).format(VALUE_DATE_TIME_FORMAT)
        }
        router.visit(
            route('timestamp.create', {
                datetime: startDatetime,
                endDatetime: endDatetime,
                type: dragType.value === 'work' ? 'work' : undefined
            }),
            {
                preserveState: true,
                preserveScroll: true
            }
        )
    }
    drag.value = false
    dragType.value = undefined
}

const createDateTimeFromIndex = (index: number, addMinutes?: number) => {
    const date = createDayMoment()
    const hours = Math.floor(index / 100)
    const minutes = index % 100

    date.hour(hours)
    date.minute(minutes)
    date.second(0)

    if (addMinutes) {
        date.add(addMinutes, 'minutes')
    }

    const dayEnd = createDayMoment().endOf('day')

    if (date.isAfter(dayEnd)) {
        return dayEnd
    }

    return date
}

const dragReset = () => {
    drag.value = false
    dragType.value = undefined
    startDragIndex.value = undefined
    currentDragIndex.value = undefined
}

const dragLeave = () => {
    if (drag.value) {
        if (currentDragIndex.value === 0 || currentDragIndex.value === 2350) {
            dragStop()
        } else {
            dragReset()
        }
    }
}

const indexToTimeFormat = (
    index: string,
    withoutMinutesBy12H?: boolean,
    dragModus?: boolean
) => {
    const indexNumber = parseInt(index)

    const hours = Math.floor(indexNumber / 100)
    const minutes = indexNumber % 100

    const time = moment()
        .hour(hours)
        .minute(minutes)
        .second(0)
        .millisecond(0)

    if (dragModus) {
        time.add(10, 'minutes')

        if (indexNumber === 2350) {
            time.hour(23)
            time.minute(59)
            time.second(59)
        }
    }

    const output = formatTimestampTime(time).replace(/^0([0-9])/, '$1')

    return withoutMinutesBy12H
        ? output.replace(/:00(?::00)? (PM|AM)/, ' $1')
        : output
}

watch(props, () => {
    if (route().current('overview.day.show')) {
        dragReset()
    }
})
</script>

<template>
    <div>
        <div @mouseleave="dragLeave" @mouseup="dragStop" class="relative h-24">
            <div class="absolute inset-x-0 top-3 z-10 mx-0.5 flex justify-between gap-0.5">
                <TooltipProvider :delayDuration="0" :key="index" v-for="(time, index) in timeline">
                    <Tooltip>
                        <TooltipTrigger class="group flex-1">
                            <div
                                :aria-label="index"
                                :class="{
                                    'bg-primary ring-primary': time?.type === 'work' || time?.type === 'overtime',
                                    'bg-pink-400 ring-pink-400': time?.type === 'break',
                                    'ring-gray-300 hover:bg-gray-300 dark:ring-gray-600 dark:hover:bg-gray-600': !time,
                                    'bg-gray-400! ring-gray-400! hover:bg-gray-500! hover:ring-gray-500! dark:ring-gray-500! dark:hover:bg-gray-500!':
                                        ifSelected(index) && !time,
                                    'bg-pink-400! ring-pink-400!': ifSelected(index) && time
                                }"
                                @mousedown="
                                    time?.type !== 'break' && !isFuture
                                        ? dragStart(
                                              index,
                                              !time || time?.type === 'break'
                                                  ? (time?.type as 'work' | 'break' | undefined)
                                                  : 'work'
                                          )
                                        : undefined
                                "
                                @mouseover="
                                    time?.type !== 'break' && !isFuture
                                        ? dragOver(
                                              index,
                                              !time || time?.type === 'break'
                                                  ? (time?.type as 'work' | 'break' | undefined)
                                                  : 'work'
                                          )
                                        : dragStop()
                                "
                                class="bg-muted ring-offset-background h-14 shrink-0 rounded-full ring-offset-1 transition-transform duration-100 group-hover:scale-110 group-hover:ring-2"
                            />
                            <div
                                :class="{
                                    'bg-amber-400 ring-amber-400': time?.type === 'overtime'
                                }"
                                class="mt-0.5 aspect-square shrink-0 rounded-full"
                            />
                        </TooltipTrigger>
                        <TooltipContent
                            :class="{
                                'bg-muted [&_.fill-primary]:fill-muted [&_.fill-primary]:bg-muted': time === undefined,
                                'bg-primary': time?.type === 'work',
                                'bg-amber-400 [&_.fill-primary]:bg-amber-400 [&_.fill-primary]:fill-amber-400':
                                    time?.type === 'overtime',
                                'bg-pink-400 [&_.fill-primary]:bg-pink-400 [&_.fill-primary]:fill-pink-400':
                                    time?.type === 'break'
                            }"
                            side="bottom"
                        >
                            <div
                                :class="{
                                    'text-muted-foreground': time === undefined
                                }"
                            >
                                <bdi>
                                    {{ indexToTimeFormat(index, false, drag) }}
                                </bdi>
                            </div>
                            <div class="flex justify-center">
                                <BriefcaseBusiness
                                    class="my-1 size-5 shrink-0"
                                    v-if="time?.type === 'work' || time?.type === 'overtime'"
                                />
                                <Coffee class="my-1 size-5 shrink-0" v-if="time?.type === 'break'" />
                            </div>
                        </TooltipContent>
                    </Tooltip>
                </TooltipProvider>
            </div>
            <div class="pointer-events-none absolute inset-x-0 top-0 mx-0.5 flex justify-between gap-0.5">
                <div
                    :class="{
                        'flex-none': index === 49,
                        'border-gray-300 dark:border-gray-600': index % 4 === 1,
                        'border-gray-100 dark:border-gray-800': index % 4 !== 1 && index % 2 !== 1,
                        'border-gray-200 dark:border-gray-700': index % 2 === 1 && index % 4 !== 1
                    }"
                    :key="index"
                    class="h-20 flex-1 not-rtl:border-l rtl:border-r"
                    v-for="index in 49"
                ></div>
            </div>
            <div class="pointer-events-none absolute inset-x-0 top-20 mx-0.5 flex justify-between gap-0.5">
                <div
                    :class="{
                        'flex-none': index === 13,
                        'not-rtl:pl-1 rtl:pr-1': index !== 13
                    }"
                    :key="index"
                    class="text-muted-foreground flex h-4 flex-1 items-end border-gray-300 text-xs leading-none not-rtl:border-l rtl:border-r dark:border-gray-600"
                    v-for="index in 13"
                >
                    <bdi>
                        {{ index < 13 ? indexToTimeFormat(((index - 1) * 2 * 100).toString(), true) : '' }}
                    </bdi>
                </div>
            </div>
        </div>
    </div>
</template>
