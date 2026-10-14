<template>
  <div class="profile-block">
    <div class="profile-row__title">{{ $t('profile.appearance.title') }}</div>
    <div class="profile-row__sub">{{ $t('profile.appearance.hint') }}</div>
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
import { computed } from 'vue'
import { useThemeStore } from '@/stores/theme'
import { THEME_OPTIONS } from '@/utils/theme'

/** Profile: the colour theme (same choice as the header menu). */
const theme = useThemeStore()

/** Computed: the labels follow the language. */
const options = computed(() =>
  THEME_OPTIONS.map(o => ({ value: o.value, label: o.label, icon: o.icon }))
)
</script>
