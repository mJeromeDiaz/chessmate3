<template>
  <q-btn
    flat
    round
    dense
    :icon="current.icon"
    :aria-label="`Thème : ${current.label}`"
    data-testid="theme-menu"
  >
    <q-tooltip>Thème : {{ current.label }}</q-tooltip>
    <q-menu anchor="bottom right" self="top right">
      <q-list dense style="min-width: 170px">
        <q-item
          v-for="option in THEME_OPTIONS"
          :key="option.value"
          v-close-popup
          clickable
          :active="theme.theme === option.value"
          :data-testid="`theme-${option.value}`"
          @click="theme.choose(option.value)"
        >
          <q-item-section avatar>
            <q-icon :name="option.icon" />
          </q-item-section>
          <q-item-section>{{ option.label }}</q-item-section>
        </q-item>
      </q-list>
    </q-menu>
  </q-btn>
</template>

<script setup>
import { computed } from 'vue'
import { useThemeStore } from '@/stores/theme'
import { THEME_OPTIONS } from '@/utils/theme'

/** Header button: the current theme's icon, a menu to pick automatic, light or dark. */
const theme = useThemeStore()

const current = computed(
  () => THEME_OPTIONS.find(o => o.value === theme.theme) ?? THEME_OPTIONS[0]
)
</script>
