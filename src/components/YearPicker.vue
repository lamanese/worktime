<template>
    <div class="year-picker">
        <NcButton type="tertiary"
            :aria-label="t('worktime', 'Vorheriges Jahr')"
            @click="$emit('update', year - 1)">
            <template #icon>
                <ChevronLeft :size="20" />
            </template>
        </NcButton>

        <span class="year-picker__label">
            {{ year }}
        </span>

        <NcButton type="tertiary"
            :aria-label="t('worktime', 'Nächstes Jahr')"
            @click="$emit('update', year + 1)">
            <template #icon>
                <ChevronRight :size="20" />
            </template>
        </NcButton>
    </div>
</template>

<script>
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import ChevronLeft from 'vue-material-design-icons/ChevronLeft.vue'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'

export default {
    name: 'YearPicker',
    components: {
        NcButton,
        ChevronLeft,
        ChevronRight,
    },
    props: {
        year: {
            type: Number,
            required: true,
        },
    },
}
</script>

<style scoped>
.year-picker {
    display: flex;
    align-items: center;
    gap: 8px;
}

.year-picker__label {
    font-size: 1.1em;
    font-weight: 500;
    min-width: 4rem;
    text-align: center;
}
</style>
