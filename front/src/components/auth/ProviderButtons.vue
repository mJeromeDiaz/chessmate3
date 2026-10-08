<template>
  <div class="provider-buttons">
    <template v-for="(provider, i) in ordered" :key="provider.id">
      <!-- With a key: a form POST, so that the key stays out of URLs (docs/EARLY_ACCESS.md). -->
      <form
        v-if="invitationKey"
        method="post"
        :action="authApi.oauthLoginUrl(provider.id)"
        class="provider-buttons__item auth-rise"
        :style="{ '--d': `${delay + i * 130}ms` }"
        @submit="emit('leave', provider.id)"
      >
        <input type="hidden" name="invitationKey" :value="invitationKey" />
        <button
          type="submit"
          :class="buttonClass(provider.id)"
          :data-testid="`provider-${provider.id}`"
        >
          <span
            class="provider-buttons__glyph"
            :class="`provider-buttons__glyph--${provider.id}`"
            aria-hidden="true"
            >{{ provider.glyph }}</span
          >{{ verb }} {{ provider.label }}
          <span v-if="isLead(provider.id)" class="provider-buttons__badge"
            >Recommandé</span
          >
        </button>
      </form>
      <a
        v-else
        :href="authApi.oauthLoginUrl(provider.id)"
        :class="[buttonClass(provider.id), 'provider-buttons__item auth-rise']"
        :style="{ '--d': `${delay + i * 130}ms` }"
        :data-testid="`provider-${provider.id}`"
        @click="emit('leave', provider.id)"
      >
        <span
          class="provider-buttons__glyph"
          :class="`provider-buttons__glyph--${provider.id}`"
          aria-hidden="true"
          >{{ provider.glyph }}</span
        >{{ verb }} {{ provider.label }}
        <span v-if="isLead(provider.id)" class="provider-buttons__badge"
          >Recommandé</span
        >
      </a>
    </template>
  </div>
</template>

<script setup>
/**
 * "Continuer avec Lichess" (recommended, first) and "Continuer avec Google" (design "Connexion").
 * Without a key, links that sign an existing account in; with one, form POSTs that may also open
 * an account (docs/EARLY_ACCESS.md). `leave` fires just before the browser goes to the provider.
 */
import { computed } from 'vue'
import { authApi } from '@/services/api'
import { PROVIDERS } from '@/utils/auth/authFlow'

const props = defineProps({
  /** An invitation key checked by the early access screen; '' to sign in. */
  invitationKey: { type: String, default: '' },
  /** The provider shown first and highlighted (the one a refused sign-in came from). */
  lead: { type: String, default: 'lichess' },
  /** Entrance delay of the first button, in ms. */
  delay: { type: Number, default: 0 },
  /** "Continuer avec X" or "Créer mon compte avec X". */
  verb: { type: String, default: 'Continuer avec' }
})

const emit = defineEmits(['leave'])

const ordered = computed(() =>
  [...PROVIDERS].sort(
    (a, b) => Number(b.id === props.lead) - Number(a.id === props.lead)
  )
)

/** @param {string} id */
const isLead = id => id === props.lead

/** @param {string} id */
const buttonClass = id => [
  'auth-btn',
  'provider-buttons__btn',
  isLead(id) ? 'provider-buttons__btn--lead' : 'auth-btn--outline'
]
</script>

<style scoped lang="scss">
.provider-buttons {
  display: flex;
  flex-direction: column;
  gap: 18px;
}

.provider-buttons__btn {
  position: relative;

  &--lead {
    height: 58px;
    font-size: 16px;
    background: #1b1530;
    color: #fff;
  }
}

.provider-buttons__glyph {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 24px;
  line-height: 1;
}

.provider-buttons__glyph--google {
  width: 24px;
  height: 24px;
  border-radius: 50%;
  background: var(--cm-subtle);
  color: var(--cm-ink);
  font-weight: 800;
  font-size: 13px;
}

.provider-buttons__badge {
  position: absolute;
  top: -11px;
  right: 14px;
  padding: 3px 9px;
  border-radius: 999px;
  background: #c6f432;
  color: #1b1530;
  font-size: 11px;
  font-weight: 700;
  animation: provider-badge 0.4s cubic-bezier(0.34, 2, 0.64, 1) 0.5s both;
}

@keyframes provider-badge {
  from {
    transform: scale(0);
  }
}

@media (prefers-reduced-motion: reduce) {
  .provider-buttons__badge {
    animation: none;
  }
}
</style>
