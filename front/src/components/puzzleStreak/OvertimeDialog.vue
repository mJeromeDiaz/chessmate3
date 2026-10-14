<template>
  <q-dialog :model-value="modelValue" persistent>
    <q-card class="overtime" data-testid="streak-overtime">
      <q-card-section class="q-gutter-y-sm">
        <div class="text-h6">{{ $t('puzzleStreak.overtime.title') }}</div>
        <i18n-t
          keypath="puzzleStreak.overtime.streak"
          tag="div"
          scope="global"
          class="text-body1"
        >
          <template #streak>
            <b>{{ streakText(streak) }}</b>
          </template>
          <template #n>{{ extensionMinutes }}</template>
        </i18n-t>
        <div class="text-caption text-grey" data-testid="streak-overtime-left">
          {{ $t('puzzleStreak.overtime.left', { n: seconds }) }}
        </div>
        <div class="text-caption text-grey">{{
          $t('puzzleStreak.overtime.rule')
        }}</div>
        <div v-if="error" class="text-negative">{{ error }}</div>
      </q-card-section>
      <q-card-actions align="right">
        <q-btn
          flat
          no-caps
          :label="$t('puzzleStreak.overtime.end')"
          :disable="busy"
          data-testid="streak-overtime-end"
          @click="emit('end')"
        />
        <q-btn
          unelevated
          no-caps
          color="primary"
          :label="$t('puzzleStreak.overtime.extend', { n: extensionMinutes })"
          :loading="busy"
          data-testid="streak-overtime-extend"
          @click="emit('extend')"
        />
      </q-card-actions>
    </q-card>
  </q-dialog>
</template>

<script setup>
/**
 * Overtime (docs/TRAINING.md, "Prolongation"): the run's time is up while a streak is alive. Go on
 * for a few more minutes, or end the streak there; the decision time runs down.
 */
import { computed } from 'vue'
import { streakText } from '@/utils/puzzleStreak'

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  /** Puzzles solved in the streak in progress. */
  streak: { type: Number, required: true },
  /** Ms left to decide. */
  decisionMs: { type: Number, required: true },
  extensionMinutes: { type: Number, default: 5 },
  busy: { type: Boolean, default: false },
  error: { type: String, default: '' }
})

const emit = defineEmits({ extend: null, end: null })

const seconds = computed(() => Math.ceil(props.decisionMs / 1000))
</script>

<style scoped>
.overtime {
  width: min(420px, 100%);
}
</style>
