<template>
  <section class="cm-card" data-testid="profile-connections">
    <h2 class="cm-card__title q-mb-xs">Connexions</h2>

    <div
      v-for="row in rows"
      :key="row.provider"
      class="profile-row"
      :data-testid="`connection-${row.provider}`"
    >
      <div
        class="connection__tile"
        :class="`connection__tile--${row.provider}`"
        aria-hidden="true"
        >{{ TILES[row.provider] }}</div
      >
      <div class="profile-row__text">
        <div class="connection__name">
          {{ providerLabel(row.provider) }}
          <span
            class="connection__dot"
            :class="{ 'connection__dot--on': row.identity }"
          />
        </div>
        <template v-if="row.identity">
          <div class="profile-row__sub connection__detail">{{
            identityDetail(row.identity)
          }}</div>
          <div class="profile-row__sub"
            >Lié le {{ formatDay(row.identity.linkedAt) }}</div
          >
        </template>
        <div v-else class="profile-row__sub">Non lié</div>
      </div>
      <q-btn
        v-if="row.identity"
        unelevated
        no-caps
        class="profile-btn"
        label="Déconnecter"
        :disable="!row.identity.removable"
        :loading="busy === row.identity.id"
        :data-testid="`connection-${row.provider}-unlink`"
        @click="unlink(row.identity)"
      />
      <q-btn
        v-else-if="row.linkable"
        unelevated
        no-caps
        class="profile-btn profile-btn--strong"
        label="Lier"
        :loading="busy === row.provider"
        :data-testid="`connection-${row.provider}-link`"
        @click="link(row.provider)"
      />
    </div>

    <div v-if="locked" class="profile-note" data-testid="connection-lock-note">
      Garde au moins une méthode de connexion active.
    </div>

    <q-banner v-if="error" class="profile-error q-mt-sm" rounded>{{
      error
    }}</q-banner>
  </section>
</template>

<script setup>
/**
 * Profile: the Google and Lichess accounts (design "Profil", card "Connexions"). Removing the last
 * sign-in method is refused by the API (`last_auth_method`); the button is then disabled.
 */
import { computed, ref } from 'vue'
import { useQuasar } from 'quasar'
import { useAuthStore } from '@/stores/auth'
import { providerLabel } from '@/utils/format'
import { apiErrorMessage } from '@/utils/apiError'
import { connectionRows, identityDetail } from '@/utils/profile'

const props = defineProps({
  /** The /api/profile payload. */
  profile: { type: Object, required: true }
})

/** The tile letter of each provider (♞ in text presentation). */
const TILES = { google: 'G', lichess: '♞︎' }

const $q = useQuasar()
const auth = useAuthStore()
const busy = ref(null)
const error = ref('')

const rows = computed(() => connectionRows(props.profile))
const locked = computed(() => props.profile.identities.some(i => !i.removable))

/** @param {string} iso */
function formatDay(iso) {
  return new Intl.DateTimeFormat('fr-FR', { dateStyle: 'long' }).format(
    new Date(iso)
  )
}

/** Leaves the SPA for the provider; it comes back through /oauth/callback. */
async function link(provider) {
  busy.value = provider
  error.value = ''

  try {
    window.location.assign(await auth.startLink(provider))
  } catch (e) {
    error.value = apiErrorMessage(e)
    busy.value = null
  }
}

function unlink(identity) {
  $q.dialog({
    title: `Déconnecter ${providerLabel(identity.provider)} ?`,
    message:
      'Tu ne pourras plus te connecter avec ce compte. Tes autres sessions seront fermées. Tes données ChessMate restent sauvegardées.',
    cancel: true
  }).onOk(async () => {
    busy.value = identity.id
    error.value = ''

    try {
      await auth.unlinkIdentity(identity.id)
    } catch (e) {
      error.value = apiErrorMessage(e, {
        409: "C'est ton seul moyen de connexion : il ne peut pas être retiré."
      })
    } finally {
      busy.value = null
    }
  })
}
</script>

<style scoped lang="scss">
.connection__tile {
  flex: none;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 42px;
  height: 42px;
  border-radius: 13px;
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 21px;

  &--google {
    background: var(--cm-orange-soft);
    color: var(--cm-orange-ink);
  }

  &--lichess {
    background: var(--cm-ink);
    color: var(--cm-surface);
  }

  @media (min-width: $breakpoint-md-min) {
    width: 46px;
    height: 46px;
    border-radius: 14px;
    font-size: 23px;
  }
}

.connection__name {
  display: flex;
  align-items: center;
  gap: 6px;
  font-weight: 700;
  font-size: 14.5px;

  @media (min-width: $breakpoint-md-min) {
    font-size: 15px;
  }
}

.connection__dot {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: var(--cm-dash);

  &--on {
    background: #8dba0a;
  }
}

.connection__detail {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
</style>
