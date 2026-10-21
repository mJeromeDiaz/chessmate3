<template>
  <SessionBuilder />
</template>

<script setup>
/** A new training session: the builder on the browser's draft. */
import SessionBuilder from '@/components/session/SessionBuilder.vue'

definePage({
  meta: {
    auth: 'required',
    header: {
      title: 'layout.header.titles.sessionNew',
      subtitle: 'layout.header.kickers.create'
    }
  }
})
</script>
