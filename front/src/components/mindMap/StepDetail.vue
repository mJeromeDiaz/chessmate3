<template>
  <div
    class="step-detail"
    :class="[`step-detail--${step.kind}`, `step-detail--${step.status}`]"
    data-testid="step-detail"
    :data-step="step.key"
    :data-kind="step.kind"
  >
    <!-- A boss: its stage, standing, then who it is. -->
    <template v-if="boss">
      <div class="step-detail__stage">
        <div class="step-detail__halo" aria-hidden="true" />
        <span v-if="step.status === 'hidden'" class="step-detail__mystery"
          >?</span
        >
        <BossPortrait
          v-else
          class="step-detail__portrait"
          :character="step.character"
          :pose="step.status === 'done' ? 'beaten' : 'idle'"
          :full="step.status !== 'done'"
          :label="name"
        />
        <button
          v-if="closable"
          type="button"
          class="step-detail__close step-detail__close--stage"
          :aria-label="$t('mindMapPath.sheet.close')"
          @click="emit('close')"
          >✕</button
        >
      </div>
      <div class="step-detail__body">
        <div class="step-detail__tags">
          <span class="step-detail__tag step-detail__tag--kind">{{
            kindTag
          }}</span>
          <span class="step-detail__tag">{{ place }}</span>
        </div>
        <h3 class="step-detail__name" data-testid="boss-name">{{ name }}</h3>
        <template v-if="step.status !== 'hidden'">
          <p class="step-detail__tagline">{{ bossLine(step, 'tagline') }}</p>
          <div class="step-detail__bubble">
            « {{ bossLine(step, 'taunt') }} »
          </div>
          <p class="step-detail__text">{{ stepDesc(capacity, step.key) }}</p>
          <div class="step-detail__lives" aria-hidden="true">
            <q-icon v-for="i in BOSS_LIVES" :key="i" name="favorite" />
          </div>
          <p class="step-detail__note">{{
            $t('mindMapPath.boss.lives', { name })
          }}</p>
        </template>
        <p v-else class="step-detail__text">{{
          $t('mindMapPath.sheet.hiddenText')
        }}</p>
      </div>
    </template>

    <!-- A chest: itself, what it holds. -->
    <template v-else-if="step.kind === 'chest'">
      <div class="step-detail__chest">
        <button
          v-if="closable"
          type="button"
          class="step-detail__close step-detail__close--corner"
          :aria-label="$t('mindMapPath.sheet.close')"
          @click="emit('close')"
          >✕</button
        >
        <WoodChest :state="chestState" :size="1.3" />
        <span class="step-detail__chest-kicker">{{
          $t('mindMapPath.kinds.chest')
        }}</span>
        <h3 class="step-detail__chest-title">{{
          step.status === 'done'
            ? $t('mindMapPath.page.chestOpened')
            : $t('mindMapPath.sheet.crystals', { n: step.crystals })
        }}</h3>
        <span class="step-detail__place">{{ place }}</span>
      </div>
      <div class="step-detail__body">
        <p class="step-detail__text">{{
          step.status === 'done'
            ? $t('mindMapPath.sheet.chestEarned', { n: step.crystals })
            : $t('mindMapPath.sheet.chestLocked')
        }}</p>
      </div>
    </template>

    <!-- An exercise: its head in the capacity's colour, what it asks, its passes. -->
    <template v-else>
      <div class="step-detail__head">
        <div class="step-detail__head-row">
          <span class="step-detail__kicker">
            <template v-if="step.number"
              >{{ $t('mindMapPath.page.step', { n: step.number }) }} · </template
            >{{ $t(`mindMapPath.kinds.${step.kind}`) }}
          </span>
          <button
            v-if="closable"
            type="button"
            class="step-detail__close"
            :aria-label="$t('mindMapPath.sheet.close')"
            @click="emit('close')"
            >✕</button
          >
        </div>
        <h3 class="step-detail__title">{{ stepTitle(capacity, step.key) }}</h3>
        <span class="step-detail__place">{{ place }}</span>
      </div>
      <div class="step-detail__body">
        <span class="step-detail__label">{{
          $t('mindMapPath.detail.brief')
        }}</span>
        <p class="step-detail__text">{{ stepDesc(capacity, step.key) }}</p>
        <div class="step-detail__passes" data-testid="step-passes">
          <span
            v-for="i in step.required"
            :key="i"
            class="step-detail__pass"
            :class="{
              'step-detail__pass--done':
                i <= step.passes || step.status === 'done'
            }"
          />
          <span>{{
            step.status === 'done'
              ? $t('mindMapPath.sheet.passesDone', { total: step.required })
              : $t('mindMapPath.sheet.passes', {
                  n: Math.min(step.passes + 1, step.required),
                  total: step.required
                })
          }}</span>
        </div>
        <p class="step-detail__note">{{ $t('mindMapPath.sheet.rule') }}</p>
        <p v-if="step.status === 'skipped'" class="step-detail__note">{{
          $t('mindMapPath.sheet.skippedNote')
        }}</p>
      </div>
    </template>

    <div class="step-detail__foot">
      <div v-if="step.kind !== 'chest'" class="step-detail__rewards">
        <span v-if="step.crystals" class="step-detail__reward"
          ><CrystalGem :size="20" />{{
            $t('mindMapPath.sheet.crystals', { n: step.crystals })
          }}</span
        >
        <span class="step-detail__reward step-detail__reward--xp">{{
          $t('mindMapPath.sheet.xp', { xp: step.xp })
        }}</span>
        <span
          v-for="badge in step.badges"
          :key="badge"
          class="step-detail__reward"
          ><q-icon name="military_tech" />{{ badgeName(badge) }}</span
        >
      </div>
      <span class="step-detail__status" data-testid="step-detail-status">{{
        statusText
      }}</span>
      <div v-if="error" class="step-detail__error" role="alert">{{
        error
      }}</div>

      <template v-if="boss">
        <q-btn
          v-if="fightable"
          unelevated
          no-caps
          size="lg"
          class="step-detail__go"
          :label="
            step.status === 'done'
              ? $t('mindMapPath.boss.fightAgain')
              : $t('mindMapPath.boss.fight')
          "
          :loading="busy === 'start'"
          :disable="!!busy"
          data-testid="boss-fight"
          @click="fight"
        />
        <q-btn
          v-else
          unelevated
          no-caps
          size="lg"
          disable
          class="step-detail__go step-detail__go--off"
          :label="offLabel"
        />
      </template>

      <template v-else-if="step.kind === 'exercise'">
        <q-btn
          v-if="playable"
          unelevated
          no-caps
          size="lg"
          class="step-detail__go"
          :label="startLabel"
          :loading="busy === 'start'"
          :disable="!!busy"
          data-testid="step-start"
          @click="emit('start', step)"
        />
        <q-btn
          v-else
          unelevated
          no-caps
          size="lg"
          disable
          class="step-detail__go step-detail__go--off"
          :label="offLabel"
        />
        <q-btn
          v-if="step.skippable"
          outline
          no-caps
          :label="$t('mindMapPath.sheet.skip')"
          :loading="busy === 'skip'"
          :disable="!!busy"
          data-testid="step-skip"
          @click="confirmSkip"
        />
      </template>
    </div>
  </div>
</template>

<script setup>
/**
 * A step of the path shown in full (design « Parcours zone »): in the column beside the path on a
 * wide screen, in a sheet from the bottom on a phone. A boss (its stage, who it is, what it says,
 * its challenge, its three lives, « Affronter »), a chest (what it holds), an exercise (what it
 * asks, its passes, « Commencer » or « Passer »); then what it earns, where it stands, its button
 * (locked: greyed). A boss chosen by the player plays its theme while it is shown.
 */
import { computed, onBeforeUnmount, watch } from 'vue'
import { useQuasar } from 'quasar'
import BossPortrait from '@/components/mindMap/BossPortrait.vue'
import WoodChest from '@/components/mindMap/WoodChest.vue'
import CrystalGem from '@/components/shop/CrystalGem.vue'
import {
  BOSS_LIVES,
  badgeName,
  bossLine,
  bossName,
  isBoss,
  stepDesc,
  stepTitle
} from '@/utils/mindMapPath'
import { playAmbientSound } from '@/utils/sounds'
import { t } from '@/i18n'

const props = defineProps({
  /** @type {import('vue').PropType<import('@/utils/mindMapPath').PathStep>} */
  step: { type: Object, required: true },
  /** The API's capacity (its texts). */
  capacity: { type: String, required: true },
  /** « Chapitre 2 · Vision aveugle statique · Section 2.1 ». */
  place: { type: String, default: '' },
  /** @type {import('vue').PropType<''|'start'|'skip'>} */
  busy: { type: String, default: '' },
  error: { type: String, default: '' },
  /** In a sheet: a close button. */
  closable: { type: Boolean, default: false },
  /** Chosen by the player (not shown by default): a boss plays its theme. */
  chosen: { type: Boolean, default: false }
})

const emit = defineEmits({ start: null, skip: null, close: null })

const $q = useQuasar()

const boss = computed(() => isBoss(props.step.kind))
const name = computed(() =>
  props.step.status === 'hidden'
    ? t('mindMapPath.kinds.hidden_boss')
    : bossName(props.step)
)
const kindTag = computed(() =>
  props.step.kind === 'boss'
    ? t('mindMapPath.page.bossOfChapter')
    : t(`mindMapPath.kinds.${props.step.kind}`)
)

const fightable = computed(() => ['open', 'done'].includes(props.step.status))
const playable = computed(() =>
  ['open', 'done', 'skipped'].includes(props.step.status)
)
const offLabel = computed(() =>
  props.step.status === 'soon'
    ? t('mindMapPath.detail.soonButton')
    : t('mindMapPath.detail.lockedButton')
)

const startLabel = computed(() => {
  if (props.step.status === 'done') return t('mindMapPath.sheet.practice')
  return props.step.passes > 0
    ? t('mindMapPath.sheet.again')
    : t('mindMapPath.sheet.start')
})

/** Earned and not opened yet: ready; earned and opened: open; else locked. */
const chestState = computed(() => {
  if (props.step.status !== 'done') return 'locked'
  return props.step.chest && !props.step.chest.opened ? 'ready' : 'opened'
})

const statusText = computed(() => {
  const kind = boss.value ? 'boss' : props.step.kind
  const status =
    kind === 'chest'
      ? props.step.status === 'done'
        ? 'done'
        : 'locked'
      : props.step.status
  return t(`mindMapPath.detail.status.${kind}.${status}`)
})

function confirmSkip() {
  const step = props.step
  $q.dialog({
    title: t('mindMapPath.sheet.skipTitle'),
    message: t('mindMapPath.sheet.skipText'),
    cancel: true
  }).onOk(() => emit('skip', step))
}

/** The bosses with a theme of their own, played while they are shown (chosen by the player). */
const THEMES =
  /** @type {Record<string, import('@/utils/sounds').AmbientSound>} */ ({
    gargouille: 'gargoyleTheme',
    metronome: 'metronomeTheme',
    jongleur: 'jongleurTheme',
    cyclope: 'cyclopeTheme'
  })
let stopTheme = () => {}
watch(
  () => [props.step.key, props.chosen],
  () => {
    stopTheme()
    const theme =
      props.chosen && boss.value && props.step.status !== 'hidden'
        ? THEMES[props.step.character ?? '']
        : undefined
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
  const opening = OPENINGS[props.step.character ?? '']
  if (opening) playAmbientSound(opening, { volume: 0.7 })
  emit('start', props.step)
}
</script>

<style scoped lang="scss">
.step-detail {
  display: flex;
  flex-direction: column;
  overflow: hidden;
  border-radius: 28px;
  background: var(--cm-surface);
  color: var(--cm-ink);
  box-shadow:
    0 0 0 1px var(--cm-line),
    var(--cm-card-shadow);
}

.step-detail__close {
  flex: none;
  width: 34px;
  height: 34px;
  border: none;
  border-radius: 12px;
  background: rgba(27, 21, 48, 0.15);
  color: inherit;
  font-size: 16px;
  font-weight: 800;
  cursor: pointer;

  &--stage,
  &--corner {
    position: absolute;
    top: 14px;
    right: 14px;
  }

  &--stage {
    background: rgba(255, 255, 255, 0.12);
    color: #fff;
  }

  &--corner {
    background: var(--cm-subtle);
  }
}

// A boss's stage: dark in both themes, the capacity's glow behind it.
.step-detail__stage {
  position: relative;
  display: flex;
  flex: none;
  align-items: flex-end;
  justify-content: center;
  height: 250px;
  overflow: hidden;
  background: radial-gradient(
    circle at 50% 70%,
    color-mix(in srgb, var(--capacity, #8b6bff) 33%, transparent),
    #1b1530 70%
  );
}

.step-detail__halo {
  position: absolute;
  inset: 18% 22% 0;
  border-radius: 50%;
  background: radial-gradient(
    circle,
    var(--capacity, #8b6bff),
    transparent 68%
  );
  opacity: 0.45;
}

.step-detail__portrait {
  position: relative;
  width: 210px;
  margin-bottom: 12px;
  border-radius: 20px;
  background: #fff;
  box-shadow: 0 18px 40px rgba(0, 0, 0, 0.35);
}

// Standing, whole: no card, its feet at the stage's floor.
.step-detail__portrait.boss-portrait--standing {
  width: auto;
  height: 232px;
  margin-bottom: 0;
  background: none;
  box-shadow: none;
  filter: drop-shadow(0 18px 24px rgba(0, 0, 0, 0.4));
}

.step-detail--locked .step-detail__portrait,
.step-detail--soon .step-detail__portrait {
  filter: grayscale(0.8) brightness(0.6);
}

.step-detail__mystery {
  position: relative;
  align-self: center;
  font-family: var(--cm-heading);
  font-size: 72px;
  font-weight: 800;
  color: #4a4460;
}

.step-detail__body {
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding: 20px 24px 4px;
}

.step-detail__tags {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.step-detail__tag {
  padding: 5px 11px;
  border-radius: 999px;
  background: var(--cm-subtle);
  color: var(--cm-muted);
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.07em;
  text-transform: uppercase;

  &--kind {
    background: var(--capacity, var(--cm-brand));
    color: var(--capacity-ink, #fff);
  }
}

.step-detail--boss .step-detail__tag--kind {
  background: #ffd43b;
  color: #1b1530;
}

.step-detail__name {
  margin: 0;
  font-family: var(--cm-heading);
  font-size: 30px;
  font-weight: 800;
  line-height: 1.05;
}

.step-detail__tagline {
  margin: -6px 0 0;
  font-family: var(--cm-heading);
  font-size: 17px;
  font-weight: 600;
  color: var(--capacity-deep, var(--cm-brand));
}

.step-detail__bubble {
  align-self: flex-start;
  padding: 10px 14px;
  border-radius: 4px 16px 16px 16px;
  background: var(--cm-subtle);
  font-size: 14px;
  font-style: italic;
  font-weight: 600;
}

.step-detail__text {
  margin: 0;
  font-size: 14.5px;
  line-height: 1.5;
  text-wrap: pretty;
}

.step-detail__label {
  font-size: 12px;
  font-weight: 800;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--cm-muted);
}

.step-detail__lives {
  display: flex;
  gap: 4px;
  color: #ff5a7a;
  font-size: 22px;
}

.step-detail__note {
  margin: 0;
  font-size: 13px;
  line-height: 1.45;
  color: var(--cm-muted);
}

// An exercise's head, in the capacity's colour.
.step-detail__head {
  display: flex;
  flex-direction: column;
  gap: 6px;
  padding: 22px 24px;
  background: var(--capacity, var(--cm-brand));
  color: var(--capacity-ink, #fff);
}

.step-detail__head-row {
  display: flex;
  align-items: center;
  gap: 10px;
}

.step-detail__kicker {
  flex: 1;
  font-size: 12px;
  font-weight: 800;
  letter-spacing: 0.06em;
  text-transform: uppercase;
}

.step-detail__title {
  margin: 0;
  font-family: var(--cm-heading);
  font-size: 26px;
  font-weight: 800;
  line-height: 1.1;
  text-wrap: balance;
}

.step-detail__place {
  font-size: 13px;
  font-weight: 700;
}

.step-detail__passes {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  font-weight: 800;
}

.step-detail__pass {
  width: 22px;
  height: 8px;
  border-radius: 4px;
  background: var(--cm-subtle);

  &--done {
    background: var(--capacity, var(--cm-brand));
  }
}

// A chest: itself in the middle.
.step-detail__chest {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 10px;
  padding: 28px 24px 8px;
  text-align: center;

  .step-detail__place {
    color: var(--cm-muted);
  }
}

.step-detail__chest-kicker {
  margin-top: 6px;
  font-size: 12px;
  font-weight: 800;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: #c99a00;
}

.step-detail__chest-title {
  margin: 0;
  font-family: var(--cm-heading);
  font-size: 26px;
  font-weight: 800;
}

.step-detail__foot {
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding: 16px 24px 24px;
}

.step-detail__rewards {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.step-detail__reward {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 5px 12px;
  border-radius: 999px;
  background: var(--cm-brand-soft);
  color: var(--cm-brand-deep);
  font-size: 13px;
  font-weight: 800;

  &--xp {
    background: var(--cm-subtle);
    color: var(--cm-ink);
  }
}

.step-detail__status {
  margin-top: 4px;
  font-size: 12px;
  font-weight: 800;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--capacity-deep, var(--cm-brand));
}

.step-detail--locked .step-detail__status,
.step-detail--soon .step-detail__status {
  color: var(--cm-muted);
}

.step-detail__error {
  padding: 10px 12px;
  border-radius: 12px;
  background: var(--cm-danger-soft);
  color: var(--cm-danger);
  font-size: 13.5px;
  font-weight: 700;
}

.step-detail__go {
  border-radius: 16px;
  background: var(--capacity, var(--cm-brand));
  color: var(--capacity-ink, #fff);
  font-weight: 800;
  box-shadow: 0 5px 0 var(--capacity-deep, var(--cm-brand-deep));

  &--off {
    background: var(--cm-subtle);
    color: var(--cm-muted);
    box-shadow: 0 5px 0 var(--cm-dash);
  }
}
</style>
