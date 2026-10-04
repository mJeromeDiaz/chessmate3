<template>
  <section class="cm-card sessions" data-testid="recent-sessions">
    <h2 class="cm-card__title">Dernières sessions</h2>
    <div class="sessions__empty">
      <span>Tes sessions jouées apparaîtront ici.</span>
      <q-btn
        flat
        dense
        no-caps
        color="primary"
        to="/session/new"
        label="Préparer une session"
      />
    </div>
  </section>
</template>

<script setup>
/**
 * Played sessions (design "Dashboard"). Sessions are not played yet (the builder is a draft):
 * an empty state until the session backend exists.
 */
</script>

<style scoped lang="scss">
.sessions {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.sessions__empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
  padding: 16px;
  border-radius: 16px;
  background: var(--cm-page);
  color: var(--cm-ink-soft);
  text-align: center;
  font-size: 14px;
}
</style>
