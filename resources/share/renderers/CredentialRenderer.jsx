import { useState, useCallback } from 'react'
import {
  KeyRound, Terminal, HardDrive, Database, StickyNote, BadgeCheck, FileCode,
  LayoutTemplate, CreditCard, Copy, Eye, EyeOff, Check, AlertTriangle, Shield,
} from 'lucide-react'

const TYPE_META = {
  login: { icon: KeyRound, label: 'Login' },
  ssh: { icon: Terminal, label: 'SSH' },
  ftp: { icon: HardDrive, label: 'FTP' },
  database: { icon: Database, label: 'Database' },
  api_key: { icon: KeyRound, label: 'API Key' },
  note: { icon: StickyNote, label: 'Note' },
  software_license: { icon: BadgeCheck, label: 'License' },
  env: { icon: FileCode, label: 'Env' },
  card: { icon: CreditCard, label: 'Card' },
  custom: { icon: LayoutTemplate, label: 'Custom' },
}

const SENSITIVE = new Set(['password', 'private_key', 'passphrase', 'secret', 'token', 'pin', 'cvv', 'notes'])

function isSensitive(key) {
  return SENSITIVE.has(key) || key.includes('password') || key.includes('secret') || key.includes('key')
}

export default function CredentialRenderer({ data, viewsRemaining }) {
  if (!data) return null

  const meta = TYPE_META[data.type] || TYPE_META.custom
  const Icon = meta.icon
  const decrypted = data._decrypted || {}
  const isLastView = viewsRemaining === 0

  return (
    <div className="max-w-3xl mx-auto">
      {/* Header */}
      <div className="flex items-center gap-4 mb-8">
        <div className="w-16 h-16 rounded-2xl bg-primary/15 ring-1 ring-primary/30 grid place-items-center shrink-0">
          <Icon className="w-7 h-7 text-primary-glow" />
        </div>
        <div className="min-w-0">
          <h1 className="font-display text-3xl font-medium text-foreground tracking-[-0.03em] truncate">{data.name}</h1>
          <div className="mt-1.5 flex items-center gap-2.5 text-[13px] text-muted">
            <span className="rounded-md bg-surface2 px-2 py-0.5 uppercase tracking-wider font-mono text-[11px]">
              {meta.label}
            </span>
            {data.url && <span className="font-mono truncate">{data.url}</span>}
          </div>
        </div>
      </div>

      {isLastView && (
        <div className="flex items-start gap-2.5 rounded-2xl ring-1 ring-amber/40 bg-amber/10 text-amber px-5 py-4 text-[14px] mb-8">
          <AlertTriangle className="w-5 h-5 shrink-0 mt-0.5" />
          <span>This data is shown once. Copy what you need now — refreshing the page will not bring it back.</span>
        </div>
      )}

      {/* Plaintext metadata */}
      {data.tags?.length > 0 && (
        <div className="flex flex-wrap gap-2 mb-6">
          {data.tags.map((tag, i) => (
            <span key={i} className="rounded-md bg-surface2 px-2.5 py-1 text-[12px] text-foreground">{tag}</span>
          ))}
        </div>
      )}

      {/* Decrypted fields */}
      {Object.keys(decrypted).length > 0 ? (
        <div className="space-y-4">
          {Object.entries(decrypted).map(([key, value]) => {
            if (value == null || value === '') return null
            return (
              <FieldRow
                key={key}
                label={key.replace(/_/g, ' ')}
                value={String(value)}
                sensitive={isSensitive(key)}
              />
            )
          })}
        </div>
      ) : (
        <div className="rounded-2xl card p-8 text-center text-base text-muted">
          No additional fields in this credential.
        </div>
      )}

      <div className="mt-8 flex items-center gap-2 text-[13px] text-muted2">
        <Shield className="w-4 h-4" />
        <span>Decrypted in your browser. The server never saw these values.</span>
      </div>
    </div>
  )
}

function FieldRow({ label, value, sensitive }) {
  const [revealed, setRevealed] = useState(!sensitive)
  const [copied, setCopied] = useState(false)

  const copy = useCallback(async () => {
    try {
      await navigator.clipboard.writeText(value)
      setCopied(true)
      setTimeout(() => setCopied(false), 2000)
    } catch {}
  }, [value])

  const isMultiline = value.includes('\n') || value.length > 120

  return (
    <div className="rounded-2xl card p-5">
      <div className="kicker text-[11px] mb-3">{label}</div>
      <div className="flex items-start gap-3">
        <div className={`flex-1 min-w-0 font-mono text-[15px] leading-relaxed ${isMultiline ? 'whitespace-pre-wrap break-all' : 'truncate'} ${
          sensitive && !revealed ? 'text-muted' : 'text-foreground'
        }`}>
          {sensitive && !revealed ? '•'.repeat(Math.min(value.length, 28)) : value}
        </div>
        <div className="flex items-center gap-1.5 shrink-0">
          {sensitive && (
            <button
              onClick={() => setRevealed((r) => !r)}
              className="w-9 h-9 grid place-items-center rounded-lg text-muted hover:text-foreground hover:bg-surface2 transition"
              aria-label={revealed ? 'Hide' : 'Reveal'}
            >
              {revealed ? <EyeOff className="w-4.5 h-4.5" /> : <Eye className="w-4.5 h-4.5" />}
            </button>
          )}
          <button
            onClick={copy}
            className="w-9 h-9 grid place-items-center rounded-lg text-muted hover:text-foreground hover:bg-surface2 transition"
            aria-label="Copy"
          >
            {copied ? <Check className="w-4.5 h-4.5 text-emerald-400" /> : <Copy className="w-4.5 h-4.5" />}
          </button>
        </div>
      </div>
    </div>
  )
}
