import { t } from '@/i18n'

/**
 * The site's legal pages (docs/SECURITY.md, § 12): the terms of use and the privacy policy, their
 * texts in `legal.*`. A draft until reviewed: the page says so.
 */

/**
 * The publisher, a private individual, named in both pages. To fill in once, here.
 */
export const PUBLISHER = {
  name: '[À COMPLÉTER : nom de l’éditeur]',
  email: '[À COMPLÉTER : e-mail de contact]'
}

/**
 * The sections of each page, in order, and their number of paragraphs (`legal.<page>.<key>.p.<i>`).
 *
 * @type {Record<'terms'|'privacy', [string, number][]>}
 */
export const LEGAL_SECTIONS = {
  terms: [
    ['object', 2],
    ['access', 3],
    ['account', 3],
    ['use', 2],
    ['crystals', 2],
    ['content', 2],
    ['liability', 2],
    ['changes', 2]
  ],
  privacy: [
    ['controller', 2],
    ['data', 6],
    ['purposes', 4],
    ['retention', 4],
    ['recipients', 5],
    ['cookies', 2],
    ['rights', 3]
  ]
}

/**
 * A page's sections in the current language.
 *
 * @param {'terms'|'privacy'} page
 * @returns {{key: string, title: string, paragraphs: string[]}[]}
 */
export function legalSections(page) {
  return LEGAL_SECTIONS[page].map(([key, count]) => ({
    key,
    title: t(`legal.${page}.${key}.title`),
    paragraphs: Array.from({ length: count }, (_, i) =>
      t(`legal.${page}.${key}.p.${i}`, PUBLISHER)
    )
  }))
}
