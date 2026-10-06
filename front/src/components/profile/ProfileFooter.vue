<template>
  <footer class="profile-footer" data-testid="profile-footer">
    <nav class="profile-footer__links">
      <router-link to="/conditions">Conditions d’utilisation</router-link>
      <router-link to="/confidentialite">Confidentialité</router-link>
      <router-link to="/contact">Contact</router-link>
    </nav>
    <div>Don't Stay Rooky v{{ version }}</div>
  </footer>
</template>

<script setup>
/** Profile: legal links and the app version (front package.json, baked in at build time). */
const version = import.meta.env.APP_VERSION
</script>

<style scoped lang="scss">
.profile-footer {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
  padding: 6px 6px 4px;
  font-size: 12px;
  color: var(--cm-muted);

  @media (min-width: $breakpoint-md-min) {
    flex-direction: row;
    justify-content: space-between;
    font-size: 12.5px;
  }
}

.profile-footer__links {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: 14px;

  a {
    color: var(--cm-muted);
  }
}
</style>
