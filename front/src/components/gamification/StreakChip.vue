<template>
  <router-link
    v-if="streak"
    to="/"
    class="streak-chip"
    :class="{ 'streak-chip--waiting': !playedToday }"
    :aria-label="title"
    :data-testid="testid"
  >
    <span aria-hidden="true">🔥&#xFE0E;</span>
    <span :data-testid="`${testid}-count`">{{ streak.current }}</span>
    <q-tooltip>{{ title }}</q-tooltip>
  </router-link>
</template>

<script setup>
/**
 * The streak in the header, on every page (docs/GAMIFICATION.md, § 4): greyed until today's first
 * exercise. Loaded once, reloaded when the summary belongs to a past day (tab left open overnight).
 */
import { computed, onMounted, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useGamificationStore } from '@/stores/gamification'
import { days, localToday } from '@/utils/streak'

defineProps({
  /** Two chips at once (header, drawer): distinct test ids. */
  testid: { type: String, default: 'streak' }
})

const auth = useAuthStore()
const gamification = useGamificationStore()
const route = useRoute()

const streak = computed(() => gamification.summary?.streak ?? null)
/** Played today, and today is still the summary's day. */
const playedToday = computed(
  () =>
    !!streak.value?.playedToday &&
    gamification.summary?.today?.date === localToday(auth.profile?.timezone)
)
const title = computed(() => {
  const s = streak.value
  if (!s) return ''
  if (playedToday.value)
    return `Série : ${days(s.current)} · record ${days(s.best)}`
  return s.current > 0
    ? `Série : ${days(s.current)} · joue aujourd’hui pour la garder`
    : 'Joue aujourd’hui pour lancer une série'
})

function refresh() {
  if (auth.isAuthenticated) gamification.refreshIfStale().catch(() => {})
}

onMounted(refresh)
watch(() => route.path, refresh)
</script>

<style scoped lang="scss">
.streak-chip {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  height: 32px;
  padding: 0 10px;
  border-radius: 12px;
  background: var(--cm-orange-soft);
  color: var(--cm-orange-ink);
  font-weight: 800;
  font-size: 14px;
  text-decoration: none;
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}

// Not played yet today: the streak is still alive, but waits for today's exercise.
.streak-chip--waiting {
  background: var(--cm-subtle);
  color: var(--cm-muted);
}
</style>
