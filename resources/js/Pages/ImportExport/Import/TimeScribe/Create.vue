<script lang="ts" setup>
import SheetDialog from '@/Components/dialogs/SheetDialog.vue'
import { Badge } from '@/Components/ui/badge'
import { Head, router, useForm } from '@inertiajs/vue3'

const props = defineProps<{
    submit_route: string
}>()

const form = useForm({
    source: 'timescribe'
})

const submit = () => {
    router.flushAll()
    form.post(props.submit_route, {
        preserveScroll: true,
        preserveState: 'errors'
    })
}
</script>

<template>
    <Head title="TimeScribe Import" />
    <SheetDialog
        :close="$t('app.cancel')"
        :description="
            'Import a CSV file exported from the normal/base TimeScribe app.'
        "
        :loading="form.processing"
        :submit="$t('app.import csv file')"
        title="Import TimeScribe data"
        @submit="submit"
    >
        <div class="mt-4 flex flex-col gap-6 text-sm">
            <div class="flex flex-col gap-1">
                <div class="flex items-center gap-2">
                    <Badge>{{ $t('app.step :number', { number: '1' }) }}</Badge>
                    <span class="font-medium">Open your old TimeScribe app</span>
                </div>
                <span class="text-muted-foreground">
                    Use the normal/base TimeScribe instance that contains the data you want to move.
                </span>
            </div>

            <div class="flex flex-col gap-1">
                <div class="flex items-center gap-2">
                    <Badge>{{ $t('app.step :number', { number: '2' }) }}</Badge>
                    <span class="font-medium">Export CSV with all columns</span>
                </div>
                <span class="text-muted-foreground">
                    Export all relevant dates and keep all available columns enabled.
                </span>
            </div>

            <div class="flex flex-col gap-1">
                <div class="flex items-center gap-2">
                    <Badge>{{ $t('app.step :number', { number: '3' }) }}</Badge>
                    <span class="font-medium">Import the CSV here</span>
                </div>
                <span class="text-muted-foreground">
                    Existing duplicates will be skipped rather than imported again.
                </span>
            </div>
        </div>

        <div class="border-border mt-6 border-t pt-6 text-sm">
            This import preserves work and break rows, project names, descriptions, source,
            paid state, and precise start/end times where they exist in the CSV.
        </div>
    </SheetDialog>
</template>
