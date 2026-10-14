<template>
  <q-card flat bordered data-testid="cycle-recap">
    <q-card-section>
      <div class="text-h6">
        {{
          setCompleted
            ? $t('woodpecker.recap.setDone')
            : $t('woodpecker.recap.cycleDone', { n: completed.number })
        }}
      </div>
      <div class="q-mt-sm">
        <div
          >{{
            $t('woodpecker.recap.accuracy', {
              value: percent(completed.accuracy)
            })
          }}<span v-if="previous">{{
            $t('woodpecker.recap.compared', {
              comparison: compare(completed.accuracy, previous.accuracy)
            })
          }}</span></div
        >
        <div
          >{{
            $t('woodpecker.recap.active', {
              value: formatDuration(completed.activeMs)
            })
          }}<span v-if="previous">{{
            $t('woodpecker.recap.compared', {
              comparison: compareTime(completed.activeMs, previous.activeMs)
            })
          }}</span></div
        >
        <div>{{
          $t('woodpecker.recap.average', {
            value: formatDuration(completed.averageMs)
          })
        }}</div>
        <div>{{
          $t('woodpecker.recap.calendar', { value: days(completed.calendarMs) })
        }}</div>
      </div>
    </q-card-section>
  </q-card>
</template>

<script setup>
import { formatDuration } from '@/utils/format'
import { intlLocale, t } from '@/i18n'

defineProps({
  /** @type {import('vue').PropType<import('@/stores/woodpecker').CycleView>} */
  completed: { type: Object, required: true },
  /** @type {import('vue').PropType<import('@/stores/woodpecker').CycleView|null>} The previous cycle, for the comparison. */
  previous: { type: Object, default: null },
  setCompleted: { type: Boolean, default: false }
})

/** @param {number|null} ratio */
const percent = ratio =>
  ratio === null
    ? '—'
    : t('woodpecker.recap.percent', { n: Math.round(ratio * 100) })

/** @param {number|null} ms */
const days = ms =>
  ms === null
    ? '—'
    : t('woodpecker.recap.days', {
        n: (ms / 86_400_000).toLocaleString(intlLocale(), {
          minimumFractionDigits: 1,
          maximumFractionDigits: 1
        })
      })

/**
 * @param {number|null} now
 * @param {number|null} before
 */
function compare(now, before) {
  if (now === null || before === null) return t('woodpecker.recap.noComparison')
  const diff = Math.round((now - before) * 100)
  return t('woodpecker.recap.points', {
    sign: diff >= 0 ? '+' : '−',
    n: Math.abs(diff)
  })
}

/**
 * @param {number} now
 * @param {number} before
 */
function compareTime(now, before) {
  if (!before) return t('woodpecker.recap.noComparison')
  const ratio = now / before
  return t('woodpecker.recap.ofPrevious', { n: Math.round(ratio * 100) })
}
</script>
