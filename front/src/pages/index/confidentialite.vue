<template>
  <PlaceholderPage :title="$t('landing.footer.privacy')" />
</template>

<script setup>
import PlaceholderPage from '@/components/landing/PlaceholderPage.vue'

definePage({
  meta: {
    auth: 'public',
    header: {
      title: 'layout.header.titles.privacy',
      subtitle: 'layout.header.kickers.about'
    }
  }
})
</script>
