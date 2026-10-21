<template>
  <PlaceholderPage :title="$t('landing.footer.contact')" />
</template>

<script setup>
import PlaceholderPage from '@/components/landing/PlaceholderPage.vue'

definePage({
  meta: {
    auth: 'public',
    header: {
      title: 'layout.header.titles.contact',
      subtitle: 'layout.header.kickers.about'
    }
  }
})
</script>
