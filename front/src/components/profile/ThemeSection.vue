<template>
  <div class="profile-block">
    <div class="profile-row__title">Apparence</div>
    <div class="profile-row__sub">
      Automatique suit le réglage de ton appareil. Ton choix est gardé sur ton
      compte et s’applique sur tes autres appareils.
    </div>
    <q-btn-toggle
      :model-value="theme.theme"
      :options="options"
      no-caps
      unelevated
      spread
      class="profile-segmented"
      toggle-color="primary"
      data-testid="profile-theme"
      @update:model-value="theme.choose"
    />
  </div>
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
