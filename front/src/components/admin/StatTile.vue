<template>
  <div class="cm-card stat-tile" :data-testid="testid">
    <span class="stat-tile__label">{{ label }}</span>
    <strong class="stat-tile__value">{{ value }}</strong>
    <span v-if="hint" class="stat-tile__hint">{{ hint }}</span>
  </div>
</template>

<script setup>
/** One key figure of the admin dashboard: a label, the value, an optional line of context. */
defineProps({
  label: { type: String, required: true },
  value: { type: String, required: true },
  hint: { type: String, default: '' },
  testid: { type: String, required: true }
})
</script>

<style scoped lang="scss">
.stat-tile {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.stat-tile__label {
  font-size: 13px;
  color: var(--cm-ink-soft);
}

.stat-tile__value {
  font-size: 28px;
  font-weight: 700;
  line-height: 1.1;
  color: var(--cm-ink);
}

.stat-tile__hint {
  font-size: 12px;
  color: var(--cm-muted);
}
</style>
