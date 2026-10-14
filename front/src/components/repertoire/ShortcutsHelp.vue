<template>
  <q-btn
    flat
    round
    dense
    icon="keyboard"
    :aria-label="$t('repertoire.shortcuts.title')"
  >
    <q-menu anchor="bottom right" self="top right">
      <q-list dense style="min-width: 260px">
        <q-item-label header>{{
          $t('repertoire.shortcuts.title')
        }}</q-item-label>
        <q-item v-for="s in SHORTCUTS" :key="s.label">
          <q-item-section>{{
            $t(`repertoire.shortcuts.labels.${s.label}`)
          }}</q-item-section>
          <q-item-section side
            ><kbd class="shortcut-key">{{
              s.keys ? $t(`repertoire.shortcuts.${s.keys}`) : s.symbol
            }}</kbd></q-item-section
          >
        </q-item>
      </q-list>
    </q-menu>
  </q-btn>
</template>

<script setup>
/** Keyboard shortcuts of the repertoire editor (handled by MoveTree and the editor page). */
/** `label`: under repertoire.shortcuts.labels; a key named in words (`keys`): under repertoire.shortcuts. */
const SHORTCUTS = [
  { symbol: '←', label: 'previous' },
  { symbol: '→', label: 'next' },
  { symbol: '↑ ↓', label: 'variation' },
  { keys: 'home', label: 'start' },
  { keys: 'end', label: 'lineEnd' },
  { symbol: 'F', label: 'flip' },
  { symbol: 'Ctrl+Z', label: 'undo' }
]
</script>

<style scoped>
.shortcut-key {
  padding: 0 0.4em;
  border: 1px solid #bdbdbd;
  border-radius: 4px;
  font-family: inherit;
}
</style>
