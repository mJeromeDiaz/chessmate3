<template>
  <q-card flat bordered data-testid="run-recap">
    <q-card-section>
      <div class="text-h6">{{ $t('training.recap.title') }}</div>
      <div class="text-body2" data-testid="run-close-reason">{{
        closeReasonText(run)
      }}</div>
    </q-card-section>
    <q-card-section v-if="summary" class="row q-col-gutter-md">
      <div v-for="stat in stats" :key="stat.label" class="col-6 col-sm-4">
        <div class="text-caption text-grey">{{ stat.label }}</div>
        <div class="text-h6" :data-testid="stat.testid">{{ stat.value }}</div>
      </div>
    </q-card-section>
    <q-card-section v-if="details.length" class="text-caption text-grey">
      <div v-for="line in details" :key="line">{{ line }}</div>
    </q-card-section>
    <q-card-actions>
      <slot name="actions" />
    </q-card-actions>
  </q-card>
</template>

<script setup>
/**
 * The normalized recap of a closed run (same for every module), plus the details a module adds
 * in `summary.metrics`.
 */
import { computed } from 'vue'
import {
  formatDuration,
  formatPercent,
  formatRatingDelta
} from '@/utils/format'
import { LEVELS } from '@/utils/blindfold'
import { recallLines } from '@/utils/blindfoldRecall'
import { rushLines } from '@/utils/puzzleRush'
import { RAMPS, streakText, topicLabel, wonBadges } from '@/utils/puzzleStreak'
import {
  ORIENTATIONS,
  formatAnswerTime,
  validationText
} from '@/utils/coordinates'
import { streakAids } from '@/utils/runResult'
import { unitWord } from '@/utils/repertoireTest'
import { FREE_FORMATS, closeReasonText } from '@/utils/training'
import { intlLocale, t } from '@/i18n'

const props = defineProps({
  /** @type {import('vue').PropType<import('@/composables/training/useTimeboxedRun').TrainingRun>} */
  run: { type: Object, required: true }
})

const summary = computed(() => props.run.summary)

const stats = computed(() => {
  const s = summary.value
  if (!s) return []
  const duration = {
    label: t('training.recap.duration'),
    value: formatDuration(s.durationMs),
    testid: 'run-duration'
  }
  // Free study has nothing to count but its time.
  if (props.run.module === 'free') return [duration]
  return [
    duration,
    {
      label: t('training.recap.finished'),
      value: String(s.itemCount),
      testid: 'run-items'
    },
    {
      label: t('training.recap.succeeded'),
      value: String(s.successCount),
      testid: 'run-successes'
    },
    {
      label: t('training.recap.failed'),
      value: String(s.failureCount),
      testid: 'run-failures'
    },
    {
      label: t('training.recap.accuracy'),
      value: formatPercent(s.successRate),
      testid: 'run-accuracy'
    },
    {
      label: t('training.recap.perMinute'),
      value:
        s.itemsPerMinute === null
          ? '—'
          : s.itemsPerMinute.toLocaleString(intlLocale()),
      testid: 'run-per-minute'
    }
  ]
})

/** Module-specific lines (Woodpecker, the repertoire test, puzzles, free study, coordinates, blindfold puzzles, position evaluation, puzzle streak, puzzle rush, position recall). */
const details = computed(() => {
  const m = summary.value?.metrics ?? {}
  const lines = []
  if (props.run.module === 'repertoire') {
    const unit = m.unit === 'line' ? 'line' : 'segment'
    /** @param {'recovered'|'interrupted'|'dropped'} key @param {number} count */
    const unitLine = (key, count) =>
      t(
        `training.recap.${key}.${unit}`,
        { units: unitWord(unit, count) },
        count
      )
    lines.push(t('training.recap.graded', { n: m.positionsGraded ?? 0 }))
    if (m.recovered > 0) lines.push(unitLine('recovered', m.recovered))
    if (m.interrupted > 0) lines.push(unitLine('interrupted', m.interrupted))
    if (m.dropped > 0) lines.push(unitLine('dropped', m.dropped))
    if (m.rounds > 1) lines.push(t('training.recap.rounds', { n: m.rounds }))
    return lines
  }
  if (props.run.module === 'free') {
    lines.push(
      t('training.recap.format', { format: FREE_FORMATS[m.format] ?? m.format })
    )
    if (m.notes) lines.push(t('training.recap.notes', { notes: m.notes }))
    return lines
  }
  if (props.run.module === 'coordinates') {
    lines.push(
      t('training.recap.orientation', {
        orientation: ORIENTATIONS[m.orientation] ?? m.orientation
      })
    )
    lines.push(validationText(props.run))
    if (m.averageMs)
      lines.push(
        t('training.recap.perSquare', { time: formatAnswerTime(m.averageMs) })
      )
    return lines
  }
  if (props.run.module === 'blindfold') {
    lines.push(
      t('training.recap.blindfoldSettings', {
        level: LEVELS[m.level] ?? m.level,
        length: m.length,
        seconds: m.visibleSeconds
      })
    )
    lines.push(
      t('training.recap.blindfoldResults', {
        solved: m.solved ?? 0,
        helped: m.helped ?? 0,
        failed: m.failed ?? 0
      })
    )
    if (m.averageMs)
      lines.push(
        t('training.recap.perPuzzle', { time: formatDuration(m.averageMs) })
      )
    return lines
  }
  if (props.run.module === 'puzzle_streak') {
    lines.push(
      t('training.recap.streakSettings', {
        ramp: RAMPS[m.ramp] ?? m.ramp,
        topic: topicLabel(m.topic ?? 'general')
      })
    )
    lines.push(
      t(
        m.record
          ? 'training.recap.bestStreakRecord'
          : 'training.recap.bestStreak',
        {
          streak: streakText(m.bestStreak ?? 0)
        }
      )
    )
    if ((m.streaks?.length ?? 0) > 1)
      lines.push(t('training.recap.streaks', { list: m.streaks.join(', ') }))
    for (const badge of wonBadges(m))
      lines.push(
        t('training.recap.badge', {
          name: badge.name,
          goal: streakText(badge.goal)
        })
      )
    if (m.hardestRating)
      lines.push(t('training.recap.hardest', { rating: m.hardestRating }))
    if (m.extensions)
      lines.push(t('training.recap.extended', { n: m.extensions }))
    const aids = streakAids(m)
    if (aids) lines.push(t('training.recap.aids', { aids }))
    return lines
  }
  if (props.run.module === 'puzzle_rush') {
    lines.push(...rushLines(props.run, null))
    if (m.averageMs)
      lines.push(
        t('training.recap.perPuzzle', { time: formatDuration(m.averageMs) })
      )
    if (m.hardestRating)
      lines.push(t('training.recap.hardest', { rating: m.hardestRating }))
    return lines
  }
  if (props.run.module === 'blindfold_recall') {
    lines.push(...recallLines(m))
    if (m.averageMs)
      lines.push(
        t('training.recap.perPosition', { time: formatDuration(m.averageMs) })
      )
    return lines
  }
  if (props.run.module === 'endgames') {
    lines.push(
      t('endgame.recap.results', {
        won: m.won ?? 0,
        held: m.held ?? 0,
        failed: (m.spoiled ?? 0) + (m.overtime ?? 0) + (m.resigned ?? 0) + (m.timeout ?? 0)
      })
    )
    if (m.engineJudged)
      lines.push(t('endgame.recap.engineJudged', { n: m.engineJudged }))
    return lines
  }
  if (props.run.module === 'evaluation') {
    lines.push(
      t('training.recap.evaluation', {
        exact: m.exact ?? 0,
        close: m.close ?? 0,
        miss: m.miss ?? 0,
        timeout: m.timeout ?? 0
      })
    )
    if (m.planOk) lines.push(t('training.recap.plans', { n: m.planOk }))
    if (m.averageMs)
      lines.push(
        t('training.recap.perPosition', { time: formatDuration(m.averageMs) })
      )
    return lines
  }
  if (props.run.module === 'puzzles' && m.ratingBefore != null) {
    lines.push(
      t('training.recap.rating', {
        before: m.ratingBefore,
        after: m.ratingAfter,
        delta: formatRatingDelta(m.ratingDelta)
      })
    )
  }
  if (m.averageMs)
    lines.push(
      t('training.recap.perPuzzle', { time: formatDuration(m.averageMs) })
    )
  if (m.added > 0)
    lines.push(
      t('training.recap.grown', { added: m.added, total: m.puzzleCount })
    )
  if (m.cycle)
    lines.push(
      t('training.recap.cycle', {
        n: m.cycle.number,
        played: m.cycle.played,
        total: m.puzzleCount
      })
    )
  return lines
})
</script>
