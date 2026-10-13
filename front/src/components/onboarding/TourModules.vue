<template>
  <div class="tour-modules">
    <div
      v-for="(module, i) in modules"
      :key="module.id"
      class="tour-module tour-in"
      :style="{
        animationDelay: `${0.15 + i * 0.1}s`,
        '--accent': accentOn(module, dark),
        '--soft': module.soft
      }"
      :data-testid="`tour-module-${module.id}`"
    >
      <div class="tour-module__head">
        <div
          class="tour-module__glyph"
          :style="{ background: module.bg, color: module.ink }"
          aria-hidden="true"
        >
          {{ module.glyph }}
        </div>
        <div class="tour-module__text">
          <div class="tour-module__title">
            <span>{{ module.title }}</span>
            <span v-if="module.badge" class="tour-module__badge"
              >★ {{ module.badge }}</span
            >
            <span v-if="module.soon" class="tour-module__soon">Bientôt</span>
          </div>
          <div class="tour-module__desc">{{ module.desc }}</div>
        </div>
      </div>
      <button
        type="button"
        class="tour-module__toggle"
        :aria-expanded="!!open[module.id]"
        :data-testid="`tour-why-${module.id}`"
        @click="emit('toggle', module.id)"
      >
        Pourquoi ça aide
        <span
          class="tour-module__chev"
          :class="{ 'tour-module__chev--open': open[module.id] }"
          aria-hidden="true"
          >▾</span
        >
      </button>
      <div v-if="open[module.id]" class="tour-module__why">
        {{ module.why }}
      </div>
    </div>
  </div>
</template>

<script setup>
/**
 * A professor's modules (design "Visite guidée"): what each one is, and why it helps (folded).
 */
import { computed } from 'vue'
import { useQuasar } from 'quasar'
import { MODULES, accentOn } from '@/utils/onboarding'

const props = defineProps({
  /** @type {import('vue').PropType<string[]>} */
  ids: { type: Array, required: true },
  /** @type {import('vue').PropType<Record<string, boolean>>} */
  open: { type: Object, required: true }
})

const emit = defineEmits({
  /** @param {string} id */
  toggle: id => typeof id === 'string'
})

const $q = useQuasar()
const dark = computed(() => $q.dark.isActive)
const modules = computed(() => props.ids.map(id => MODULES[id]))
</script>

<style scoped lang="scss">
.tour-modules {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.tour-module {
  flex: none;
  padding: 12px 14px;
  border-radius: 18px;
  background: var(--cm-page);
  border: 1px solid var(--cm-line);
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.tour-module__head {
  display: flex;
  gap: 12px;
  align-items: flex-start;
}

.tour-module__glyph {
  width: 46px;
  height: 46px;
  flex: none;
  border-radius: 14px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 22px;
}

.tour-module__text {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.tour-module__title {
  display: flex;
  align-items: center;
  gap: 7px;
  flex-wrap: wrap;
  font-weight: 800;
  font-size: 15.5px;
  color: var(--cm-ink);
}

.tour-module__badge,
.tour-module__soon {
  height: 20px;
  padding: 0 8px;
  border-radius: 999px;
  font-size: 10.5px;
  font-weight: 800;
  letter-spacing: 0.03em;
  display: flex;
  align-items: center;
}

.tour-module__badge {
  background: #1b1530;
  color: #c6f432;
}

.tour-module__soon {
  background: var(--cm-subtle);
  color: var(--cm-muted);
}

.tour-module__desc {
  font-size: 13px;
  line-height: 1.4;
  color: var(--cm-ink-soft);
  text-wrap: pretty;
}

.tour-module__toggle {
  align-self: flex-start;
  border: none;
  background: none;
  padding: 2px 0;
  color: var(--accent);
  font-weight: 800;
  font-size: 12.5px;
  cursor: pointer;
  display: flex;
  align-items: center;
  gap: 5px;
}

.tour-module__chev {
  display: inline-block;
  transition: transform 0.3s cubic-bezier(0.34, 1.8, 0.64, 1);

  &--open {
    transform: rotate(180deg);
  }
}

.tour-module__why {
  padding: 10px 12px;
  border-radius: 12px;
  background: var(--soft);
  color: #1b1530;
  font-size: 13px;
  line-height: 1.45;
  font-weight: 500;
  text-wrap: pretty;
  animation: tour-in 0.35s both;
}

.body--dark .tour-module__why {
  background: var(--cm-subtle);
  color: var(--cm-ink);
  border-left: 3px solid var(--accent);
}
</style>
