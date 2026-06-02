/**
 * Lightweight BlockNote JSON renderer (read-only).
 * Avoids importing the full @blocknote/react editor bundle.
 * Handles: paragraph, heading, bulletListItem, numberedListItem,
 * checkListItem, codeBlock, image, table, and inline content
 * (text with styles, links, mentions).
 *
 * Includes a "On this page" TOC sidebar on md+ screens,
 * matching the desktop app's DocTableOfContents layout.
 */

import { useMemo } from 'react'

/* ---- URL scheme allow-list (audit H3) ----
 * A malicious doc author can set link `href` or image `src` to
 * `javascript:`/`data:`/`file:` URLs. React does not strip these.
 * Anyone opening the share would execute attacker JS in the share
 * viewer's origin — with access to the decrypted snapshot in JS
 * memory, breaking the "decrypted in your browser, never sent to
 * our servers" guarantee.
 */
const SAFE_LINK_SCHEMES = new Set(['http:', 'https:', 'mailto:'])
const SAFE_IMG_SCHEMES = new Set(['http:', 'https:'])

function safeUrl(raw, allowed) {
  if (typeof raw !== 'string' || raw === '') return null
  try {
    const u = new URL(raw, 'https://placeholder.invalid')
    return allowed.has(u.protocol) ? raw : null
  } catch {
    return null
  }
}

function safeLinkHref(raw) { return safeUrl(raw, SAFE_LINK_SCHEMES) }
function safeImgSrc(raw)  { return safeUrl(raw, SAFE_IMG_SCHEMES) }

/* ---- heading extraction for TOC ---- */

function extractHeadings(blocks) {
  const headings = []
  for (const block of blocks || []) {
    if (block.type === 'heading' && block.content?.length) {
      const text = block.content
        .map((c) => c.text ?? c.props?.userName ?? '')
        .join('')
      if (text.trim()) {
        headings.push({ level: block.props?.level || 1, text: text.trim(), id: block.id })
      }
    }
  }
  return headings
}

function scrollToBlock(id) {
  if (!id) return
  const el = document.getElementById(`block-${id}`)
  if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

/* ---- main renderer ---- */

export default function DocRenderer({ data }) {
  if (!data) return null
  const headings = useMemo(() => extractHeadings(data.content), [data.content])

  return (
    <div className="flex min-h-0">
      {/* Editor content */}
      <div className="flex-1 min-w-0 max-w-3xl mx-auto">
        <h1 className="font-display text-4xl md:text-5xl font-medium text-foreground tracking-[-0.03em] mb-2">
          {data.title}
        </h1>
        {data.updated_at && (
          <div className="text-[13px] text-muted font-mono mb-10">
            Last updated {new Date(data.updated_at).toLocaleDateString()}
          </div>
        )}

        <div className="prose-share space-y-3.5">
          {(data.content || []).map((block, i) => (
            <Block key={block.id || i} block={block} />
          ))}
        </div>
      </div>

      {/* TOC sidebar — md+ only, sticky, mirrors desktop's DocTableOfContents */}
      {headings.length > 0 && (
        <aside className="hidden md:block w-56 lg:w-64 shrink-0 self-start sticky top-24 ml-8 lg:ml-12 border-l border-border pl-5">
          <nav className="space-y-1">
            <p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-muted2 mb-4">
              On this page
            </p>
            {headings.map((h, i) => (
              <button
                key={i}
                onClick={() => scrollToBlock(h.id)}
                className={`block w-full text-left text-[13px] py-1.5 rounded transition hover:text-foreground truncate ${
                  h.level === 1
                    ? 'font-medium text-foreground'
                    : h.level === 2
                      ? 'pl-3.5 text-muted'
                      : 'pl-6 text-muted2'
                }`}
              >
                {h.text}
              </button>
            ))}
          </nav>
        </aside>
      )}
    </div>
  )
}

/* ---- block renderer ---- */

function Block({ block }) {
  const { type, props = {}, content, children } = block

  switch (type) {
    case 'paragraph':
      return (
        <p className="text-[16px] text-foreground leading-[1.75]">
          <InlineContent content={content} />
        </p>
      )

    case 'heading': {
      const level = props.level || 1
      const cls = level === 1
        ? 'text-2xl md:text-3xl font-semibold text-foreground mt-10 mb-3 tracking-[-0.02em]'
        : level === 2
          ? 'text-xl md:text-2xl font-semibold text-foreground mt-8 mb-3 tracking-[-0.02em]'
          : 'text-lg font-semibold text-foreground mt-6 mb-2'
      const Tag = `h${Math.min(level, 6)}`
      return <Tag id={block.id ? `block-${block.id}` : undefined} className={cls}><InlineContent content={content} /></Tag>
    }

    case 'bulletListItem':
      return (
        <div className="flex gap-3 text-[16px] text-foreground pl-2 leading-[1.75]">
          <span className="text-muted mt-1">•</span>
          <div className="flex-1">
            <InlineContent content={content} />
            {children?.map((child, i) => <Block key={i} block={child} />)}
          </div>
        </div>
      )

    case 'numberedListItem':
      return (
        <div className="flex gap-3 text-[16px] text-foreground pl-2 leading-[1.75]">
          <span className="text-muted font-mono text-[14px] mt-0.5 w-5 shrink-0">{props.index || '1'}.</span>
          <div className="flex-1">
            <InlineContent content={content} />
            {children?.map((child, i) => <Block key={i} block={child} />)}
          </div>
        </div>
      )

    case 'checkListItem':
      return (
        <div className="flex items-start gap-3 text-[16px] pl-2 leading-[1.75]">
          <div className={`w-5 h-5 mt-1 rounded-md border shrink-0 grid place-items-center ${
            props.checked ? 'bg-primary/20 border-primary text-primary-glow' : 'border-border'
          }`}>
            {props.checked && <span className="text-[11px]">✓</span>}
          </div>
          <span className={props.checked ? 'text-muted line-through' : 'text-foreground'}>
            <InlineContent content={content} />
          </span>
        </div>
      )

    case 'codeBlock':
      return (
        <div className="rounded-xl bg-surface ring-1 ring-border p-5 overflow-x-auto my-2">
          {props.language && (
            <div className="kicker text-[11px] mb-3">{props.language}</div>
          )}
          <pre className="font-mono text-[14px] text-primary-glow/90 leading-relaxed whitespace-pre-wrap">
            <InlineContent content={content} />
          </pre>
        </div>
      )

    case 'image': {
      const url = safeImgSrc(props.url)
      return (
        <figure className="my-6">
          {url && (
            <img
              src={url}
              alt={props.caption || ''}
              className="rounded-xl max-w-full ring-1 ring-border"
            />
          )}
          {props.caption && <figcaption className="text-[13px] text-muted mt-3 text-center">{props.caption}</figcaption>}
        </figure>
      )
    }

    case 'table':
      return (
        <div className="rounded-xl ring-1 ring-border overflow-x-auto my-6">
          <table className="w-full text-[15px]">
            <tbody>
              {(content?.rows || children || []).map((row, ri) => (
                <tr key={ri} className="border-b border-border last:border-0">
                  {(row.cells || row.content || []).map((cell, ci) => (
                    <td key={ci} className="px-4 py-3 text-foreground">
                      {typeof cell === 'string' ? cell : <InlineContent content={Array.isArray(cell) ? cell : [cell]} />}
                    </td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )

    default:
      if (content) return <p className="text-[16px] text-foreground leading-[1.75]"><InlineContent content={content} /></p>
      return null
  }
}

/* ---- inline content ---- */

function InlineContent({ content }) {
  if (!content || !Array.isArray(content)) return null

  return content.map((item, i) => {
    if (!item) return null

    if (item.type === 'mention') {
      const name = item.props?.userName || item.props?.name || 'Unknown'
      return (
        <span key={i} className="inline-flex items-center gap-1 rounded-md bg-primary/15 text-primary-glow px-2 py-0.5 text-[14px] font-medium">
          @{name}
        </span>
      )
    }

    if (item.type === 'link') {
      const href = safeLinkHref(item.href)
      if (!href) {
        // Drop the anchor entirely on unsafe scheme; keep the
        // visible text so the user still sees the linked label.
        return <InlineContent key={i} content={item.content} />
      }
      return (
        <a key={i} href={href} className="text-primary-glow underline underline-offset-2" target="_blank" rel="noopener noreferrer">
          <InlineContent content={item.content} />
        </a>
      )
    }

    if (item.type === 'text' || typeof item.text === 'string') {
      const text = item.text ?? ''
      const styles = item.styles || {}
      let el = text

      if (styles.bold) el = <strong key="b">{el}</strong>
      if (styles.italic) el = <em key="i">{el}</em>
      if (styles.code) el = <code key="c" className="rounded-md bg-surface px-1.5 py-0.5 font-mono text-[14px] text-primary-glow">{el}</code>
      if (styles.strikethrough) el = <s key="s">{el}</s>
      if (styles.underline) el = <u key="u">{el}</u>

      return <span key={i}>{el}</span>
    }

    return null
  })
}
