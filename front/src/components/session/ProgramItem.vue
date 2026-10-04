<template>
  <div
    class="program-item"
    :style="{ borderColor: selected ? module.deep : 'transparent' }"
    role="button"
    tabindex="0"
    :aria-label="`Régler ${module.title}`"
    data-testid="program-item"
    @click="emit('edit')"
    @keydown.enter.prevent="emit('edit')"
    @keydown.space.prevent="emit('edit')"
  >
    <span
      class="program-item__handle drag-handle"
      title="Glisser pour déplacer"
      aria-hidden="true"
      @click.stop
      >⠿</span
    >
    <ProfAvatar
      class="program-item__avatar"
      :image="module.image"
      :bg="module.bg"
      :deep="module.deep"
      :ink="module.ink"
      :glyph="module.glyph"
      radius="15px"
    />
    <div class="program-item__body">
      <div class="row no-wrap items-start justify-between q-gutter-x-sm">
        <div>
          <div class="program-item__kicker"
            >{{ number }} · PROF {{ module.prof.toUpperCase() }}</div
          >
          <div class="program-item__title cm-heading">{{ module.title }}</div>
          <div class="program-item__desc gt-sm">{{ module.desc }}</div>
        </div>
        <span
          class="program-item__duration"
          data-testid="program-item-duration"
          >{{ duration }}</span
        >
      </div>
      <div v-if="chips.length" class="program-item__chips">
        <span
          v-for="chip in chips"
          :key="chip"
          class="program-item__chip"
          :style="{ background: module.soft }"
          >{{ chip }}</span
        >
      </div>
      <div v-if="item.values.notes" class="program-item__notes"
        >« {{ item.values.notes }} »</div
      >
      <div
        v-if="issue"
        class="program-item__issue"
        data-testid="program-item-issue"
        >⚠ {{ issue }}</div
      >
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import ProfAvatar from '@/components/session/ProfAvatar.vue'
import { useSessionStore } from '@/stores/session'
import {
  MODULES_BY_ID,
  formatMinutes,
  itemIssue,
  moduleMinutes,
  settingChips
} from '@/utils/session/catalog'

/** One step of the session program: its module, length and settings; click to edit. */
const props = defineProps({
  /** @type {import('vue').PropType<import('@/utils/session/catalog').SessionItem>} */
  item: { type: Object, required: true },
  index: { type: Number, required: true },
  /** Being edited in the settings panel. */
  selected: { type: Boolean, default: false }
})

const emit = defineEmits(['edit'])

const session = useSessionStore()

const module = computed(() => MODULES_BY_ID[props.item.moduleId])
const number = computed(() => String(props.index + 1).padStart(2, '0'))
const duration = computed(() =>
  formatMinutes(moduleMinutes(module.value, props.item.values))
)
const chips = computed(() =>
  settingChips(module.value, props.item.values, session.context)
)
const issue = computed(() =>
  itemIssue(module.value, props.item.values, session.context)
)
</script>

<style scoped lang="scss">
.program-item {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  padding: 12px;
  background: var(--cm-surface);
  border: 2px solid transparent;
  border-radius: 20px;
  cursor: pointer;

  &:focus-visible {
    outline: 3px solid var(--cm-brand);
    outline-offset: 2px;
  }

  @media (min-width: $breakpoint-md-min) {
    gap: 16px;
    padding: 16px;
    border-radius: 22px;
  }
}

.program-item__handle {
  order: 3;
  padding: 2px 4px;
  color: var(--cm-faint);
  font-size: 18px;
  cursor: grab;
  touch-action: none;
  user-select: none;

  @media (min-width: $breakpoint-md-min) {
    order: 0;
    padding-top: 20px;
    font-size: 20px;
  }
}

.program-item__avatar {
  flex: none;
  width: 52px;
  height: 52px;

  @media (min-width: $breakpoint-md-min) {
    width: 64px;
    height: 64px;
  }
}

.program-item__body {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 7px;
}

.program-item__kicker {
  color: var(--cm-muted);
  font-size: 11px;
  font-weight: 700;
}

.program-item__title {
  font-size: 17px;
  line-height: 1.2;

  @media (min-width: $breakpoint-md-min) {
    font-size: 19px;
  }
}

.program-item__desc {
  margin-top: 2px;
  color: var(--cm-ink-soft);
  font-size: 13.5px;
}

.program-item__duration {
  flex: none;
  padding: 4px 9px;
  border-radius: 999px;
  background: var(--cm-subtle);
  font-size: 13px;
  font-weight: 700;
  white-space: nowrap;
}

.program-item__chips {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.program-item__chip {
  padding: 4px 9px;
  border-radius: 999px;
  color: #1b1530;
  font-size: 12px;
  font-weight: 500;
}

.program-item__issue {
  margin-top: 8px;
  color: var(--cm-danger);
  font-size: 12.5px;
  font-weight: 700;
}

.program-item__notes {
  color: var(--cm-muted);
  font-size: 12.5px;
  font-style: italic;
}
</style>
