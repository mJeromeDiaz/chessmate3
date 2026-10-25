import { fromLocalInput, toLocalInput } from '@/utils/admin/shop'
import { t } from '@/i18n'

/**
 * The announcements' administration (docs/ANNOUNCEMENTS.md, § 2): the form, where each stands,
 * its email. Pure.
 *
 * @typedef {'draft'|'scheduled'|'live'|'ended'|'archived'} AnnouncementState
 *
 * @typedef {object} AdminAnnouncement
 * @property {string} id
 * @property {string} titleFr
 * @property {string} bodyFr
 * @property {string|null} titleEn
 * @property {string|null} bodyEn
 * @property {'draft'|'published'|'archived'} status
 * @property {AnnouncementState} state
 * @property {string|null} startsAt
 * @property {string|null} endsAt
 * @property {string|null} publishedAt
 * @property {string} createdAt
 * @property {string} updatedAt
 * @property {string|null} author the admin's email
 * @property {number} reads players who closed it
 * @property {{requestedAt: string|null, finishedAt: string|null, queued: number, sent: number}} email
 *
 * @typedef {object} AnnouncementForm
 * @property {string} titleFr
 * @property {string} bodyFr HTML from the editor
 * @property {boolean} english whether the English version is written
 * @property {string} titleEn
 * @property {string} bodyEn
 * @property {string} startsAt datetime-local value, '' for none
 * @property {string} endsAt
 *
 * @typedef {{titleFr: string, bodyFr: string, titleEn: string|null, bodyEn: string|null, startsAt: string|null, endsAt: string|null, publish: boolean}} AnnouncementPayload
 */

/** The longest title, as the API keeps it. */
export const TITLE_LENGTH = 120

/** The form's fields, as the API names them in its violations. */
export const FORM_FIELDS = [
  'titleFr',
  'bodyFr',
  'titleEn',
  'bodyEn',
  'startsAt',
  'endsAt'
]

/**
 * The messages of the violations tied to no field of the form, '' when none.
 *
 * @param {{propertyPath: string, title: string}[]} violations
 */
export function fieldlessViolations(violations) {
  return violations
    .filter(v => !FORM_FIELDS.includes(v.propertyPath))
    .map(v => v.title)
    .join(' ')
}

/** The editor's toolbar: the tags the server keeps (config/packages/html_sanitizer.yaml). */
export const EDITOR_TOOLBAR = [
  ['bold', 'italic', 'underline', 'strike'],
  ['unordered', 'ordered', 'quote'],
  ['link'],
  ['undo', 'redo']
]

/**
 * The form of an announcement, blank for a new one.
 *
 * @param {AdminAnnouncement|null} announcement
 * @returns {AnnouncementForm}
 */
export function announcementForm(announcement) {
  if (!announcement)
    return {
      titleFr: '',
      bodyFr: '',
      english: false,
      titleEn: '',
      bodyEn: '',
      startsAt: '',
      endsAt: ''
    }
  return {
    titleFr: announcement.titleFr,
    bodyFr: announcement.bodyFr,
    english: announcement.titleEn !== null,
    titleEn: announcement.titleEn ?? '',
    bodyEn: announcement.bodyEn ?? '',
    startsAt: toLocalInput(announcement.startsAt),
    endsAt: toLocalInput(announcement.endsAt)
  }
}

/**
 * What the API receives; without the English version, its fields are sent empty.
 *
 * @param {AnnouncementForm} form
 * @param {boolean} publish
 * @returns {AnnouncementPayload}
 */
export function announcementPayload(form, publish) {
  return {
    titleFr: form.titleFr.trim(),
    bodyFr: form.bodyFr,
    titleEn: form.english ? form.titleEn.trim() || null : null,
    bodyEn: form.english ? form.bodyEn || null : null,
    startsAt: fromLocalInput(form.startsAt),
    endsAt: fromLocalInput(form.endsAt),
    publish
  }
}

/**
 * Whether an HTML body holds some text (an empty editor sends "<br>").
 *
 * @param {string} html
 */
export function hasText(html) {
  return (
    html
      .replace(/<[^>]*>/g, '')
      .replace(/&nbsp;|&#160;/g, ' ')
      .trim() !== ''
  )
}

/**
 * Whether the form can be sent: a French title and text, and both English ones or neither.
 *
 * @param {AnnouncementForm} form
 */
export function announcementFormValid(form) {
  const english =
    !form.english || (form.titleEn.trim() !== '' && hasText(form.bodyEn))
  return form.titleFr.trim() !== '' && hasText(form.bodyFr) && english
}

/**
 * The label of where an announcement stands.
 *
 * @param {AnnouncementState} state
 */
export function stateLabel(state) {
  return t(`admin.announcements.states.${state}`)
}

/**
 * Whether its email may still be asked: published, not ended, not asked yet.
 *
 * @param {AdminAnnouncement} announcement
 */
export function canEmail(announcement) {
  return (
    (announcement.state === 'live' || announcement.state === 'scheduled') &&
    announcement.email.requestedAt === null
  )
}

/**
 * Where its email stands: `none`, `waiting` (asked, the announcement not started), `sending`,
 * `done`.
 *
 * @param {AdminAnnouncement} announcement
 * @returns {'none'|'waiting'|'sending'|'done'}
 */
export function emailState(announcement) {
  if (announcement.email.requestedAt === null) return 'none'
  if (announcement.email.finishedAt !== null) return 'done'
  return announcement.state === 'scheduled' ? 'waiting' : 'sending'
}
