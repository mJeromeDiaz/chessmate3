<template>
  <div
    class="capacity-panel"
    :style="{ '--capacity': capacity.color, '--capacity-ink': capacity.ink }"
    data-testid="capacity-panel"
    :data-capacity="capacity.key"
  >
    <header class="capacity-panel__head">
      <div class="capacity-panel__kicker">
        <span>{{ $t('mindMap.panel.kicker', { n: capacity.num }) }}</span>
        <button
          type="button"
          class="capacity-panel__close"
          :aria-label="$t('common.actions.close')"
          data-testid="capacity-panel-close"
          @click="emit('close')"
          >✕</button
        >
      </div>
      <h2 class="capacity-panel__name">{{ capacityName(capacity.key) }}</h2>
      <p class="capacity-panel__desc">{{ capacityDesc(capacity.key) }}</p>
    </header>

    <section class="capacity-panel__section">
      <div class="capacity-panel__row">
        <span class="capacity-panel__label">{{
          $t('mindMap.panel.modules')
        }}</span>
        <button
          type="button"
          class="capacity-panel__why-toggle"
          :aria-expanded="why"
          data-testid="capacity-why-toggle"
          @click="why = !why"
          >{{
            why ? $t('mindMap.panel.hideWhy') : $t('mindMap.panel.why')
          }}</button
        >
      </div>
      <div class="capacity-panel__chips">
        <router-link
          v-for="m in modules"
          :key="m.key"
          :to="m.to"
          class="capacity-panel__chip"
          :class="{ 'capacity-panel__chip--primary': m.primary }"
          :data-testid="`capacity-module-${m.key}`"
          >{{ moduleName(m.key)
          }}<span class="capacity-panel__role">{{
            m.primary
              ? $t('mindMap.panel.primary')
              : $t('mindMap.panel.associated')
          }}</span></router-link
        >
      </div>
      <div v-if="why" class="capacity-panel__why" data-testid="capacity-why">
        <div v-for="m in modules" :key="m.key" class="capacity-panel__why-row">
          <b>{{ moduleName(m.key) }}</b>
          <span>{{ moduleWhy(m.key) }}</span>
        </div>
      </div>
    </section>

    <section class="capacity-panel__section">
      <div class="capacity-panel__soon" data-testid="capacity-soon">
        <div class="capacity-panel__soon-title">{{
          $t('mindMap.panel.soonTitle')
        }}</div>
        <p>{{ $t('mindMap.panel.soonText') }}</p>
        <div class="capacity-panel__actions">
          <q-btn
            unelevated
            no-caps
            class="capacity-panel__train"
            :label="$t('mindMap.panel.train')"
            :to="modules[0]?.to ?? '/'"
            data-testid="capacity-train"
          />
          <q-btn
            outline
            no-caps
            :label="$t('mindMap.panel.compose')"
            to="/session/new"
            data-testid="capacity-compose"
          />
        </div>
      </div>
    </section>
  </div>
</template>

<script setup>
/**
 * A capacity opened beside the mind map (design "Carte mentale"): what it is, the modules that
 * train it (main or associated, each one a link to its page) and why. Its chapters come later:
 * meanwhile, ways to train it now.
 */
import { computed, ref, watch } from 'vue'
import {
  capacityDesc,
  capacityModules,
  capacityName,
  moduleName,
  moduleWhy
} from '@/utils/mindMap'

const props = defineProps({
  /** @type {import('vue').PropType<import('@/utils/mindMap').Capacity>} */
  capacity: { type: Object, required: true }
})

const emit = defineEmits({ close: null })

const why = ref(false)
const modules = computed(() => capacityModules(props.capacity))

watch(
  () => props.capacity.key,
  () => (why.value = false)
)
</script>

<style scoped lang="scss">
.capacity-panel {
  display: flex;
  flex-direction: column;
  min-height: 100%;
  background: var(--cm-page);
  color: var(--cm-ink);
}

.capacity-panel__head {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 22px 24px 24px;
  background: var(--capacity);
  color: var(--capacity-ink);
}

.capacity-panel__kicker {
  display: flex;
  align-items: center;
  gap: 10px;
  font-size: 12px;
  font-weight: 800;
  letter-spacing: 0.06em;

  span {
    flex: 1;
  }
}

.capacity-panel__close {
  width: 36px;
  height: 36px;
  border: none;
  border-radius: 12px;
  background: rgba(27, 21, 48, 0.15);
  color: inherit;
  font-size: 18px;
  font-weight: 800;
  cursor: pointer;
}

.capacity-panel__name {
  margin: 0;
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 28px;
  line-height: 1.1;
  text-wrap: balance;
}

.capacity-panel__desc {
  margin: 0;
  font-size: 14.5px;
  line-height: 1.5;
  text-wrap: pretty;
}

.capacity-panel__section {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 20px 24px 4px;
}

.capacity-panel__row {
  display: flex;
  align-items: center;
  gap: 10px;
}

.capacity-panel__label {
  flex: 1;
  font-size: 12px;
  font-weight: 800;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--cm-muted);
}

.capacity-panel__why-toggle {
  padding: 0;
  border: none;
  background: none;
  color: var(--cm-brand);
  font: inherit;
  font-size: 13px;
  font-weight: 800;
  cursor: pointer;
}

.capacity-panel__chips {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.capacity-panel__chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  height: 32px;
  padding: 0 12px;
  border-radius: 999px;
  box-shadow: inset 0 0 0 2px var(--cm-line);
  color: var(--cm-ink);
  font-size: 13px;
  font-weight: 800;
  text-decoration: none;

  &--primary {
    background: var(--capacity);
    color: var(--capacity-ink);
    box-shadow: inset 0 0 0 2px var(--capacity);
  }
}

.capacity-panel__role {
  font-size: 11px;
  font-weight: 700;
  opacity: 0.75;
}

.capacity-panel__why {
  display: flex;
  flex-direction: column;
  padding: 4px 16px;
  border-radius: 16px;
  background: var(--cm-surface);
}

.capacity-panel__why-row {
  display: flex;
  flex-direction: column;
  gap: 3px;
  padding: 12px 0;
  font-size: 13px;
  line-height: 1.45;

  & + & {
    border-top: 1px solid var(--cm-line);
  }

  span {
    color: var(--cm-muted);
  }
}

.capacity-panel__soon {
  padding: 16px;
  border-radius: 16px;
  background: var(--cm-surface);
  box-shadow: inset 0 0 0 2px var(--cm-line);

  p {
    margin: 6px 0 12px;
    color: var(--cm-muted);
    font-size: 13.5px;
    line-height: 1.45;
  }
}

.capacity-panel__soon-title {
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 18px;
}

.capacity-panel__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.capacity-panel__train {
  background: var(--capacity);
  color: var(--capacity-ink);
}
</style>
