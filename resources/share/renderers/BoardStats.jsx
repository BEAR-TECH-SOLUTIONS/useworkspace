import { formatDistance, parseISO } from 'date-fns'
import {
  CheckCircle2, PlusCircle, ArrowRight, MessageSquare,
  Lock, AlertCircle,
} from 'lucide-react'

const ACTIVITY_ICON = {
  created: { icon: PlusCircle, color: 'text-cyan' },
  completed: { icon: CheckCircle2, color: 'text-emerald-400' },
  moved: { icon: ArrowRight, color: 'text-primary-glow' },
  commented: { icon: MessageSquare, color: 'text-amber' },
}

const ACTIVITY_VERB = {
  created: 'created',
  completed: 'completed',
  moved: (detail) => `moved to ${detail || '…'}`,
  commented: (detail) => detail || 'commented',
}

/* ---- time helpers ---- */

function formatFrozenDate(isoStr, tz) {
  try {
    const d = new Date(isoStr)
    return d.toLocaleDateString('en-US', {
      year: 'numeric', month: 'short', day: 'numeric',
      hour: '2-digit', minute: '2-digit', hour12: false,
      timeZone: tz,
    }) + ` (${tz})`
  } catch {
    return isoStr
  }
}

function relativeTime(atStr, generatedAtStr) {
  try {
    return formatDistance(parseISO(atStr), parseISO(generatedAtStr), { addSuffix: true })
  } catch {
    return ''
  }
}

function isStale(generatedAt) {
  try {
    return Date.now() - new Date(generatedAt).getTime() > 86400000
  } catch {
    return false
  }
}

/* ---- safe stats reader ---- */
function safe(stats) {
  try {
    return {
      totals: stats?.totals || {},
      today: stats?.today || {},
      thisWeek: stats?.this_week || {},
      activity: Array.isArray(stats?.recent_activity) ? stats.recent_activity : [],
      generatedAt: stats?.generated_at || null,
      tz: stats?.timezone || 'UTC',
    }
  } catch {
    return null
  }
}

/* ---- main component ---- */

export default function BoardStats({ stats }) {
  const s = safe(stats)
  if (!s || !s.generatedAt) return null

  const stale = isStale(s.generatedAt)
  const frozenDate = formatFrozenDate(s.generatedAt, s.tz)
  const pct = s.totals.total_tasks > 0
    ? Math.round((s.totals.completed / s.totals.total_tasks) * 100)
    : null

  return (
    <div className="rounded-2xl card p-6 mb-8 space-y-6">
      {/* Frozen banner */}
      <div className="flex items-start gap-2.5 rounded-xl bg-surface ring-1 ring-border px-4 py-3 text-[13px] text-muted">
        <Lock className="w-4 h-4 shrink-0 mt-0.5 text-primary-glow" />
        <span>
          Stats frozen{stale && <> <strong>{formatDistance(new Date(), parseISO(s.generatedAt))} ago</strong></>} on{' '}
          <strong className="text-foreground">{frozenDate}</strong>. The board may have changed since.
        </span>
      </div>

      {/* KPI row */}
      <dl className="grid grid-cols-2 md:grid-cols-4 gap-4">
        <KpiCard value={s.totals.total_tasks ?? 0} label="Total" />
        <KpiCard value={s.totals.completed ?? 0} label="Completed" sub={pct != null && pct > 0 ? `${pct}%` : null} />
        <KpiCard value={s.totals.in_progress ?? 0} label="In progress" />
        <KpiCard value={s.totals.overdue ?? 0} label="Overdue" danger={s.totals.overdue > 0} />
      </dl>

      {/* Today / This week */}
      <div className="grid md:grid-cols-2 gap-4">
        <div className="rounded-xl bg-surface ring-1 ring-border p-5">
          <div className="kicker text-[11px] mb-4">Today</div>
          <div className="space-y-2.5">
            <MiniStat icon="✓" label="completed" value={s.today.completed ?? 0} />
            <MiniStat icon="+" label="created" value={s.today.created ?? 0} />
            <MiniStat icon="→" label="moved" value={s.today.moved ?? 0} />
            <MiniStat icon="💬" label="commented" value={s.today.commented ?? 0} />
          </div>
        </div>
        <div className="rounded-xl bg-surface ring-1 ring-border p-5">
          <div className="kicker text-[11px] mb-4">This week</div>
          <div className="space-y-2.5">
            <MiniStat icon="✓" label="completed" value={s.thisWeek.completed ?? 0} />
            <MiniStat icon="+" label="created" value={s.thisWeek.created ?? 0} />
          </div>
        </div>
      </div>

      {/* Activity timeline */}
      <div>
        <div className="kicker text-[11px] mb-4">Recent activity</div>
        {s.activity.length === 0 ? (
          <p className="text-[13px] text-muted2 py-6 text-center">No activity in this window.</p>
        ) : (
          <ol className="space-y-0">
            {s.activity.map((item, i) => (
              <ActivityRow key={i} item={item} generatedAt={s.generatedAt} />
            ))}
          </ol>
        )}
      </div>
    </div>
  )
}

/* ---- sub-components ---- */

function KpiCard({ value, label, sub, danger }) {
  return (
    <div className="rounded-xl bg-surface ring-1 ring-border p-4">
      <dd className={`font-display text-3xl md:text-4xl font-medium tabular-nums tracking-[-0.02em] ${
        danger ? 'text-rose' : 'text-foreground'
      }`}>
        {danger && <AlertCircle className="w-5 h-5 inline-block mr-1.5 -mt-1" />}
        {value}
      </dd>
      <dt className="kicker text-[11px] mt-2">
        {label}
        {sub && <span className="ml-2 text-emerald-400 normal-case tracking-normal">{sub}</span>}
      </dt>
    </div>
  )
}

function MiniStat({ icon, label, value }) {
  const dim = value === 0
  return (
    <div className={`flex items-center gap-3 text-[14px] ${dim ? 'text-muted2' : 'text-foreground'}`}>
      <span className="w-6 text-center text-[13px]">{icon}</span>
      <span className="font-mono tabular-nums w-8 text-right">{value}</span>
      <span className={dim ? 'text-muted2' : 'text-muted'}>{label}</span>
    </div>
  )
}

function ActivityRow({ item, generatedAt }) {
  const meta = ACTIVITY_ICON[item.type] || ACTIVITY_ICON.created
  const Icon = meta.icon
  const verb = typeof ACTIVITY_VERB[item.type] === 'function'
    ? ACTIVITY_VERB[item.type](item.detail)
    : ACTIVITY_VERB[item.type] || item.type
  const rel = relativeTime(item.at, generatedAt)

  return (
    <li className="flex items-start gap-3.5 py-3.5 border-b border-border last:border-0">
      <div className={`w-8 h-8 rounded-full bg-surface2 grid place-items-center shrink-0 ${meta.color}`}>
        <Icon className="w-4 h-4" />
      </div>
      <div className="flex-1 min-w-0">
        <div className="text-[14px] leading-snug">
          <span className="font-medium text-foreground">{item.task_title}</span>{' '}
          <span className="text-muted">{verb}</span>
          {item.actor_name && (
            <span className="text-muted2"> · by {item.actor_name}</span>
          )}
        </div>
      </div>
      {item.at && (
        <time dateTime={item.at} className="text-[12px] text-muted2 font-mono shrink-0 mt-1">
          {rel}
        </time>
      )}
    </li>
  )
}
