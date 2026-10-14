import { intlLocale, t } from '@/i18n'

/**
 * Display helpers of the profile page (design "Profil").
 */

/**
 * The name shown on the profile: the chosen display name, else the part of the email before "@",
 * else the first linked identity's username or name, else "Joueur".
 *
 * @param {{displayName?: string|null, email?: string|null, identities?: Array<{username?: string|null, name?: string|null}>}} profile
 * @returns {string}
 */
export function profileName(profile) {
  if (profile.displayName) return profile.displayName
  if (profile.email) return profile.email.split('@')[0]

  for (const identity of profile.identities ?? []) {
    const name = identity.username ?? identity.name
    if (name) return name
  }

  return t('profile.player')
}

/**
 * The avatars offered (design "Profil"): a piece in text presentation on its own colour, in the
 * design's order. Keys are the API's values.
 *
 * @type {Array<{value: string, glyph: string, color: string, label: string}>}
 */
export const AVATARS = [
  {
    value: 'knight',
    glyph: '♞\uFE0E',
    color: '#FF8A3D',
    get label() {
      return t('profile.avatars.knight')
    }
  },
  {
    value: 'bishop',
    glyph: '♝\uFE0E',
    color: '#C6F432',
    get label() {
      return t('profile.avatars.bishop')
    }
  },
  {
    value: 'queen',
    glyph: '♛\uFE0E',
    color: '#FF6FAE',
    get label() {
      return t('profile.avatars.queen')
    }
  },
  {
    value: 'rook',
    glyph: '♜\uFE0E',
    color: '#4FB2FF',
    get label() {
      return t('profile.avatars.rook')
    }
  },
  {
    value: 'king',
    glyph: '♚\uFE0E',
    color: '#FFD43B',
    get label() {
      return t('profile.avatars.king')
    }
  },
  {
    value: 'pawn',
    glyph: '♟\uFE0E',
    color: '#2ED3A8',
    get label() {
      return t('profile.avatars.pawn')
    }
  }
]

/**
 * What the avatar tile shows: the chosen piece on its colour, else the name's initial on lime.
 *
 * @param {{avatar?: string|null}} profile
 * @param {string} name the name shown (for the initial)
 * @returns {{glyph: string, color: string}}
 */
export function avatarTile(profile, name) {
  const avatar = AVATARS.find(a => a.value === profile.avatar)

  return avatar
    ? { glyph: avatar.glyph, color: avatar.color }
    : { glyph: name.charAt(0).toUpperCase(), color: '#C6F432' }
}

/**
 * A handle as typed, cleaned like the API does: lower case, only [a-z0-9_], 20 characters at most.
 *
 * @param {string} value
 * @returns {string}
 */
export function cleanHandle(value) {
  return value
    .toLowerCase()
    .replace(/[^a-z0-9_]/g, '')
    .slice(0, 20)
}

/** The answer of the availability check that blocks saving, by reason. */
const REFUSALS = {
  get invalid() {
    return t('profile.handle.invalid')
  },
  get reserved() {
    return t('profile.handle.reserved')
  },
  get taken() {
    return t('profile.handle.taken')
  }
}

/**
 * The hint under the handle field and whether the form may be saved with it.
 *
 * @param {string} handle the cleaned handle typed
 * @param {string|null} current the handle saved on the account
 * @param {null|'checking'|{handle: string, available: boolean, reason: string|null}} check the
 *   availability answer for `handle` ('checking' while asking)
 * @returns {{text: string, tone: 'muted'|'ok'|'error', savable: boolean}}
 */
export function handleStatus(handle, current, check) {
  if (handle === '') {
    return {
      text: t('profile.handle.optional'),
      tone: 'muted',
      savable: true
    }
  }
  if (handle.length < 3) {
    return { text: REFUSALS.invalid, tone: 'error', savable: false }
  }
  if (handle === current) {
    return { text: t('profile.handle.current'), tone: 'ok', savable: true }
  }
  if (check === null || check === 'checking' || check.handle !== handle) {
    return { text: t('profile.handle.checking'), tone: 'muted', savable: false }
  }
  if (!check.available) {
    return {
      text: REFUSALS[check.reason ?? 'invalid'] ?? REFUSALS.invalid,
      tone: 'error',
      savable: false
    }
  }

  return {
    text: t('profile.handle.available', { handle }),
    tone: 'ok',
    savable: true
  }
}

/**
 * A date as its month and year, "mars 2026".
 *
 * @param {string} iso
 * @returns {string}
 */
export function formatMonth(iso) {
  return new Intl.DateTimeFormat(intlLocale(), {
    month: 'long',
    year: 'numeric'
  }).format(new Date(iso))
}

/** The providers offered in the "Connexions" card, in the design's order. */
export const CONNECTION_PROVIDERS = ['google', 'lichess']

/**
 * One row per provider of the "Connexions" card: the linked identity, if any, and whether it can
 * be removed (not the account's last sign-in method).
 *
 * @param {{identities: Array<{provider: string, removable: boolean}>, linkableProviders: string[]}} profile
 * @returns {Array<{provider: string, identity: object|null, linkable: boolean}>}
 */
export function connectionRows(profile) {
  return CONNECTION_PROVIDERS.map(provider => ({
    provider,
    identity: profile.identities.find(i => i.provider === provider) ?? null,
    linkable: profile.linkableProviders.includes(provider)
  }))
}

/**
 * The detail line of a linked identity: its username, name or email, then its Lichess ratings
 * ("blitz 2250, rapid 1900?", "?" = provisional).
 *
 * @param {{provider: string, username?: string|null, name?: string|null, providerEmail?: string|null, ratings?: Record<string, {rating: number, provisional?: boolean}>|null}} identity
 * @returns {string}
 */
export function identityDetail(identity) {
  const who = identity.username
    ? identity.provider === 'lichess'
      ? `@${identity.username}`
      : identity.username
    : (identity.name ?? identity.providerEmail ?? '')
  const ratings = Object.entries(identity.ratings ?? {})
    .map(([perf, r]) => `${perf} ${r.rating}${r.provisional ? '?' : ''}`)
    .join(', ')

  return [who, ratings].filter(Boolean).join(' · ')
}

/** The icon of a session, by form factor. */
const SESSION_ICONS = { phone: '▯', tablet: '▭', desktop: '▢' }

/**
 * How a session reads in "Dernières connexions": device ("Chrome · macOS"), icon and a line with
 * the network and the last activity.
 *
 * @param {{browser: string|null, os: string|null, form: string|null, ip: string|null, lastActiveAt: string|null, current: boolean}} session
 * @param {Date} [now]
 * @returns {{device: string, icon: string, meta: string}}
 */
export function sessionLine(session, now = new Date()) {
  const device =
    [session.browser, session.os].filter(Boolean).join(' · ') ||
    t('profile.sessions.unknownDevice')
  const when = session.current
    ? t('profile.sessions.now')
    : session.lastActiveAt
      ? relativeDay(new Date(session.lastActiveAt), now)
      : null
  const meta = [
    session.ip ? t('profile.sessions.network', { ip: session.ip }) : null,
    when
  ]
    .filter(Boolean)
    .join(' · ')

  return { device, icon: SESSION_ICONS[session.form] ?? '▢', meta }
}

/**
 * "aujourd’hui, 09:42", "hier, 21:14" or "2 oct., 09:42" (local time).
 *
 * @param {Date} date
 * @param {Date} now
 * @returns {string}
 */
export function relativeDay(date, now) {
  const time = new Intl.DateTimeFormat(intlLocale(), {
    hour: '2-digit',
    minute: '2-digit'
  }).format(date)
  const day = d =>
    new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime()
  const days = Math.round((day(now) - day(date)) / 86_400_000)
  if (days === 0) return t('profile.sessions.today', { time })
  if (days === 1) return t('profile.sessions.yesterday', { time })

  const dayLabel = new Intl.DateTimeFormat(intlLocale(), {
    day: 'numeric',
    month: 'short',
    ...(date.getFullYear() === now.getFullYear() ? {} : { year: 'numeric' })
  }).format(date)

  return t('profile.sessions.day', { day: dayLabel, time })
}

/**
 * The day an account will be purged, e.g. "4 novembre 2026" (in the browser's timezone).
 *
 * @param {string|null|undefined} iso
 * @returns {string}
 */
export function deletionDay(iso) {
  if (!iso) return ''
  return new Intl.DateTimeFormat(intlLocale(), { dateStyle: 'long' }).format(
    new Date(iso)
  )
}

/**
 * What to tell after a refused step of the account deletion (the API's `error` code).
 *
 * @param {any} error an Axios error
 * @returns {{message: string, signInAgain: boolean}} signInAgain: a recent sign-in is needed
 */
export function deletionRefusal(error) {
  const code = error?.response?.data?.error
  switch (code) {
    case 'invalid_code':
      return {
        message: t('profile.deletionRefusals.invalid_code'),
        signInAgain: false
      }
    case 'code_expired':
      return {
        message: t('profile.deletionRefusals.code_expired'),
        signInAgain: false
      }
    case 'resend_too_soon':
      return {
        message: t('profile.deletionRefusals.resend_too_soon'),
        signInAgain: false
      }
    case 'recent_sign_in_required':
      return {
        message: t('profile.deletionRefusals.recent_sign_in_required'),
        signInAgain: true
      }
    case 'deletion_scheduled':
      return {
        message: t('profile.deletionRefusals.deletion_scheduled'),
        signInAgain: false
      }
    default:
      return {
        message:
          error?.response?.status === 429
            ? t('profile.deletionRefusals.tooMany')
            : t('profile.deletionRefusals.generic'),
        signInAgain: false
      }
  }
}
