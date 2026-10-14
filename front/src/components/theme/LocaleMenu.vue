<template>
  <q-btn
    flat
    dense
    no-caps
    class="locale-menu"
    :label="locale.locale.toUpperCase()"
    :aria-label="t('common.locale.menu', { name: LOCALE_NAMES[locale.locale] })"
    data-testid="locale-menu"
  >
    <q-menu anchor="bottom right" self="top right">
      <q-list dense style="min-width: 150px">
        <q-item
          v-for="code in LOCALES"
          :key="code"
          v-close-popup
          clickable
          :active="locale.locale === code"
          :lang="code"
          :data-testid="`locale-${code}`"
          @click="locale.choose(code)"
        >
          <q-item-section>{{ LOCALE_NAMES[code] }}</q-item-section>
        </q-item>
      </q-list>
    </q-menu>
  </q-btn>
</template>

<script setup>
import { useI18n } from 'vue-i18n'
import { LOCALES, LOCALE_NAMES } from '@/i18n'
import { useLocaleStore } from '@/stores/locale'

/** Header button: the current language's code, a menu to pick another (docs/I18N.md). */
const locale = useLocaleStore()
const { t } = useI18n()
</script>

<style scoped lang="scss">
.locale-menu {
  font-weight: 800;
  letter-spacing: 0.04em;
}
</style>
