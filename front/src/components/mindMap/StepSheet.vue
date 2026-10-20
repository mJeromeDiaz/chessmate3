<template>
  <q-dialog
    :model-value="!!step"
    :position="$q.screen.lt.sm ? 'bottom' : 'standard'"
    @hide="emit('close')"
  >
    <div v-if="step" class="step-sheet" data-testid="step-sheet">
      <header class="step-sheet__head">
        <span class="step-sheet__kicker">
          <template v-if="step.number">{{ step.number }} · </template
          >{{ $t(`mindMapPath.kinds.${step.kind}`) }}
        </span>
        <button
          type="button"
          class="step-sheet__close"
          :aria-label="$t('mindMapPath.sheet.close')"
          @click="emit('close')"
          >✕</button
        >
      </header>
      <h3 class="step-sheet__title">{{
        step.status === 'hidden'
          ? $t('mindMapPath.kinds.hidden_boss')
          : stepTitle(capacity, step.key)
      }}</h3>
      <p class="step-sheet__desc">{{
        step.status === 'hidden'
          ? $t('mindMapPath.sheet.hiddenText')
          : stepDesc(capacity, step.key)
      }}</p>

      <template v-if="step.kind === 'exercise'">
        <div class="step-sheet__passes" data-testid="step-passes">
          <span
            v-for="i in step.required"
            :key="i"
            class="step-sheet__pass"
            :class="{
              'step-sheet__pass--done':
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
        <p class="step-sheet__note">{{ $t('mindMapPath.sheet.rule') }}</p>
        <p v-if="step.status === 'skipped'" class="step-sheet__note">{{
          $t('mindMapPath.sheet.skippedNote')
        }}</p>
        <p v-if="step.status === 'soon'" class="step-sheet__note">{{
          $t('mindMapPath.sheet.soonText')
        }}</p>
        <p v-if="step.status === 'locked'" class="step-sheet__note">{{
          $t('mindMapPath.sheet.lockedText')
        }}</p>
        <div class="step-sheet__reward">
          <span class="step-sheet__chip">{{
            $t('mindMapPath.sheet.xp', { xp: step.xp })
          }}</span>
        </div>
      </template>

      <template v-else-if="step.kind === 'chest'">
        <div class="step-sheet__reward">
          <CrystalGem :size="28" />
          <b>{{ $t('mindMapPath.sheet.crystals', { n: step.crystals }) }}</b>
        </div>
        <p class="step-sheet__note">{{
          step.status === 'done'
            ? $t('mindMapPath.sheet.chestEarned', { n: step.crystals })
            : $t('mindMapPath.sheet.chestLocked')
        }}</p>
      </template>

      <div v-if="error" class="step-sheet__error" role="alert">{{ error }}</div>

      <div v-if="step.kind === 'exercise'" class="step-sheet__actions">
        <q-btn
          v-if="playable"
          unelevated
          no-caps
          class="step-sheet__start"
          :label="startLabel"
          :loading="busy === 'start'"
          :disable="!!busy"
          data-testid="step-start"
          @click="emit('start', step)"
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
      </div>
    </div>
  </q-dialog>
</template>

<script setup>
/**
 * A step of the path opened (docs/MIND_MAP.md): an exercise (its passes, its reward, « Commencer »
 * or « Passer »), a chest (earned or how to earn it), a hidden boss (a riddle). Bosses have their
 * own screen (`BossIntro`).
 */
import { computed } from 'vue'
import { useQuasar } from 'quasar'
import CrystalGem from '@/components/shop/CrystalGem.vue'
import { stepDesc, stepTitle } from '@/utils/mindMapPath'
import { t } from '@/i18n'

const props = defineProps({
  /** @type {import('vue').PropType<import('@/utils/mindMapPath').PathStep|null>} */
  step: { type: Object, default: null },
  /** The API's capacity (its texts). */
  capacity: { type: String, required: true },
  /** @type {import('vue').PropType<''|'start'|'skip'>} */
  busy: { type: String, default: '' },
  error: { type: String, default: '' }
})

const emit = defineEmits({ start: null, skip: null, close: null })

const $q = useQuasar()

const playable = computed(() =>
  ['open', 'done', 'skipped'].includes(props.step?.status ?? '')
)

const startLabel = computed(() => {
  const step = props.step
  if (step?.status === 'done') return t('mindMapPath.sheet.practice')
  return step && step.passes > 0
    ? t('mindMapPath.sheet.again')
    : t('mindMapPath.sheet.start')
})

function confirmSkip() {
  const step = props.step
  if (!step) return
  $q.dialog({
    title: t('mindMapPath.sheet.skipTitle'),
    message: t('mindMapPath.sheet.skipText'),
    cancel: true
  }).onOk(() => emit('skip', step))
}
</script>

<style scoped lang="scss">
.step-sheet {
  display: flex;
  flex-direction: column;
  gap: 10px;
  width: min(460px, 100vw);
  padding: 20px 22px 24px;
  border-radius: 24px 24px 0 0;
  background: var(--cm-surface);
  color: var(--cm-ink);

  @media (min-width: $breakpoint-sm-min) {
    border-radius: 24px;
  }
}

.step-sheet__head {
  display: flex;
  align-items: center;
}

.step-sheet__kicker {
  flex: 1;
  font-size: 12px;
  font-weight: 800;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--cm-muted);
}

.step-sheet__close {
  width: 34px;
  height: 34px;
  border: none;
  border-radius: 12px;
  background: var(--cm-subtle);
  color: var(--cm-ink);
  font-weight: 800;
  cursor: pointer;
}

.step-sheet__title {
  margin: 0;
  font-family: var(--cm-heading);
  font-size: 24px;
  font-weight: 800;
  line-height: 1.15;
}

.step-sheet__desc {
  margin: 0;
  font-size: 15px;
  line-height: 1.5;
  color: var(--cm-ink-soft);
}

.step-sheet__passes {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  font-weight: 800;
  color: var(--cm-muted);
}

.step-sheet__pass {
  width: 28px;
  height: 10px;
  border-radius: 999px;
  background: var(--cm-subtle);

  &--done {
    background: var(--cm-brand);
  }
}

.step-sheet__note {
  margin: 0;
  font-size: 13.5px;
  color: var(--cm-muted);
}

.step-sheet__reward {
  display: flex;
  align-items: center;
  gap: 8px;
}

.step-sheet__chip {
  padding: 4px 12px;
  border-radius: 999px;
  background: var(--cm-lime-soft);
  color: var(--cm-lime-ink);
  font-size: 13px;
  font-weight: 800;
}

.step-sheet__error {
  padding: 10px 12px;
  border-radius: 12px;
  background: var(--cm-danger-soft);
  color: var(--cm-danger);
  font-size: 13.5px;
  font-weight: 700;
}

.step-sheet__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 6px;
}

.step-sheet__start {
  background: var(--cm-brand);
  color: #fff;
}
</style>
