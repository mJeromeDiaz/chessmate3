<template>
  <section v-if="streak" class="cm-card streak-card" data-testid="streak-card">
    <div class="streak-card__head">
      <h2 class="cm-card__title">Série</h2>
      <span class="streak-card__best" data-testid="streak-card-best"
        >Record : {{ days(streak.best) }}</span
      >
    </div>

    <div class="streak-card__now">
      <div
        class="streak-card__flame"
        :class="{ 'streak-card__flame--waiting': !playedToday }"
        aria-hidden="true"
        >🔥&#xFE0E;</div
      >
      <div>
        <div class="streak-card__count" data-testid="streak-card-count">{{
          streak.current
        }}</div>
        <div class="streak-card__unit">{{
          streak.current > 1 ? 'jours d’affilée' : 'jour d’affilée'
        }}</div>
      </div>
      <p class="streak-card__hint">{{ hint }}</p>
    </div>

    <div class="streak-card__week" aria-label="Ta semaine">
      <div
        v-for="(day, i) in week"
        :key="i"
        class="streak-card__day"
        :class="{
          'streak-card__day--on': day.done,
          'streak-card__day--today': day.today
        }"
        :data-testid="`streak-day-${i}`"
        :data-done="day.done || undefined"
      >
        <span class="streak-card__day-label">{{ day.label }}</span>
        <span class="streak-card__day-dot">{{ day.done ? '✓' : '' }}</span>
      </div>
    </div>

    <div class="streak-card__badges-head">
      <span class="streak-card__badges-title">Badges de série</span>
      <span class="streak-card__badges-count" data-testid="streak-badges-count"
        >{{ won }} / {{ track.length }}</span
      >
    </div>
    <div class="streak-card__badges">
      <div
        v-for="badge in track"
        :key="badge.key"
        class="streak-card__badge"
        :class="{
          'streak-card__badge--on': badge.unlocked,
          'streak-card__badge--next': badge.next
        }"
        :data-testid="`streak-badge-${badge.goal}`"
        :data-unlocked="badge.unlocked || undefined"
        :title="
          badge.unlocked
            ? `${badge.name} : gagné le ${badge.date}`
            : `${badge.name} : ${days(badge.goal)} d’affilée`
        "
      >
        <span class="streak-card__badge-disc">{{ badge.goal }}</span>
        <span class="streak-card__badge-name">{{ badge.name }}</span>
        <span v-if="badge.next" class="streak-card__badge-left"
          >encore {{ badge.left }} j</span
        >
      </div>
    </div>
  </section>
</template>

<script setup>
/**
 * The streak card of the dashboard (docs/GAMIFICATION.md, § 4): today's streak and the record, the
 * active days of the week, the 12 streak badges (won for good, the next one to win highlighted).
 */
import { computed } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useGamificationStore } from '@/stores/gamification'
import { badgeTrack, days, localToday, weekRow } from '@/utils/streak'

const auth = useAuthStore()
const gamification = useGamificationStore()

const streak = computed(() => gamification.summary?.streak ?? null)
const today = computed(() => localToday(auth.profile?.timezone))
const playedToday = computed(
  () =>
    !!streak.value?.playedToday &&
    gamification.summary?.today?.date === today.value
)
const week = computed(() =>
  weekRow(
    streak.value?.week ?? [],
    gamification.summary?.today?.date ?? today.value
  )
)
const track = computed(() =>
  badgeTrack(gamification.trophies, streak.value?.current ?? 0)
)
const won = computed(() => track.value.filter(b => b.unlocked).length)
const hint = computed(() => {
  const s = streak.value
  if (!s) return ''
  if (!playedToday.value)
    return s.current > 0
      ? 'Un exercice aujourd’hui et ta série continue.'
      : 'Un exercice aujourd’hui pour lancer une série.'
  return s.nextMilestone
    ? `Prochain badge dans ${days(s.nextMilestone - s.current)}.`
    : 'Tous les badges sont à toi.'
})
</script>

<style scoped lang="scss">
.streak-card {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.streak-card__head,
.streak-card__badges-head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 8px;
}

.streak-card__best,
.streak-card__badges-count {
  font-size: 13px;
  color: var(--cm-muted);
}

.streak-card__now {
  display: flex;
  align-items: center;
  gap: 12px;
}

.streak-card__flame {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 56px;
  height: 56px;
  flex: none;
  border-radius: 18px;
  background: var(--cm-orange-soft);
  font-size: 30px;

  &--waiting {
    background: var(--cm-subtle);
    filter: grayscale(1);
    opacity: 0.7;
  }
}

.streak-card__count {
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 36px;
  line-height: 1;
  color: var(--cm-orange-ink);
  font-variant-numeric: tabular-nums;
}

.streak-card__unit {
  font-size: 13px;
  color: var(--cm-muted);
}

.streak-card__hint {
  flex: 1;
  margin: 0;
  font-size: 13px;
  color: var(--cm-ink-soft);
  text-align: right;
}

.streak-card__week {
  display: grid;
  grid-template-columns: repeat(7, minmax(0, 1fr));
  gap: 4px;
}

.streak-card__day {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
}

.streak-card__day-label {
  font-size: 12px;
  font-weight: 700;
  color: var(--cm-muted);

  .streak-card__day--today & {
    color: var(--cm-orange-ink);
  }
}

.streak-card__day-dot {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 30px;
  height: 30px;
  border-radius: 50%;
  background: var(--cm-subtle);
  font-weight: 800;
  font-size: 15px;

  .streak-card__day--on & {
    background: #ff8a3d;
    color: #1b1530;
  }

  .streak-card__day--today & {
    box-shadow: 0 0 0 3px var(--cm-orange-line);
  }
}

.streak-card__badges-title {
  font-weight: 700;
  font-size: 14px;
}

.streak-card__badges {
  display: flex;
  gap: 8px;
  overflow-x: auto;
  padding-bottom: 4px;
  scroll-snap-type: x proximity;
}

.streak-card__badge {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
  flex: none;
  width: 68px;
  scroll-snap-align: start;
  text-align: center;
  opacity: 0.55;

  &--on,
  &--next {
    opacity: 1;
  }
}

.streak-card__badge-disc {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 46px;
  height: 46px;
  border-radius: 50%;
  border: 2px dashed var(--cm-line);
  background: var(--cm-subtle);
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 15px;
  color: var(--cm-muted);

  .streak-card__badge--on & {
    border: none;
    background: #ff8a3d;
    color: #1b1530;
    box-shadow: 0 3px 0 #b84a0e;
  }

  .streak-card__badge--next & {
    border-color: #ff8a3d;
    color: var(--cm-orange-ink);
  }
}

.streak-card__badge-name {
  font-size: 11.5px;
  font-weight: 700;
  line-height: 1.2;
}

.streak-card__badge-left {
  font-size: 11px;
  color: var(--cm-orange-ink);
}
</style>
