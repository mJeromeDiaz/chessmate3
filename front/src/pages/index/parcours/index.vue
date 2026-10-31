<template>
  <q-page padding class="row justify-center">
    <q-spinner size="2em" />
  </q-page>
</template>

<script setup>
/**
 * The former parcours page (docs/PARCOURS.md): the défis replaced them (docs/DEFIS.md), an old
 * link lands on their page.
 */
import { useRouter } from 'vue-router'

// Open to a guest (docs/GUEST.md): it only redirects to the défis.
definePage({ meta: { auth: 'required', guestOpen: true } })

useRouter().replace('/defis')
</script>
