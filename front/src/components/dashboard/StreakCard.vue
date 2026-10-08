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
        :class="{ 'streak-card__flame--out': streak.current === 0 }"
        :style="{
          width: `${FLAME_PX * scale}px`,
          height: `${FLAME_PX * scale}px`
        }"
        :data-scale="scale"
        data-testid="streak-card-flame"
      >
        <div class="streak-card__art" :style="{ transform: `scale(${scale})` }">
          <div class="streak-card__glow" aria-hidden="true" />
          <div class="streak-card__drops" aria-hidden="true">
            <div class="streak-card__drop streak-card__drop--outer" />
            <div class="streak-card__drop streak-card__drop--inner" />
          </div>
          <div class="streak-card__count" data-testid="streak-card-count">{{
            streak.current
          }}</div>
        </div>
      </div>
      <div class="streak-card__unit">{{
        streak.current > 1 ? 'jours d’affilée' : 'jour d’affilée'
      }}</div>
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
  </section>
</template>

<script setup>
/**
 * The streak card of the dashboard (docs/GAMIFICATION.md, § 4): a flame holding the current streak,
 * lit and flickering while the streak is alive (grey and still at 0), small below a week and bigger
 * with each streak badge reached; the record, the active days of the week. The streak badges are
 * in the trophy grid.
 */
import { computed } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useGamificationStore } from '@/stores/gamification'
import { days, flameScale, localToday, weekRow } from '@/utils/streak'

const auth = useAuthStore()
const gamification = useGamificationStore()

/** The flame's size at scale 1, in px. */
const FLAME_PX = 132

const streak = computed(() => gamification.summary?.streak ?? null)
const scale = computed(() => flameScale(streak.value?.current ?? 0))
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

.streak-card__head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 8px;
}

.streak-card__best {
  font-size: 13px;
  color: var(--cm-muted);
}

.streak-card__now {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 2px;
  text-align: center;
}

// The flame of the celebration screen, smaller: two drops, the streak in the heart.
.streak-card__flame {
  position: relative;
  transition:
    width 0.6s cubic-bezier(0.34, 1.6, 0.64, 1),
    height 0.6s cubic-bezier(0.34, 1.6, 0.64, 1);

  &--out {
    filter: grayscale(1);
    opacity: 0.55;
  }
}

.streak-card__art {
  position: absolute;
  left: 50%;
  top: 50%;
  width: 132px;
  height: 132px;
  margin: -66px 0 0 -66px;
  display: flex;
  align-items: center;
  justify-content: center;
  transform-origin: 50% 50%;
  transition: transform 0.6s cubic-bezier(0.34, 1.6, 0.64, 1);
}

.streak-card__glow {
  position: absolute;
  inset: -8px;
  border-radius: 50%;
  background: radial-gradient(circle, #ff8a3d 0%, transparent 65%);
  opacity: 0.35;

  .streak-card__flame--out & {
    display: none;
  }
}

.streak-card__drops {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  margin-top: 14px;
}

.streak-card__drop {
  position: absolute;
  border-radius: 50% 0 50% 50%;
  transform: rotate(-45deg);

  &--outer {
    width: 100px;
    height: 100px;
    background: #ff8a3d;
    box-shadow: inset -8px 8px 0 #ffa85e;
    animation: streak-card-flicker 1.6s ease-in-out infinite;
  }

  &--inner {
    top: 46px;
    width: 62px;
    height: 62px;
    background: #ffd43b;
    animation: streak-card-flicker-2 1.2s ease-in-out infinite;
  }

  .streak-card__flame--out & {
    animation: none;
  }
}

.streak-card__count {
  position: relative;
  top: 26px;
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 34px;
  line-height: 1;
  color: #1b1530;
  font-variant-numeric: tabular-nums;
}

.streak-card__unit {
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 17px;
  color: var(--cm-orange-ink);
}

.streak-card__hint {
  margin: 0;
  font-size: 13px;
  color: var(--cm-ink-soft);
  text-wrap: pretty;
}

@keyframes streak-card-flicker {
  0%,
  100% {
    transform: rotate(-45deg) scale(1, 1);
  }
  50% {
    transform: rotate(-43deg) scale(0.96, 1.05);
  }
}

@keyframes streak-card-flicker-2 {
  0%,
  100% {
    transform: rotate(-45deg) scale(1);
  }
  50% {
    transform: rotate(-47deg) scale(1.08, 0.94);
  }
}

@media (prefers-reduced-motion: reduce) {
  .streak-card__drop {
    animation: none;
  }
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
</style>
