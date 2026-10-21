<template>
  <q-dialog
    :model-value="!!step"
    :maximized="$q.screen.lt.sm"
    transition-show="scale"
    transition-hide="fade"
    @hide="emit('close')"
  >
    <div v-if="step" class="boss-intro" data-testid="boss-intro">
      <button
        type="button"
        class="boss-intro__close"
        :aria-label="$t('mindMapPath.sheet.close')"
        @click="emit('close')"
        >✕</button
      >
      <div class="boss-intro__stage">
        <div class="boss-intro__halo" aria-hidden="true" />
        <BossPortrait
          class="boss-intro__portrait"
          :character="step.character"
          :pose="step.status === 'done' ? 'beaten' : 'idle'"
          :full="step.status !== 'done'"
          :label="name"
        />
      </div>
      <div class="boss-intro__text">
        <span class="boss-intro__kicker">
          {{ $t(`mindMapPath.kinds.${step.kind}`) }}
          <template v-if="step.kind === 'hidden_boss'">
            · {{ $t('mindMapPath.boss.optional') }}</template
          >
          <template v-if="step.status === 'done'">
            · {{ $t('mindMapPath.boss.beaten') }}</template
          >
        </span>
        <h3 class="boss-intro__name" data-testid="boss-name">{{ name }}</h3>
        <p class="boss-intro__tagline">{{ bossLine(step, 'tagline') }}</p>
        <div class="boss-intro__bubble">« {{ bossLine(step, 'taunt') }} »</div>
        <p class="boss-intro__challenge">{{ stepDesc(capacity, step.key) }}</p>

        <div class="boss-intro__lives" aria-hidden="true">
          <q-icon v-for="i in BOSS_LIVES" :key="i" name="favorite" />
        </div>
        <p class="boss-intro__rule">{{
          $t('mindMapPath.boss.lives', { name })
        }}</p>

        <div class="boss-intro__rewards">
          <span class="boss-intro__label">{{
            $t('mindMapPath.boss.rewards')
          }}</span>
          <span class="boss-intro__reward"
            ><CrystalGem :size="22" />{{
              $t('mindMapPath.sheet.crystals', { n: step.crystals })
            }}</span
          >
          <span class="boss-intro__reward boss-intro__reward--xp">{{
            $t('mindMapPath.sheet.xp', { xp: step.xp })
          }}</span>
          <span
            v-for="badge in step.badges"
            :key="badge"
            class="boss-intro__reward"
            ><q-icon name="military_tech" />{{ badgeName(badge) }}</span
          >
        </div>

        <p v-if="step.status === 'soon'" class="boss-intro__note">{{
          $t('mindMapPath.boss.soon')
        }}</p>
        <p v-else-if="step.status === 'locked'" class="boss-intro__note">{{
          $t('mindMapPath.boss.locked')
        }}</p>
        <div v-if="error" class="boss-intro__error" role="alert">{{
          error
        }}</div>

        <div class="boss-intro__actions">
          <q-btn
            v-if="fightable"
            unelevated
            no-caps
            size="lg"
            class="boss-intro__fight"
            :label="
              step.status === 'done'
                ? $t('mindMapPath.boss.fightAgain')
                : $t('mindMapPath.boss.fight')
            "
            :loading="busy"
            data-testid="boss-fight"
            @click="fight"
          />
          <q-btn
            flat
            no-caps
            :label="$t('mindMapPath.boss.later')"
            @click="emit('close')"
          />
        </div>
      </div>
    </div>
  </q-dialog>
</template>

<script setup>
/**
 * A boss before the fight (docs/MIND_MAP.md): its face, who it is and what it says, the challenge,
 * its three lives, what beating it earns, « Affronter ». Locked or without module yet, it only
 * shows itself. A boss with its own music (the Gargouille) plays it while its card is open.
 */
import { computed, onBeforeUnmount, watch } from 'vue'
import { useQuasar } from 'quasar'
import BossPortrait from '@/components/mindMap/BossPortrait.vue'
import CrystalGem from '@/components/shop/CrystalGem.vue'
import {
  BOSS_LIVES,
  badgeName,
  bossLine,
  bossName,
  stepDesc
} from '@/utils/mindMapPath'
import { playAmbientSound } from '@/utils/sounds'

const props = defineProps({
  /** @type {import('vue').PropType<import('@/utils/mindMapPath').PathStep|null>} */
  step: { type: Object, default: null },
  /** The API's capacity (its texts). */
  capacity: { type: String, required: true },
  busy: { type: Boolean, default: false },
  error: { type: String, default: '' }
})

const emit = defineEmits({ start: null, close: null })

const $q = useQuasar()

const name = computed(() => (props.step ? bossName(props.step) : ''))
const fightable = computed(() =>
  ['open', 'done'].includes(props.step?.status ?? '')
)

/** The bosses with a theme of their own, played while their card is open. */
const THEMES =
  /** @type {Record<string, import('@/utils/sounds').AmbientSound>} */ ({
    gargouille: 'gargoyleTheme',
    metronome: 'metronomeTheme',
    jongleur: 'jongleurTheme',
    cyclope: 'cyclopeTheme'
  })
let stopTheme = () => {}
watch(
  () => props.step?.key,
  () => {
    stopTheme()
    const theme = THEMES[props.step?.character ?? '']
    stopTheme = theme ? playAmbientSound(theme, { volume: 0.5 }) : () => {}
  },
  { immediate: true }
)
onBeforeUnmount(() => stopTheme())

/** The bosses whose fight opens on a sound of its own (not stopped: it outlives the card). */
const OPENINGS =
  /** @type {Record<string, import('@/utils/sounds').AmbientSound>} */ ({
    gargouille: 'doors'
  })
function fight() {
  const opening = OPENINGS[props.step?.character ?? '']
  if (opening) playAmbientSound(opening, { volume: 0.7 })
  emit('start', props.step)
}
</script>

<style scoped lang="scss">
.boss-intro {
  position: relative;
  display: flex;
  flex-direction: column;
  width: min(860px, 100vw);
  max-width: 100vw;
  background: linear-gradient(160deg, #2b2250, #1b1530 70%);
  color: #fff;
  overflow-y: auto;

  @media (min-width: $breakpoint-sm-min) {
    flex-direction: row;
    align-items: center;
    border-radius: 28px;
  }
}

.boss-intro__close {
  position: absolute;
  top: 14px;
  right: 14px;
  z-index: 1;
  width: 38px;
  height: 38px;
  border: none;
  border-radius: 12px;
  background: rgba(255, 255, 255, 0.14);
  color: #fff;
  font-weight: 800;
  cursor: pointer;
}

.boss-intro__stage {
  position: relative;
  display: grid;
  place-items: center;
  padding: 36px 40px 8px;

  @media (min-width: $breakpoint-sm-min) {
    flex: 0 0 44%;
    padding: 32px;
  }
}

.boss-intro__halo {
  position: absolute;
  inset: 10%;
  border-radius: 50%;
  background: radial-gradient(
    circle,
    var(--capacity, #8b6bff),
    transparent 68%
  );
  opacity: 0.55;
}

.boss-intro__portrait {
  position: relative;
  width: min(260px, 70vw);
  border-radius: 24px;
  background: #fff;
  box-shadow: 0 18px 40px rgba(0, 0, 0, 0.35);
  animation: boss-intro-in 0.6s cubic-bezier(0.2, 1.4, 0.4, 1) both;
}

// Standing, whole: no card, the halo behind it.
.boss-intro__portrait.boss-portrait--standing {
  width: min(220px, 56vw);
  background: none;
  box-shadow: none;
  filter: drop-shadow(0 18px 24px rgba(0, 0, 0, 0.4));
}

@keyframes boss-intro-in {
  from {
    transform: translateY(30px) scale(0.85) rotate(-4deg);
    opacity: 0;
  }
}

@media (prefers-reduced-motion: reduce) {
  .boss-intro__portrait {
    animation: none;
  }
}

.boss-intro__text {
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding: 16px 24px 28px;

  @media (min-width: $breakpoint-sm-min) {
    flex: 1;
    padding: 36px 36px 32px 8px;
  }
}

.boss-intro__kicker {
  font-size: 12px;
  font-weight: 800;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: #c9b8ff;
}

.boss-intro__name {
  margin: 0;
  font-family: var(--cm-heading);
  font-size: 34px;
  font-weight: 800;
  line-height: 1.05;
}

.boss-intro__tagline,
.boss-intro__challenge,
.boss-intro__rule,
.boss-intro__note {
  margin: 0;
  font-size: 14.5px;
  line-height: 1.5;
  color: #e6e0fa;
}

.boss-intro__bubble {
  align-self: flex-start;
  padding: 10px 14px;
  border-radius: 16px 16px 16px 4px;
  background: #fff;
  color: #1b1530;
  font-weight: 700;
  font-size: 15px;
}

.boss-intro__lives {
  display: flex;
  gap: 6px;
  color: #ff6fae;
  font-size: 26px;
}

.boss-intro__rewards {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
}

.boss-intro__label {
  width: 100%;
  font-size: 11.5px;
  font-weight: 800;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: #c9b8ff;
}

.boss-intro__reward {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 4px 12px 4px 8px;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.12);
  font-size: 13px;
  font-weight: 800;

  &--xp {
    padding-left: 12px;
    background: #c6f432;
    color: #1b1530;
  }
}

.boss-intro__error {
  padding: 10px 12px;
  border-radius: 12px;
  background: #ffe6e3;
  color: #b4231a;
  font-weight: 700;
}

.boss-intro__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 6px;
}

.boss-intro__fight {
  background: #c6f432;
  color: #1b1530;
  font-weight: 800;
}
</style>
