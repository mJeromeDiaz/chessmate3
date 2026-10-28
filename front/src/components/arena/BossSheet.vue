<template>
  <q-dialog
    :model-value="!!boss"
    :position="$q.screen.lt.md ? 'bottom' : 'standard'"
    @update:model-value="!$event && emit('close')"
  >
    <div
      v-if="boss"
      class="boss-sheet"
      :style="{
        '--dark': colors.dark,
        '--glow': colors.glow,
        '--accent': colors.accent,
        '--ink': colors.ink,
        '--soft': colors.soft
      }"
      data-testid="arena-sheet"
      :data-boss="boss.character"
    >
      <div class="boss-sheet__scroll">
        <section class="boss-sheet__stage">
          <span class="boss-sheet__glow" aria-hidden="true" />
          <div class="boss-sheet__chips">
            <span class="boss-sheet__level">{{
              $t('arena.level', { n: level })
            }}</span>
            <span v-if="boss.guarded" class="boss-sheet__chip">
              <q-icon name="shield" size="12px" /> Stockfish
            </span>
          </div>
          <img
            v-if="picture.picture"
            :src="picture.picture"
            alt=""
            class="boss-sheet__pose"
            :class="{ 'boss-sheet__pose--dim': dim }"
            draggable="false"
          />
          <span v-else class="boss-sheet__glyph" aria-hidden="true">{{
            picture.glyph
          }}</span>
          <span v-if="mystery" class="boss-sheet__mystery">?</span>
          <span class="boss-sheet__fade" aria-hidden="true" />
          <q-btn
            round
            flat
            dense
            icon="close"
            class="boss-sheet__close"
            :aria-label="$t('arena.sheet.close')"
            data-testid="arena-sheet-close"
            @click="emit('close')"
          />
          <div class="boss-sheet__who">
            <h2 class="boss-sheet__name">{{ name }}</h2>
            <div class="boss-sheet__title">
              {{
                mystery
                  ? $t('arena.masters.mysteryTitle')
                  : sheetText(boss.character, 'title')
              }}
            </div>
            <div v-if="!mystery" class="boss-sheet__quote">
              <img v-if="face" :src="face" alt="" class="boss-sheet__face" />
              <p class="boss-sheet__bubble">
                « {{ bossText(boss.character, 'taunt') }} »
              </p>
            </div>
          </div>
        </section>

        <section class="boss-sheet__body">
          <div class="boss-sheet__facts">
            <div class="boss-sheet__fact">
              <span class="boss-sheet__fact-label">{{
                $t('arena.sheet.facts.elo')
              }}</span>
              <span class="boss-sheet__fact-value">{{ boss.elo }}</span>
            </div>
            <div v-if="boss.openingElo" class="boss-sheet__fact">
              <span class="boss-sheet__fact-label">{{
                $t('arena.sheet.facts.opening')
              }}</span>
              <span class="boss-sheet__fact-value">{{ boss.openingElo }}</span>
            </div>
            <div v-if="boss.endgameElo" class="boss-sheet__fact">
              <span class="boss-sheet__fact-label">{{
                $t('arena.sheet.facts.endgame')
              }}</span>
              <span class="boss-sheet__fact-value">{{ boss.endgameElo }}</span>
            </div>
            <div class="boss-sheet__fact" data-testid="arena-sheet-reward">
              <span class="boss-sheet__fact-label">{{
                $t('arena.sheet.facts.reward')
              }}</span>
              <span class="boss-sheet__fact-value">
                <template v-if="boss.crystals > 0"
                  >{{ boss.crystals }} <CrystalGem :size="16"
                /></template>
                <template v-else>{{ $t('arena.sheet.rewardDone') }}</template>
              </span>
            </div>
            <div class="boss-sheet__fact">
              <span class="boss-sheet__fact-label">{{
                $t('arena.sheet.facts.games')
              }}</span>
              <span class="boss-sheet__fact-value">{{ record }}</span>
            </div>
          </div>

          <p class="boss-sheet__lore">{{
            mystery
              ? $t('arena.masters.mysteryLore')
              : sheetText(boss.character, 'lore')
          }}</p>

          <div v-if="!mystery" class="boss-sheet__columns">
            <div class="boss-sheet__block">
              <h3 class="boss-sheet__h3">{{ $t('arena.sheet.style') }}</h3>
              <div
                v-for="trait in traits"
                :key="trait.key"
                class="boss-sheet__trait"
              >
                <span class="boss-sheet__trait-label">{{ trait.label }}</span>
                <span class="boss-sheet__gauge"
                  ><span
                    class="boss-sheet__gauge-fill"
                    :style="{ width: `${trait.value * 20}%` }"
                /></span>
                <span class="boss-sheet__trait-value">{{ trait.value }}/5</span>
              </div>
            </div>
            <div v-if="prefs.length" class="boss-sheet__block">
              <h3 class="boss-sheet__h3">{{ $t('arena.sheet.prefs') }}</h3>
              <div class="boss-sheet__prefs">
                <span
                  v-for="pref in prefs"
                  :key="pref"
                  class="boss-sheet__pref"
                  >{{ pref }}</span
                >
              </div>
            </div>
          </div>

          <div v-if="!mystery" class="boss-sheet__tip">
            <b
              >{{ $t('arena.sheet.howTo') }} ·
              {{ sheetText(boss.character, 'tipTitle') }}.</b
            >
            {{ sheetText(boss.character, 'tip') }}
            <span v-if="boss.guarded" class="boss-sheet__guarded">{{
              $t('arena.sheet.guarded')
            }}</span>
          </div>

          <div v-if="playable" class="boss-sheet__setup">
            <h3 class="boss-sheet__h3">{{ $t('arena.sheet.yourGame') }}</h3>
            <div class="boss-sheet__segments">
              <div class="boss-sheet__segment" role="radiogroup">
                <button
                  v-for="option in COLORS"
                  :key="option"
                  type="button"
                  role="radio"
                  class="boss-sheet__option"
                  :class="{ 'boss-sheet__option--on': color === option }"
                  :aria-checked="color === option"
                  :data-testid="`arena-color-${option}`"
                  @click="color = option"
                >
                  {{ $t(`arena.sheet.colors.${option}`) }}
                </button>
              </div>
              <div class="boss-sheet__segment" role="radiogroup">
                <button
                  v-for="option in cadences"
                  :key="option"
                  type="button"
                  role="radio"
                  class="boss-sheet__option"
                  :class="{ 'boss-sheet__option--on': cadence === option }"
                  :aria-checked="cadence === option"
                  :data-testid="`arena-cadence-${option}`"
                  @click="cadence = option"
                >
                  {{ option }}
                </button>
              </div>
            </div>
          </div>
        </section>
      </div>

      <footer class="boss-sheet__foot">
        <p v-if="error" class="boss-sheet__error" role="alert">{{ error }}</p>
        <button
          v-if="playable"
          type="button"
          class="boss-sheet__cta"
          data-testid="arena-play"
          :disabled="busy"
          @click="emit('play', { color, cadence })"
        >
          {{ $t('arena.sheet.play', { name }) }}
        </button>
        <template v-else-if="boss.status === 'shop'">
          <button
            type="button"
            class="boss-sheet__cta boss-sheet__cta--buy"
            data-testid="arena-buy"
            :disabled="busy || missing > 0"
            @click="emit('buy')"
          >
            {{ $t('arena.sheet.buy') }} · <CrystalGem :size="18" />
            {{ boss.price }}
          </button>
          <p v-if="missing > 0" class="boss-sheet__missing">
            {{ $t('arena.sheet.missing', { n: missing }) }}
          </p>
        </template>
        <div v-else class="boss-sheet__locked" data-testid="arena-locked">
          <span>{{ hint.text }}</span>
          <span v-if="feat" class="boss-sheet__feat" data-testid="arena-feat">{{
            $t('arena.feat.progress', feat)
          }}</span>
          <router-link
            v-if="hint.to"
            :to="hint.to"
            class="boss-sheet__go"
            data-testid="arena-locked-go"
            >{{ hint.go }}</router-link
          >
        </div>
      </footer>
    </div>
  </q-dialog>
</template>

<script setup>
/**
 * A boss's sheet in the arena (design « Défier les boss »): standing with its title and its taunt,
 * its strength, what its first defeat pays and the player's results, its story, style and
 * preferences, how to beat it; then the game's side and time control, or its price, or where to
 * beat it first. A professor of the last level: the feat that brings them (§ 2 bis); Ray, before
 * he arrives, only his silhouette.
 */
import { computed, ref, watch } from 'vue'
import CrystalGem from '@/components/shop/CrystalGem.vue'
import { t } from '@/i18n'
import {
  bossText,
  canPlay,
  cardPicture,
  displayName,
  faceOf,
  isMystery,
  lockedHint,
  paletteOf,
  sheetText,
  stylesOf,
  traitsOf
} from '@/utils/arena'

const COLORS = ['white', 'black', 'random']

const props = defineProps({
  /** @type {import('vue').PropType<import('@/utils/arena').ArenaBoss|null>} */
  boss: { type: Object, default: null },
  /** @type {import('vue').PropType<string[]>} */
  cadences: { type: Array, default: () => [] },
  defaultCadence: { type: String, default: '' },
  balance: { type: Number, default: 0 },
  busy: { type: Boolean, default: false },
  error: { type: String, default: '' }
})

const emit = defineEmits(['close', 'buy', 'play'])

const color = ref('white')
const cadence = ref(props.defaultCadence)

watch(
  () => props.defaultCadence,
  value => {
    if (!props.cadences.includes(cadence.value)) cadence.value = value
  }
)

const colors = computed(() => paletteOf(props.boss?.character ?? ''))
const level = computed(() => props.boss?.level ?? 1)
const mystery = computed(() => !!props.boss && isMystery(props.boss))
const name = computed(() => (props.boss ? displayName(props.boss) : ''))
const picture = computed(() => cardPicture(props.boss?.character ?? ''))
const face = computed(() => (props.boss ? faceOf(props.boss.character) : ''))
const playable = computed(() => !!props.boss && canPlay(props.boss))
const dim = computed(
  () => !props.boss || ['locked', 'soon'].includes(props.boss.status)
)
const traits = computed(() =>
  props.boss ? traitsOf(props.boss.character) : []
)
const prefs = computed(() => (props.boss ? stylesOf(props.boss.styles) : []))
const record = computed(() => {
  const boss = props.boss
  if (!boss || boss.won + boss.drawn + boss.lost === 0)
    return t('arena.sheet.noGames')
  return t('arena.sheet.record', {
    won: boss.won,
    drawn: boss.drawn,
    lost: boss.lost
  })
})
const missing = computed(() =>
  props.boss?.price ? Math.max(0, props.boss.price - props.balance) : 0
)
const hint = computed(() =>
  props.boss ? lockedHint(props.boss) : { text: '', to: null, go: '' }
)
/** A professor's feat counted (Ray's, one run: no count). */
const feat = computed(() => {
  const value = props.boss?.feat
  return value && value.goal > 1 && props.boss?.status === 'locked'
    ? value
    : null
})
</script>

<style scoped lang="scss">
.boss-sheet {
  width: 100%;
  max-height: calc(100dvh - 46px);
  background: var(--cm-page);
  border-radius: 28px 28px 0 0;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  color: var(--cm-ink);
}

.boss-sheet__scroll {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
}

.boss-sheet__stage {
  position: relative;
  min-height: 420px;
  background: var(--dark);
  color: #fff;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  justify-content: flex-end;
}

.boss-sheet__glow {
  position: absolute;
  left: 50%;
  top: 40%;
  width: 420px;
  height: 420px;
  transform: translate(-50%, -50%);
  background: radial-gradient(circle, var(--glow), transparent 64%);
}

.boss-sheet__chips {
  position: absolute;
  left: 14px;
  top: 14px;
  z-index: 2;
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
}

.boss-sheet__level,
.boss-sheet__chip {
  font-weight: 800;
  font-size: 11px;
  letter-spacing: 0.06em;
  padding: 5px 10px;
  border-radius: 999px;
}

.boss-sheet__level {
  background: var(--accent);
  color: #1b1530;
}

.boss-sheet__chip {
  background: rgba(255, 255, 255, 0.16);
  display: inline-flex;
  align-items: center;
  gap: 4px;
}

.boss-sheet__pose {
  position: absolute;
  left: 50%;
  top: 20px;
  transform: translateX(-50%);
  height: 300px;
  max-width: 92%;
  object-fit: contain;
  object-position: bottom;
  user-select: none;
}

.boss-sheet__pose--dim {
  filter: grayscale(1) brightness(0.55);
}

.boss-sheet__glyph {
  position: absolute;
  left: 50%;
  top: 150px;
  transform: translate(-50%, -50%);
  font-size: 170px;
  line-height: 1;
  color: var(--accent);
  text-shadow: 0 10px 40px rgba(0, 0, 0, 0.5);
}

.boss-sheet__mystery {
  position: absolute;
  right: 40px;
  top: 40px;
  z-index: 2;
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 60px;
  color: var(--cm-lime);
  transform: rotate(12deg);
}

.boss-sheet__fade {
  position: absolute;
  left: 0;
  right: 0;
  bottom: 0;
  height: 240px;
  background: linear-gradient(to bottom, rgba(0, 0, 0, 0), var(--dark) 55%);
}

.boss-sheet__close {
  position: absolute;
  right: 14px;
  top: 14px;
  z-index: 3;
  background: rgba(255, 255, 255, 0.16);
  color: #fff;
}

.boss-sheet__who {
  position: relative;
  z-index: 1;
  padding: 0 20px 20px;
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.boss-sheet__name {
  margin: 0;
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 36px;
  line-height: 0.95;
}

.boss-sheet__title {
  margin-top: -6px;
  font-family: var(--cm-heading);
  font-weight: 600;
  font-size: 16px;
  color: var(--accent);
}

.boss-sheet__quote {
  display: flex;
  gap: 10px;
  align-items: flex-end;
}

.boss-sheet__face {
  width: 58px;
  height: 58px;
  object-fit: contain;
  object-position: bottom;
  flex: none;
}

.boss-sheet__bubble {
  flex: 1;
  margin: 0;
  background: #fff;
  color: #1b1530;
  border-radius: 18px 18px 18px 4px;
  padding: 10px 14px;
  font-size: 15px;
  line-height: 1.4;
  font-style: italic;
  text-wrap: pretty;
}

.boss-sheet__body {
  padding: 20px;
  display: flex;
  flex-direction: column;
  gap: 20px;
}

.boss-sheet__facts {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(96px, 1fr));
  gap: 8px;
}

.boss-sheet__fact {
  background: var(--cm-surface);
  border-radius: 16px;
  padding: 12px 14px;
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.boss-sheet__fact-label {
  font-size: 10.5px;
  font-weight: 800;
  letter-spacing: 0.06em;
  color: var(--cm-muted);
}

.boss-sheet__fact-value {
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 17px;
  display: inline-flex;
  align-items: center;
  gap: 4px;
}

.boss-sheet__lore {
  margin: 0;
  font-size: 14.5px;
  line-height: 1.5;
  color: var(--cm-ink-soft);
  text-wrap: pretty;
}

.boss-sheet__columns {
  display: grid;
  gap: 20px;
}

.boss-sheet__block {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.boss-sheet__h3 {
  margin: 0;
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 18px;
  line-height: 1.2;
}

.boss-sheet__trait {
  display: grid;
  grid-template-columns: 104px minmax(0, 1fr) 28px;
  align-items: center;
  gap: 10px;
}

.boss-sheet__trait-label {
  font-size: 13.5px;
  font-weight: 700;
}

.boss-sheet__gauge {
  height: 8px;
  border-radius: 99px;
  background: var(--cm-line);
  overflow: hidden;
}

.boss-sheet__gauge-fill {
  display: block;
  height: 100%;
  border-radius: 99px;
  background: var(--ink);
}

.boss-sheet__trait-value {
  font-size: 12px;
  font-weight: 800;
  color: var(--cm-muted);
  text-align: right;
}

.boss-sheet__prefs {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.boss-sheet__pref {
  background: var(--soft);
  color: #1b1530;
  font-size: 13px;
  font-weight: 700;
  padding: 7px 12px;
  border-radius: 999px;
}

.boss-sheet__tip {
  background: var(--cm-surface);
  border: 2px solid var(--cm-line);
  border-radius: 18px;
  padding: 14px 16px;
  font-size: 14px;
  line-height: 1.45;
  color: var(--cm-ink-soft);
  text-wrap: pretty;

  b {
    color: var(--cm-ink);
  }
}

.boss-sheet__guarded {
  display: block;
  margin-top: 6px;
  font-weight: 700;
}

.boss-sheet__setup {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.boss-sheet__segments {
  display: grid;
  gap: 10px;
}

.boss-sheet__segment {
  display: grid;
  grid-auto-columns: minmax(0, 1fr);
  grid-auto-flow: column;
  gap: 6px;
  background: var(--cm-inset);
  padding: 4px;
  border-radius: 14px;
}

.boss-sheet__option {
  height: 40px;
  border: none;
  border-radius: 11px;
  background: transparent;
  color: var(--cm-ink);
  font: inherit;
  font-weight: 700;
  font-size: 13.5px;
  cursor: pointer;
}

.boss-sheet__option--on {
  background: var(--cm-surface);
  box-shadow: 0 1px 2px rgba(27, 21, 48, 0.12);
}

.boss-sheet__foot {
  flex: none;
  padding: 12px 16px 22px;
  background: var(--cm-surface);
  border-top: 1px solid var(--cm-line);
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.boss-sheet__error {
  margin: 0;
  color: var(--cm-danger);
  font-weight: 700;
  font-size: 13.5px;
  text-align: center;
}

.boss-sheet__cta {
  width: 100%;
  height: 58px;
  border: none;
  border-radius: 18px;
  background: var(--cm-brand);
  color: #fff;
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 19px;
  cursor: pointer;
  box-shadow: 0 4px 0 var(--cm-brand-deep);
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;

  &:disabled {
    opacity: 0.55;
    cursor: default;
  }
}

.boss-sheet__cta--buy {
  background: #1b1530;
  box-shadow: 0 4px 0 #000;
}

:global(.body--dark) .boss-sheet__cta--buy {
  background: #fff;
  color: #1b1530;
  box-shadow: 0 4px 0 #b4afc6;
}

.boss-sheet__missing {
  margin: 0;
  text-align: center;
  font-size: 13px;
  font-weight: 700;
  color: var(--cm-muted);
}

.boss-sheet__feat {
  font-size: 13px;
  font-weight: 800;
  color: var(--ink);
}

.boss-sheet__locked {
  min-height: 58px;
  border-radius: 18px;
  background: var(--cm-inset);
  color: var(--cm-muted);
  font-weight: 700;
  font-size: 15px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 4px;
  text-align: center;
  padding: 10px 16px;
}

.boss-sheet__go {
  color: var(--cm-brand);
  font-weight: 800;
}

@media (min-width: 1024px) {
  .boss-sheet {
    width: 1000px;
    max-width: calc(100vw - 48px);
    height: 680px;
    max-height: calc(100vh - 48px);
    border-radius: 28px;
    display: grid;
    grid-template-columns: 400px minmax(0, 1fr);
    grid-template-rows: minmax(0, 1fr) auto;
  }

  .boss-sheet__scroll {
    display: contents;
  }

  .boss-sheet__stage {
    grid-row: 1 / span 2;
    min-height: 0;
  }

  .boss-sheet__pose {
    top: auto;
    bottom: 190px;
    height: 420px;
  }

  .boss-sheet__glyph {
    top: 42%;
  }

  .boss-sheet__name {
    font-size: 40px;
  }

  .boss-sheet__body {
    grid-column: 2;
    grid-row: 1;
    overflow-y: auto;
    padding: 26px 30px 20px;
    gap: 22px;
  }

  .boss-sheet__columns {
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    gap: 28px;
  }

  .boss-sheet__segments {
    grid-template-columns: minmax(0, 1fr) minmax(0, 1.3fr);
  }

  .boss-sheet__foot {
    grid-column: 2;
    grid-row: 2;
    padding: 14px 30px 22px;
  }
}
</style>
