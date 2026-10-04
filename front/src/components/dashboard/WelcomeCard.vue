<template>
  <section class="welcome" data-testid="dashboard-welcome">
    <div class="welcome__text">
      <div class="welcome__kicker">Bienvenue sur ChessMate</div>
      <h1 class="welcome__title"
        >Ton tableau de bord se remplira dès ton premier exercice.</h1
      >
      <p>
        Compose une session sur mesure ou commence par quelques puzzles : ta
        régularité, ton classement et ta progression s’afficheront ici.
      </p>
      <div class="welcome__actions">
        <q-btn
          unelevated
          no-caps
          color="dark"
          to="/session/new"
          label="Composer ma première session"
          data-testid="welcome-session"
        />
        <q-btn
          outline
          no-caps
          color="primary"
          to="/puzzle"
          label="Résoudre des puzzles"
        />
      </div>
    </div>
    <img v-if="mascot" :src="mascot" alt="" class="welcome__mascot" />
  </section>
</template>

<script setup>
import { profImage } from '@/utils/prof/images'

const mascot = profImage('aaron-laugh')
</script>

<style scoped lang="scss">
.welcome {
  position: relative;
  overflow: hidden;
  display: flex;
  align-items: flex-end;
  gap: 16px;
  padding: 22px 20px;
  border-radius: 26px;
  background: var(--cm-brand-soft);
}

.welcome__text {
  flex: 1;
  min-width: 0;

  p {
    margin: 8px 0 16px;
    color: var(--cm-ink-soft);
  }
}

.welcome__kicker {
  font-size: 12px;
  font-weight: 700;
  color: var(--cm-brand-deep);
  text-transform: uppercase;
}

.welcome__title {
  margin: 6px 0 0;
  font-size: 24px;
  line-height: 1.15;

  @media (min-width: $breakpoint-md-min) {
    font-size: 30px;
  }
}

.welcome__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.welcome__mascot {
  display: none;
  width: 150px;
  margin-bottom: -22px;

  @media (min-width: $breakpoint-sm-min) {
    display: block;
  }
}
</style>
