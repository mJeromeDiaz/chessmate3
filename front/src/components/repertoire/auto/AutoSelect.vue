<template>
  <div class="ra-screen ra-up">
    <div class="ra-cols">
      <div class="ra-cols__side">
        <h1 class="ra-title ra-title--small">{{
          $t('repertoireAuto.selectScreen.title')
        }}</h1>
        <div class="ra-tiles">
          <div class="ra-tile">
            <span class="ra-tile__value">{{ synthesis.games }}</span
            ><span class="ra-tile__label">{{
              $t('repertoireAuto.selectScreen.games')
            }}</span>
          </div>
          <div class="ra-tile">
            <span class="ra-tile__value">{{ synthesis.families.length }}</span
            ><span class="ra-tile__label">{{
              $t('repertoireAuto.selectScreen.families')
            }}</span>
          </div>
          <div class="ra-tile ra-tile--accent">
            <span class="ra-tile__value">{{
              $t('repertoireAuto.selectScreen.percent', { n: score(synthesis) })
            }}</span
            ><span class="ra-tile__label">{{
              $t('repertoireAuto.selectScreen.score')
            }}</span>
          </div>
        </div>

        <div class="ra-albert ra-albert--small">
          <img
            :src="profImage('albert-v2-idea')"
            alt=""
            class="ra-albert__img"
          />
          <div class="ra-bubble ra-bubble--night">{{
            selectSay(synthesis)
          }}</div>
        </div>

        <div class="ra-actions">
          <button
            type="button"
            class="ra-cta ra-cta--accent ra-cta--big"
            :disabled="!selected.length || busy"
            data-testid="auto-generate"
            @click="emit('generate')"
            >{{
              selected.length
                ? $t('repertoireAuto.selectScreen.build', {
                    n: selected.length
                  })
                : $t('repertoireAuto.selectScreen.pickOne')
            }}</button
          >
        </div>
      </div>

      <div class="ra-cols__main">
        <div class="ra-select__head">
          <span class="ra-kicker">{{
            $t('repertoireAuto.selectScreen.choose')
          }}</span>
          <button
            type="button"
            class="ra-link"
            data-testid="auto-toggle-all"
            @click="emit('toggleAll')"
            >{{
              allSelected
                ? $t('repertoireAuto.selectScreen.removeAll')
                : $t('repertoireAuto.selectScreen.keepAll')
            }}</button
          >
        </div>

        <button
          v-for="family in synthesis.families"
          :key="family.key"
          type="button"
          class="ra-family"
          :class="{ 'ra-family--on': selected.includes(family.key) }"
          data-testid="auto-family"
          :aria-pressed="selected.includes(family.key)"
          @click="emit('toggle', family.key)"
        >
          <div class="ra-family__row">
            <span class="ra-family__box">{{
              selected.includes(family.key) ? '✓' : ''
            }}</span>
            <span
              class="ra-family__side"
              :class="`ra-family__side--${family.color}`"
              >{{ family.color === 'white' ? '♔︎' : '♚︎' }}</span
            >
            <span class="ra-family__text">
              <span class="ra-family__name">{{ family.family }}</span>
              <span class="ra-family__sub">{{
                $t('repertoireAuto.selectScreen.familyGames', {
                  side:
                    family.color === 'white'
                      ? $t('repertoireAuto.selectScreen.withWhite')
                      : $t('repertoireAuto.selectScreen.withBlack'),
                  n: family.games
                })
              }}</span>
            </span>
            <span class="ra-family__freq">{{
              $t('repertoireAuto.selectScreen.percent', {
                n: percent(family.games, synthesis.games)
              })
            }}</span>
          </div>
          <div class="ra-family__track">
            <div
              class="ra-family__fill"
              :style="{ width: `${percent(family.games, top)}%` }"
            />
          </div>
          <div class="ra-family__row">
            <div class="ra-wdl">
              <div
                v-for="o in OUTCOMES"
                :key="o.key"
                :style="{
                  width: `${percent(family[o.key], family.games)}%`,
                  background: o.color
                }"
              />
            </div>
            <span class="ra-family__score">{{
              $t('repertoireAuto.selectScreen.familyScore', {
                n: score(family)
              })
            }}</span>
          </div>
          <span class="ra-family__note">{{
            familyNote(family, synthesis)
          }}</span>
        </button>

        <div v-if="error" class="ra-error" data-testid="auto-error">{{
          error
        }}</div>
      </div>
    </div>
  </div>
</template>

<script setup>
/**
 * Third step: what the user plays (totals, Albert's summary) and the opening families to keep,
 * each with its share of the games, its results and a word on it.
 */
import { computed } from 'vue'
import { profImage } from '@/utils/prof/images'
import {
  OUTCOMES,
  familyNote,
  percent,
  score,
  selectSay
} from '@/utils/repertoireAuto'

const props = defineProps({
  /** @type {import('vue').PropType<import('@/utils/repertoireAuto').Synthesis>} */
  synthesis: { type: Object, required: true },
  /** @type {import('vue').PropType<string[]>} family keys kept */
  selected: { type: Array, required: true },
  busy: { type: Boolean, default: false },
  error: { type: String, default: '' }
})
const emit = defineEmits({
  toggle: key => typeof key === 'string',
  toggleAll: null,
  generate: null
})

const allSelected = computed(
  () => props.selected.length === props.synthesis.families.length
)
/** Games of the most played family: the frequency bars' full width. */
const top = computed(() => props.synthesis.families[0]?.games ?? 1)
</script>

<style scoped lang="scss">
.ra-tiles {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 8px;
}

.ra-tile {
  background: var(--ra-card);
  border-radius: 18px;
  padding: 12px;
  display: flex;
  flex-direction: column;
  gap: 2px;

  &--accent {
    background: var(--ra-accent);
    color: var(--ra-night);
  }
}

.ra-tile__value {
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 26px;
  line-height: 1;
}

.ra-tile__label {
  font-size: 12px;
  font-weight: 700;
}

.ra-select__head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 4px 2px 0;
}

.ra-family {
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding: 14px;
  border-radius: 20px;
  border: 2px solid var(--ra-line);
  background: var(--ra-card);
  text-align: left;
  cursor: pointer;
  color: var(--ra-ink);
  opacity: 0.6;
  transition:
    border-color 0.2s,
    opacity 0.2s;

  &--on {
    border-color: var(--ra-ink);
    opacity: 1;
  }
}

.ra-family__row {
  display: flex;
  align-items: center;
  gap: 10px;
  width: 100%;
}

.ra-family__box {
  width: 26px;
  height: 26px;
  flex: none;
  border-radius: 9px;
  border: 2px solid var(--ra-faint);
  background: var(--ra-card);
  color: var(--ra-night);
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 800;
  font-size: 15px;
  transition: background 0.2s;

  .ra-family--on & {
    border-color: var(--ra-night);
    background: var(--ra-accent);
  }
}

.ra-family__side {
  width: 30px;
  height: 30px;
  flex: none;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-family: 'Segoe UI Symbol', 'DejaVu Sans', serif;
  font-size: 19px;

  &--white {
    background: var(--ra-subtle);
    color: var(--ra-ink);
  }

  &--black {
    background: var(--ra-night);
    color: #fff;
  }
}

.ra-family__text {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
}

.ra-family__name {
  font-weight: 800;
  font-size: 15px;
}

.ra-family__sub {
  font-size: 12px;
  color: var(--ra-muted);
}

.ra-family__freq {
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 20px;
}

.ra-family__track {
  height: 6px;
  border-radius: 6px;
  background: var(--ra-subtle);
  overflow: hidden;
  width: 100%;
}

.ra-family__fill {
  height: 100%;
  background: var(--ra-hero);
  border-radius: 6px;
}

.ra-wdl {
  flex: 1;
  display: flex;
  height: 8px;
  border-radius: 8px;
  overflow: hidden;
  gap: 2px;
}

.ra-family__score {
  font-size: 12px;
  font-weight: 700;
  white-space: nowrap;
}

.ra-family__note {
  font-size: 13px;
  color: var(--ra-ink-soft);
}
</style>
