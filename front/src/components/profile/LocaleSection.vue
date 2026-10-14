<template>
  <div class="profile-block">
    <div class="profile-row__title">{{ t('common.locale.title') }}</div>
    <div class="profile-row__sub">{{ t('common.locale.hint') }}</div>
    <q-btn-toggle
      :model-value="locale.locale"
      :options="options"
      no-caps
      unelevated
      spread
      class="profile-segmented"
      toggle-color="primary"
      data-testid="profile-locale"
      @update:model-value="locale.choose"
    />
  </div>
</template>

<script setup>
import { useI18n } from 'vue-i18n'
import { LOCALES, LOCALE_NAMES } from '@/i18n'
import { useLocaleStore } from '@/stores/locale'

/** Profile: the language (same choice as the landing header's menu, docs/I18N.md). */
const locale = useLocaleStore()
const { t } = useI18n()

const options = LOCALES.map(code => ({
  value: code,
  label: LOCALE_NAMES[code],
  attrs: { lang: code }
}))
</script>
