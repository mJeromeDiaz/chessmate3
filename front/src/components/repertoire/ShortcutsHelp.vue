<template>
  <q-btn flat round dense icon="keyboard" aria-label="Raccourcis clavier">
    <q-menu anchor="bottom right" self="top right">
      <q-list dense style="min-width: 260px">
        <q-item-label header>Raccourcis clavier</q-item-label>
        <q-item v-for="s in SHORTCUTS" :key="s.keys">
          <q-item-section>{{ s.label }}</q-item-section>
          <q-item-section side
            ><kbd class="shortcut-key">{{ s.keys }}</kbd></q-item-section
          >
        </q-item>
      </q-list>
    </q-menu>
  </q-btn>
</template>

<script setup>
/** Keyboard shortcuts of the repertoire editor (handled by MoveTree and the editor page). */
const SHORTCUTS = [
  { keys: '←', label: 'Coup précédent' },
  { keys: '→', label: 'Coup suivant' },
  { keys: '↑ ↓', label: 'Variante précédente / suivante' },
  { keys: 'Début', label: 'Position initiale' },
  { keys: 'Fin', label: 'Fin de la ligne' },
  { keys: 'F', label: 'Retourner l’échiquier' },
  { keys: 'Ctrl+Z', label: 'Annuler la dernière modification' }
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
