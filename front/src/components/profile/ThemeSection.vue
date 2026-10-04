<template>
  <q-card flat bordered>
    <q-card-section>
      <div class="text-subtitle1">Apparence</div>
      <p class="text-caption text-grey q-mb-sm">
        Automatique suit le réglage de ton appareil. Ton choix est gardé sur ton
        compte et s’applique sur tes autres appareils.
      </p>
      <q-btn-toggle
        :model-value="theme.theme"
        :options="options"
        no-caps
        unelevated
        toggle-color="primary"
        data-testid="profile-theme"
        @update:model-value="theme.choose"
      />
    </q-card-section>
  </q-card>
</template>

<script setup>
import { useThemeStore } from '@/stores/theme'
import { THEME_OPTIONS } from '@/utils/theme'

/** Profile: the colour theme (same choice as the header menu). */
const theme = useThemeStore()

const options = THEME_OPTIONS.map(o => ({
  value: o.value,
  label: o.label,
  icon: o.icon
}))
</script>
