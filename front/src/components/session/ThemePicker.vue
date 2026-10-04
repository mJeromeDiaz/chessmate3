<template>
  <div class="theme-picker" data-testid="theme-picker">
    <div class="theme-picker__bar">
      <input
        v-model="search"
        type="search"
        class="theme-picker__search"
        placeholder="Chercher un thème…"
        aria-label="Chercher un thème"
      />
      <span class="theme-picker__count" :style="{ color: accentInk }"
        >{{ modelValue.length }} / {{ max }}</span
      >
    </div>
    <div v-if="!themes.length" class="theme-picker__empty"
      >Chargement des thèmes…</div
    >
    <div v-else-if="!groups.length" class="theme-picker__empty"
      >Aucun thème ne correspond.</div
    >
    <div
      v-for="group in groups"
      :key="group.category"
      class="theme-picker__group"
    >
      <div class="theme-picker__category">{{ group.label }}</div>
      <div class="theme-picker__options">
        <button
          v-for="theme in group.themes"
          :key="theme.key"
          type="button"
          class="theme-picker__option"
          :aria-pressed="modelValue.includes(theme.key)"
          :disabled="!selectable(theme)"
          :title="theme.descriptionFr"
          :data-testid="`theme-${theme.key}`"
          :style="
            modelValue.includes(theme.key)
              ? { background: soft, borderColor: deep }
              : {}
          "
          @click="toggle(theme.key)"
          >{{ theme.labelFr }}</button
        >
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'

/**
 * Picks puzzle themes (combined with OR) for the Puzzles module: grouped by category, searchable,
 * at most `max`. Themes without puzzles cannot be picked.
 */
const props = defineProps({
  /** Selected theme keys. */
  modelValue: { type: Array, required: true },
  /** @type {import('vue').PropType<import('@/stores/puzzle').Theme[]>} */
  themes: { type: Array, required: true },
  max: { type: Number, required: true },
  soft: { type: String, required: true },
  deep: { type: String, required: true },
  accentInk: { type: String, required: true }
})

const emit = defineEmits(['update:modelValue'])

const search = ref('')

/**
 * Case- and accent-insensitive form, for the search.
 *
 * @param {string} text
 * @returns {string}
 */
function fold(text) {
  return text
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
}

/** Matching themes grouped by category, in the API's order. */
const groups = computed(() => {
  const needle = fold(search.value.trim())
  /** @type {Map<string, {category: string, label: string, themes: import('@/stores/puzzle').Theme[]}>} */
  const byCategory = new Map()
  for (const theme of props.themes) {
    if (needle && !fold(theme.labelFr).includes(needle)) continue
    if (!byCategory.has(theme.category)) {
      byCategory.set(theme.category, {
        category: theme.category,
        label: theme.categoryLabelFr,
        themes: []
      })
    }
    byCategory.get(theme.category)?.themes.push(theme)
  }
  return [...byCategory.values()]
})

/** @param {import('@/stores/puzzle').Theme} theme */
function selectable(theme) {
  if (props.modelValue.includes(theme.key)) return true
  return theme.puzzleCount > 0 && props.modelValue.length < props.max
}

/** @param {string} key */
function toggle(key) {
  emit(
    'update:modelValue',
    props.modelValue.includes(key)
      ? props.modelValue.filter(k => k !== key)
      : [...props.modelValue, key]
  )
}
</script>

<style scoped lang="scss">
.theme-picker__bar {
  position: sticky;
  top: 0;
  z-index: 1;
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 8px 0 12px;
  background: var(--cm-surface);
}

.theme-picker__search {
  flex: 1;
  min-width: 0;
  height: 40px;
  padding: 0 12px;
  border: 1.5px solid var(--cm-line);
  border-radius: 12px;
  background: var(--cm-page);
  color: var(--cm-ink);
  font: inherit;
  font-size: 14px;
  outline: none;

  &:focus {
    border-color: var(--cm-brand);
  }
}

.theme-picker__count {
  flex: none;
  font-size: 14px;
  font-weight: 700;
}

.theme-picker__empty {
  padding: 16px 0;
  color: var(--cm-muted);
  font-size: 13px;
}

.theme-picker__group {
  padding: 8px 0 14px;
}

.theme-picker__category {
  margin-bottom: 8px;
  color: var(--cm-muted);
  font-size: 12px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.theme-picker__options {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.theme-picker__option {
  min-height: 34px;
  padding: 0 12px;
  border: 1.5px solid var(--cm-line);
  border-radius: 11px;
  background: var(--cm-surface);
  color: var(--cm-ink);
  font: inherit;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;

  &[aria-pressed='true'] {
    color: #1b1530;
  }

  &:disabled {
    opacity: 0.4;
    cursor: not-allowed;
  }
}
</style>
