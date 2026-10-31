<template>
  <LegalPage page="privacy" />
</template>

<script setup>
import LegalPage from '@/components/landing/LegalPage.vue'

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
