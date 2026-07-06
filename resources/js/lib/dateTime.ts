import moment from 'moment'
import {
    VALUE_DATE_TIME_FORMAT,
    VALUE_TIME_FORMAT,
} from '@/lib/dateTimeFormats'

export function toTimeValue(value: string) {
    return moment(value, VALUE_DATE_TIME_FORMAT).format(VALUE_TIME_FORMAT)
}