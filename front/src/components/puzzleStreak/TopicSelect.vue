<template>
  <q-select
    :model-value="modelValue"
    :options="shown"
    emit-value
    map-options
    outlined
    dense
    use-input
    input-debounce="0"
    :display-value="topicLabel(modelValue, openings)"
    @filter="onFilter"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <template #option="{ itemProps, opt, index }">
      <q-item-label
        v-if="index === 0 || shown[index - 1].group !== opt.group"
        header
        class="q-py-xs"
        >{{ opt.group }}</q-item-label
      >
      <q-item v-bind="itemProps">
        <q-item-section>{{ opt.label }}</q-item-section>
      </q-item>
    </template>
    <template #no-option>
      <q-item>
        <q-item-section class="text-grey">{{
          $t('puzzleStreak.noTopic')
        }}</q-item-section>
      </q-item>
    </template>
  </q-select>
</template>

<script setup>
/**
 * Picks a puzzle streak's topic (docs/PUZZLE_STREAK.md): general, a theme (endgames, mates,
 * motifs), one of the user's openings or any opening family, by group, with a search.
 */
import { computed, ref } from 'vue'
import { filterTopics, topicLabel, topicOptions } from '@/utils/puzzleStreak'

const props = defineProps({
  /** The topic's key. */
  modelValue: { type: String, required: true },
  /** @type {import('vue').PropType<import('@/utils/puzzleStreak').StreakRules>} */
  rules: { type: Object, required: true },
  /** @type {import('vue').PropType<import('@/utils/puzzleStreak').StreakOpenings|null>} */
  openings: { type: Object, default: null }
})

const emit = defineEmits({
  'update:modelValue': value => typeof value === 'string'
})

const needle = ref('')
const options = computed(() => topicOptions(props.rules, props.openings))
const shown = computed(() => filterTopics(options.value, needle.value))

/**
 * @param {string} value
 * @param {(fn: () => void) => void} update
 */
function onFilter(value, update) {
  update(() => (needle.value = value))
}
</script>
