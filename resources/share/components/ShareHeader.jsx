import { useEffect, useState } from 'react'
import { Eye, Clock, AlertTriangle } from 'lucide-react'

function computeTimeLeft(expiresAt) {
  if (!expiresAt) return null
  const diff = new Date(expiresAt) - Date.now()
  if (diff <= 0) return { expired: true }
  const totalSec = Math.floor(diff / 1000)
  const days = Math.floor(totalSec / 86400)
  const hrs = Math.floor((totalSec % 86400) / 3600)
  const mins = Math.floor((totalSec % 3600) / 60)
  const secs = totalSec % 60
  return { expired: false, days, hrs, mins, secs }
}

function pad(n) { return String(n).padStart(2, '0') }

function Countdown({ expiresAt }) {
  const [time, setTime] = useState(() => computeTimeLeft(expiresAt))

  useEffect(() => {
    if (!expiresAt) return
    const id = setInterval(() => setTime(computeTimeLeft(expiresAt)), 1000)
    return () => clearInterval(id)
  }, [expiresAt])

  if (!time || time.expired) return null

  return (
    <span className="flex items-center gap-1.5 text-[13px] text-muted font-mono tabular-nums">
      <Clock className="w-3.5 h-3.5" />
      {time.days > 0 && <Unit value={time.days} label="d" />}
      <Unit value={time.hrs} label="h" />
      <Unit value={time.mins} label="m" />
      <Unit value={time.secs} label="s" />
    </span>
  )
}

function Unit({ value, label }) {
  return (
    <span>
      <span className="text-foreground">{pad(value)}</span>
      <span className="text-muted2">{label}</span>
    </span>
  )
}

const TYPE_LABEL = {
  board: 'Board',
  task: 'Task',
  credential: 'Credential',
  doc: 'Document',
  expense: 'Expense',
}

/**
 * Info bar that sits below the main Nav on share pages.
 * Shows read-only badge, resource type, shared-by, countdown, and view warnings.
 */
export default function ShareInfoBar({ shareInfo }) {
  if (!shareInfo) return null

  return (
    <div className="flex flex-wrap items-center gap-3 rounded-2xl bg-surface/60 ring-1 ring-border backdrop-blur px-5 py-3.5">
      <div className="flex items-center gap-1.5 rounded-full bg-background ring-1 ring-border px-3 py-1.5 text-[12px] text-muted">
        <Eye className="w-3.5 h-3.5" />
        <span>Read-only</span>
      </div>

      {shareInfo.resource_type && (
        <span className="rounded-full bg-primary/15 text-primary-glow px-3 py-1.5 text-[12px] font-medium">
          {TYPE_LABEL[shareInfo.resource_type] || shareInfo.resource_type}
        </span>
      )}

      {shareInfo.name && (
        <span className="text-[15px] text-foreground font-medium truncate max-w-md">
          {shareInfo.name}
        </span>
      )}

      {shareInfo.created_by_name && (
        <span className="text-[13px] text-muted">
          by <span className="text-foreground">{shareInfo.created_by_name}</span>
        </span>
      )}

      <div className="flex items-center gap-3 ml-auto">
        {shareInfo.expires_at && <Countdown expiresAt={shareInfo.expires_at} />}
        {shareInfo.views_remaining === 1 && (
          <span className="flex items-center gap-1.5 text-[12px] text-amber font-mono">
            <AlertTriangle className="w-3.5 h-3.5" />
            Last view
          </span>
        )}
      </div>
    </div>
  )
}
