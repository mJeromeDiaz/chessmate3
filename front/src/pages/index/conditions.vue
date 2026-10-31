<template>
  <LegalPage page="terms" />
</template>

<script setup>
import LegalPage from '@/components/landing/LegalPage.vue'

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
