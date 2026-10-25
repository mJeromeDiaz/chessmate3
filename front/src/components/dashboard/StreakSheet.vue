<template>
  <q-dialog
    :model-value="modelValue"
    :position="$q.screen.lt.md ? 'bottom' : 'standard'"
    @update:model-value="emit('update:modelValue', $event)"
    @show="loadShop"
  >
    <div class="streak-sheet" data-testid="streak-sheet">
      <header class="streak-sheet__head">
        <div class="streak-sheet__title">
          <span class="streak-sheet__kicker">{{
            $t('gamification.card.kicker')
          }}</span>
          <span class="streak-sheet__days">{{
            $t('gamification.sheet.inARow', {
              days: days(streak?.current ?? 0)
            })
          }}</span>
        </div>
        <button
          type="button"
          class="streak-sheet__close"
          :aria-label="$t('common.actions.close')"
          @click="emit('update:modelValue', false)"
          >✕</button
        >
      </header>

      <div class="streak-sheet__body">
        <section class="streak-sheet__block">
          <div class="streak-sheet__row">
            <span class="streak-sheet__month">{{ monthName }}</span>
            <span class="streak-sheet__muted">{{
              $t('gamification.sheet.counts', counts)
            }}</span>
          </div>
          <div class="streak-sheet__calendar" data-testid="streak-calendar">
            <span
              v-for="(d, i) in weekDays"
              :key="`w${i}`"
              class="streak-sheet__dow"
              >{{ d }}</span
            >
            <span
              v-for="(cell, i) in grid"
              :key="i"
              class="streak-sheet__cell"
              :class="[
                cell.state && `streak-sheet__cell--${cell.state}`,
                { 'streak-sheet__cell--today': cell.today }
              ]"
              :data-state="cell.state ?? undefined"
              >{{ cell.state === 'frozen' ? '❄︎' : (cell.day ?? '') }}</span
            >
          </div>
          <div class="streak-sheet__legend">
            <span v-for="state in ['played', 'frozen', 'missed']" :key="state"
              ><i :class="`streak-sheet__cell--${state}`" />{{
                $t(`gamification.sheet.legend.${state}`)
              }}</span
            >
          </div>
          <div class="streak-sheet__muted">{{
            $t('gamification.card.best', { days: days(streak?.best ?? 0) })
          }}</div>
        </section>

        <section class="streak-sheet__block">
          <span class="streak-sheet__label">{{
            $t('gamification.sheet.badges')
          }}</span>
          <div class="streak-sheet__badges">
            <div
              v-for="badge in badges"
              :key="badge.goal"
              class="streak-sheet__badge"
              :class="`streak-sheet__badge--${badge.state}`"
              :title="badge.name"
            >
              <span class="streak-sheet__badge-disc"
                ><b>{{ badge.goal }}</b
                ><small>{{ $t('gamification.sheet.daysCaps') }}</small></span
              >
              <span class="streak-sheet__badge-state">{{
                $t(`gamification.sheet.badgeStates.${badge.state}`)
              }}</span>
            </div>
          </div>
        </section>

        <section v-if="streak?.nextMilestone" class="streak-sheet__next">
          <span class="streak-sheet__kicker">{{
            $t('gamification.sheet.nextTier', { days: streak.nextMilestone })
          }}</span>
          <div class="streak-sheet__row">
            <span class="streak-sheet__left">{{
              $t(
                'gamification.sheet.left',
                streak.nextMilestone - streak.current
              )
            }}</span>
            <span class="streak-sheet__reward"
              ><CrystalGem :size="20" />+{{ reward }}</span
            >
          </div>
          <span class="streak-sheet__muted">{{
            $t('gamification.sheet.rewardLine', {
              n: reward,
              days: streak.nextMilestone
            })
          }}</span>
        </section>

        <section v-if="freezes.length" class="streak-sheet__block">
          <div class="streak-sheet__row">
            <span class="streak-sheet__label">{{
              $t('gamification.sheet.freezes')
            }}</span>
            <span class="streak-sheet__stock">{{
              $t('gamification.sheet.stock', { n: streak?.freezeDays ?? 0 })
            }}</span>
          </div>
          <button
            v-for="offer in freezes"
            :key="offer.id"
            type="button"
            class="streak-sheet__freeze"
            data-testid="streak-sheet-freeze"
            @click="purchase.sheet.value = offer"
          >
            <span class="streak-sheet__ice" aria-hidden="true">❄︎</span>
            <span class="streak-sheet__freeze-text">
              <b>{{ offer.name }}</b>
              <span v-if="offer.subtitle">{{ offer.subtitle }}</span>
            </span>
            <span v-if="offer.wasPrice" class="streak-sheet__was">{{
              crowns(offer.wasPrice)
            }}</span>
            <span class="streak-sheet__price"
              ><CrystalGem :size="18" />{{ crowns(offer.price) }}</span
            >
          </button>
        </section>
      </div>

      <PurchaseSheet
        :offer="purchase.sheet.value"
        :balance="purchase.shop.state?.balance ?? 0"
        :inventory="purchase.shop.state?.inventory ?? {}"
        :busy="purchase.busy.value"
        :error="purchase.error.value"
        @close="purchase.close()"
        @buy="purchase.buy"
      />
    </div>
  </q-dialog>
</template>

<script setup>
/**
 * « En voir plus » of the streak card (design « Dashboard v2 »): the local month day by day
 * (played, frozen, missed; the summary's `streak.month`), the streak badges around the current
 * streak, the next one and its chest (docs/SHOP.md § 4 ter), the freezes on sale, bought in place.
 */
import { computed } from 'vue'
import CrystalGem from '@/components/shop/CrystalGem.vue'
import PurchaseSheet from '@/components/shop/PurchaseSheet.vue'
import { useAidPurchase } from '@/composables/shop/useAidPurchase'
import { useGamificationStore } from '@/stores/gamification'
import {
  badgeCrystals,
  badgeRow,
  monthCounts,
  monthGrid
} from '@/utils/dashboard/home'
import { crowns, offersIn } from '@/utils/shop'
import { days } from '@/utils/streak'
import { intlLocale, t } from '@/i18n'

defineProps({ modelValue: { type: Boolean, default: false } })
const emit = defineEmits(['update:modelValue'])

const gamification = useGamificationStore()
// A freeze bought: the summary counts its days.
const purchase = useAidPurchase(() => gamification.load(['summary']))

const streak = computed(() => gamification.summary?.streak ?? null)
const today = computed(() => gamification.summary?.today?.date ?? '')
const grid = computed(() => monthGrid(streak.value?.month, today.value))
const counts = computed(() => monthCounts(streak.value?.month))
const badges = computed(() => badgeRow(streak.value?.current ?? 0))
const reward = computed(() => badgeCrystals(streak.value?.nextMilestone ?? 0))
const weekDays = computed(() =>
  [0, 1, 2, 3, 4, 5, 6].map(i => t(`gamification.weekDays.${i}`))
)
const monthName = computed(() => {
  const first = streak.value?.month?.first
  if (!first) return ''
  const name = new Intl.DateTimeFormat(intlLocale(), {
    month: 'long',
    year: 'numeric',
    timeZone: 'UTC'
  }).format(new Date(`${first}T12:00:00Z`))
  return name.charAt(0).toUpperCase() + name.slice(1)
})
/** The freezes on sale, cheapest first. */
const freezes = computed(() =>
  offersIn(purchase.shop.state?.offers ?? [], 'freeze')
    .filter(o => !o.comingSoon)
    .sort((a, b) => a.price - b.price)
)

function loadShop() {
  purchase.shop.loadOnce().catch(() => {})
}
</script>

<style scoped lang="scss">
.streak-sheet {
  width: 100%;
  max-width: 760px;
  max-height: 88vh;
  display: flex;
  flex-direction: column;
  border-radius: 28px 28px 0 0;
  background: #16121f;
  color: #fff;
  overflow: hidden;

  @media (min-width: $breakpoint-md-min) {
    width: 760px;
    border-radius: 28px;
  }
}

.streak-sheet__head {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 18px 20px 12px;
}

.streak-sheet__title {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.streak-sheet__kicker {
  color: #ffa552;
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.06em;
}

.streak-sheet__days {
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 22px;
}

.streak-sheet__close {
  width: 40px;
  height: 40px;
  border: none;
  border-radius: 13px;
  background: #2b2640;
  color: #fff;
  font-size: 16px;
  cursor: pointer;
}

.streak-sheet__body {
  display: grid;
  gap: 14px;
  padding: 0 20px 22px;
  overflow-y: auto;

  @media (min-width: $breakpoint-md-min) {
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    align-items: start;
  }
}

.streak-sheet__block,
.streak-sheet__next {
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding: 14px;
  border-radius: 20px;
  background: #221d30;
}

.streak-sheet__next {
  background: linear-gradient(180deg, #2b2036, #221d30);
  box-shadow: inset 0 0 0 1.5px rgba(255, 165, 82, 0.35);
}

.streak-sheet__row {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 8px;
}

.streak-sheet__month,
.streak-sheet__label {
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 16px;
}

.streak-sheet__muted {
  color: #b4afc6;
  font-size: 12.5px;
}

.streak-sheet__calendar {
  display: grid;
  grid-template-columns: repeat(7, minmax(0, 1fr));
  gap: 5px;
}

.streak-sheet__dow {
  color: #8a85a0;
  font-size: 11px;
  font-weight: 800;
  text-align: center;
}

.streak-sheet__cell {
  display: flex;
  align-items: center;
  justify-content: center;
  aspect-ratio: 1;
  border-radius: 10px;
  color: #6e6a80;
  font-size: 12.5px;
  font-weight: 800;

  &--played {
    background: #ff7a1a;
    color: #1b1530;
  }

  &--frozen {
    background: #7cc4ff;
    color: #0b3a66;
  }

  &--missed {
    background: #3a3450;
    color: #8a85a0;
  }

  &--none,
  &--future {
    box-shadow: inset 0 0 0 1.5px #3a3450;
  }

  &--today {
    box-shadow:
      0 0 0 2px #221d30,
      0 0 0 4px #ffa552;
  }
}

.streak-sheet__legend {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  color: #b4afc6;
  font-size: 12px;
  font-weight: 700;

  span {
    display: flex;
    align-items: center;
    gap: 6px;
  }

  i {
    width: 12px;
    height: 12px;
    border-radius: 4px;
  }
}

.streak-sheet__badges {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 8px;
}

.streak-sheet__badge {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;

  &--later {
    opacity: 0.4;
  }
}

.streak-sheet__badge-disc {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  width: 58px;
  height: 58px;
  border-radius: 50%;
  background: #2b2640;
  color: #b4afc6;

  b {
    font-family: var(--cm-heading);
    font-size: 18px;
    line-height: 1;
  }

  small {
    font-size: 9px;
    font-weight: 800;
  }

  .streak-sheet__badge--reached & {
    background: linear-gradient(180deg, #ffb36b, #ff7a1a);
    color: #1b1530;
  }

  .streak-sheet__badge--next & {
    box-shadow: inset 0 0 0 2px #ffa552;
  }
}

.streak-sheet__badge-state {
  color: #8a85a0;
  font-size: 11px;
  font-weight: 800;

  .streak-sheet__badge--reached & {
    color: #ffa552;
  }
}

.streak-sheet__left {
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 18px;
}

.streak-sheet__reward,
.streak-sheet__price {
  display: flex;
  align-items: center;
  gap: 5px;
  font-weight: 800;
}

.streak-sheet__stock {
  color: #9ed4ff;
  font-size: 12.5px;
  font-weight: 800;
}

.streak-sheet__freeze {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 12px;
  border: none;
  border-radius: 16px;
  background: #2b2640;
  color: #fff;
  font: inherit;
  text-align: left;
  cursor: pointer;
}

.streak-sheet__ice {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 36px;
  height: 36px;
  border-radius: 12px;
  background: rgba(124, 196, 255, 0.16);
  color: #9ed4ff;
  font-size: 18px;
}

.streak-sheet__freeze-text {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 2px;

  span {
    color: #b4afc6;
    font-size: 12px;
  }
}

.streak-sheet__was {
  color: #8a85a0;
  font-size: 12px;
  text-decoration: line-through;
}
</style>
