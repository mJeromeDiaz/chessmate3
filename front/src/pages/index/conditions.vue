<template>
  <PlaceholderPage :title="$t('landing.footer.terms')" />
</template>

<script setup>
import PlaceholderPage from '@/components/landing/PlaceholderPage.vue'

definePage({
  meta: {
    auth: 'public',
    header: {
      title: 'layout.header.titles.terms',
      subtitle: 'layout.header.kickers.about'
    }
  }
})
</script>
