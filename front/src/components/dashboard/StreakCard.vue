<template>
  <section v-if="streak" class="streak-card" data-testid="streak-card">
    <div class="streak-card__head">
      <div
        class="streak-card__flame"
        :class="{ 'streak-card__flame--out': streak.current === 0 }"
        :style="{ '--flame': `${flamePx}px` }"
        data-testid="streak-card-flame"
        aria-hidden="true"
      >
        <span class="streak-card__drop streak-card__drop--outer" />
        <span class="streak-card__drop streak-card__drop--inner" />
      </div>
      <div class="streak-card__title">
        <span class="streak-card__kicker">{{
          $t('gamification.card.kicker')
        }}</span>
        <span class="streak-card__days"
          ><span data-testid="streak-card-count">{{ streak.current }}</span>
          {{
            $t('gamification.card.unitShort', streak.current > 1 ? 2 : 1)
          }}</span
        >
      </div>
      <span
        class="streak-card__freezes"
        :title="$t('gamification.card.freezes')"
        data-testid="streak-card-freezes"
        >❄︎ {{ streak.freezeDays }}</span
      >
    </div>

    <div class="streak-card__week" :aria-label="$t('gamification.card.week')">
      <div
        v-for="(day, i) in week"
        :key="i"
        class="streak-card__day"
        :class="{
          'streak-card__day--on': day.done,
          'streak-card__day--frozen': day.frozen,
          'streak-card__day--today': day.today,
          'streak-card__day--missed':
            !day.done && !day.frozen && !day.today && !day.future
        }"
        :data-testid="`streak-day-${i}`"
        :data-done="day.done || undefined"
        :data-frozen="day.frozen || undefined"
        :title="day.frozen ? $t('gamification.card.frozen') : undefined"
      >
        <span class="streak-card__day-label">{{ day.label }}</span>
        <span class="streak-card__day-dot">{{
          day.done ? '✓' : day.frozen ? '❄︎' : ''
        }}</span>
      </div>
    </div>

    <div class="streak-card__foot">
      <div class="streak-card__tier">
        <span>{{ tierText }}</span>
        <div class="streak-card__tier-bar">
          <div :style="{ width: `${tierPercent}%` }" />
        </div>
      </div>
      <button
        type="button"
        class="streak-card__more"
        data-testid="streak-card-more"
        @click="sheetOpen = true"
      >
        {{ $t('gamification.card.more') }}
      </button>
    </div>

    <StreakSheet v-model="sheetOpen" />
  </section>
</template>

<script setup>
/**
 * The streak card of the dashboard (design « Dashboard v2 », docs/GAMIFICATION.md § 4): the
 * streak with its flame (grey at 0, a little bigger with each streak badge reached), the freezes'
 * days in stock, the week, the way to the next badge; « En voir plus » opens the month, the badges
 * and the freezes on sale (`StreakSheet`).
 */
import { computed, ref } from 'vue'
import StreakSheet from '@/components/dashboard/StreakSheet.vue'
import { useAuthStore } from '@/stores/auth'
import { useGamificationStore } from '@/stores/gamification'
import {
  FLAME_BASE,
  STREAK_BADGES,
  flameScale,
  localToday,
  nextMilestoneText,
  weekRow
} from '@/utils/streak'

const auth = useAuthStore()
const gamification = useGamificationStore()

/** The flame's size before any badge, in px; each badge reached adds to it. */
const FLAME_PX = 30

const sheetOpen = ref(false)
const streak = computed(() => gamification.summary?.streak ?? null)
const flamePx = computed(() =>
  Math.round(
    FLAME_PX * (flameScale(streak.value?.current ?? 0) / FLAME_BASE) ** 0.5
  )
)
const today = computed(() => localToday(auth.profile?.timezone))
const week = computed(() =>
  weekRow(
    streak.value?.week ?? [],
    gamification.summary?.today?.date ?? today.value,
    streak.value?.frozenWeek ?? []
  )
)
const tierText = computed(() =>
  nextMilestoneText(
    streak.value?.current ?? 0,
    streak.value?.nextMilestone ?? null
  )
)
/** From the last badge reached to the next one. */
const tierPercent = computed(() => {
  const s = streak.value
  if (!s?.nextMilestone) return 100
  const previous =
    [...STREAK_BADGES].reverse().find(b => b.goal <= s.current)?.goal ?? 0
  return Math.round(
    (100 * (s.current - previous)) / (s.nextMilestone - previous)
  )
})
</script>

<style scoped lang="scss">
.streak-card {
  display: flex;
  flex-direction: column;
  gap: 14px;
  padding: 18px 20px;
  border-radius: 24px;
  background: #1b1530;
  color: #fff;

  @media (min-width: $breakpoint-md-min) {
    gap: 16px;
    padding: 20px;
    border-radius: 26px;
  }
}

.streak-card__head {
  display: flex;
  align-items: center;
  gap: 12px;
}

// Two drops, as the celebration screen's flame.
.streak-card__flame {
  position: relative;
  flex: none;
  width: 50px;
  height: 50px;
  border-radius: 16px;
  background: rgba(255, 138, 61, 0.16);

  &--out {
    filter: grayscale(1);
    opacity: 0.55;
  }
}

.streak-card__drop {
  position: absolute;
  left: 50%;
  border-radius: 50% 0 50% 50%;
  transform: rotate(-45deg);

  &--outer {
    bottom: calc(50% - var(--flame) * 0.5);
    width: calc(var(--flame) * 0.74);
    height: calc(var(--flame) * 0.74);
    margin-left: calc(var(--flame) * -0.37);
    background: #ff8a3d;
    animation: streak-card-flicker 1.6s ease-in-out infinite;
  }

  &--inner {
    bottom: calc(50% - var(--flame) * 0.46);
    width: calc(var(--flame) * 0.4);
    height: calc(var(--flame) * 0.4);
    margin-left: calc(var(--flame) * -0.2);
    background: #ffd84d;
  }

  .streak-card__flame--out & {
    animation: none;
  }
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

@media (prefers-reduced-motion: reduce) {
  .streak-card__drop {
    animation: none;
  }
}

.streak-card__title {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.streak-card__kicker {
  color: #ffa552;
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.06em;
}

.streak-card__days {
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 24px;
  line-height: 1;
  font-variant-numeric: tabular-nums;
}

.streak-card__freezes {
  flex: none;
  display: flex;
  align-items: center;
  height: 32px;
  padding: 0 10px;
  border-radius: 11px;
  background: rgba(124, 196, 255, 0.14);
  color: #9ed4ff;
  font-weight: 800;
  font-size: 13px;
}

.streak-card__week {
  display: grid;
  grid-template-columns: repeat(7, minmax(0, 1fr));
  gap: 6px;
}

.streak-card__day {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 5px;
}

.streak-card__day-label {
  color: #8a85a0;
  font-size: 11px;
  font-weight: 800;

  .streak-card__day--today & {
    color: #ffa552;
  }
}

.streak-card__day-dot {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 34px;
  height: 34px;
  border-radius: 12px;
  box-shadow: inset 0 0 0 1.5px #3a3450;
  font-weight: 800;
  font-size: 14px;

  .streak-card__day--on & {
    background: #ff7a1a;
    box-shadow: none;
    color: #1b1530;
  }

  .streak-card__day--frozen & {
    background: #7cc4ff;
    box-shadow: none;
    color: #0b3a66;
  }

  .streak-card__day--missed & {
    background: #3a3450;
    box-shadow: none;
  }

  .streak-card__day--today & {
    box-shadow:
      0 0 0 2px #1b1530,
      0 0 0 4px #ffa552;
  }
}

.streak-card__foot {
  display: flex;
  align-items: center;
  gap: 10px;
}

.streak-card__tier {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 5px;
  color: #b4afc6;
  font-size: 12px;
  font-weight: 700;
}

.streak-card__tier-bar {
  height: 7px;
  border-radius: 7px;
  background: #2e2840;
  overflow: hidden;

  > div {
    height: 100%;
    border-radius: 7px;
    background: #ff8a3d;
  }
}

.streak-card__more {
  flex: none;
  height: 40px;
  padding: 0 14px;
  border: none;
  border-radius: 13px;
  background: #ff8a3d;
  box-shadow: 0 3px 0 #c2531a;
  color: #1b1530;
  font-weight: 800;
  font-size: 13.5px;
  cursor: pointer;
}
</style>
