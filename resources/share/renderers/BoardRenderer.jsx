import { Flag, Calendar, CheckCircle2, KanbanSquare, Users } from 'lucide-react'
import BoardStats from './BoardStats'

const PRIORITY_COLOR = {
  low: { text: 'text-cyan', bg: 'bg-cyan/10' },
  medium: { text: 'text-amber', bg: 'bg-amber/10' },
  high: { text: 'text-orange-400', bg: 'bg-orange-400/10' },
  urgent: { text: 'text-rose', bg: 'bg-rose/10' },
}

function initials(n) {
  const parts = String(n || '?').trim().split(/\s+/)
  return ((parts[0]?.[0] || '') + (parts[1]?.[0] || '')).toUpperCase() || '?'
}

// Simple stable hash for avatar gradient
function avatarGradient(name) {
  const gradients = [
    'from-primary to-primary-2',
    'from-cyan to-blue-500',
    'from-emerald-400 to-cyan',
    'from-amber to-rose',
    'from-pink-400 to-rose-500',
    'from-primary-glow to-cyan',
  ]
  let h = 0
  for (let i = 0; i < name.length; i++) h = ((h << 5) - h + name.charCodeAt(i)) | 0
  return gradients[Math.abs(h) % gradients.length]
}

function isOverdue(dateStr) {
  if (!dateStr) return false
  return new Date(dateStr) < new Date()
}

export default function BoardRenderer({ data }) {
  if (!data) return null
  const labelMap = Object.fromEntries((data.labels || []).map((l) => [l.id, l]))
  const columns = (data.columns || []).sort((a, b) => a.position - b.position)
  const totalTasks = columns.reduce((sum, c) => sum + (c.items?.length || 0), 0)

  return (
    <div>
      {/* Board header */}
      <div className="flex flex-col sm:flex-row sm:items-center gap-4 mb-8">
        <div className="flex items-center gap-4">
          <div className="w-14 h-14 rounded-2xl bg-primary/15 ring-1 ring-primary/30 grid place-items-center shrink-0">
            <KanbanSquare className="w-7 h-7 text-primary-glow" strokeWidth={1.7} />
          </div>
          <div>
            <h1 className="font-display text-3xl font-medium text-foreground tracking-[-0.03em]">{data.name}</h1>
            {data.description && <p className="text-[15px] text-muted mt-1">{data.description}</p>}
          </div>
        </div>
        <div className="flex items-center gap-3 sm:ml-auto">
          <span className="flex items-center gap-1.5 rounded-full bg-surface ring-1 ring-border px-3 py-1.5 text-[13px] text-muted font-mono">
            <KanbanSquare className="w-3.5 h-3.5" />
            {columns.length} columns
          </span>
          <span className="flex items-center gap-1.5 rounded-full bg-surface ring-1 ring-border px-3 py-1.5 text-[13px] text-muted font-mono">
            {totalTasks} tasks
          </span>
        </div>
      </div>

      {/* Stats panel — only when present */}
      {data.stats && <BoardStats stats={data.stats} />}

      {/* Columns — fixed height container with per-column vertical scroll */}
      <div className="flex gap-4 pb-4 overflow-x-auto" style={{ height: 'calc(100vh - 240px)', minHeight: '480px', maxHeight: '820px' }}>
        {columns.map((col) => {
          const done = (col.items || []).filter((t) => t.is_completed).length
          const total = col.items?.length || 0
          return (
            <div key={col.id} className="w-80 sm:w-96 shrink-0 rounded-2xl border border-border bg-surface/30 flex flex-col h-full">
              {/* Column header */}
              <div className="flex items-center gap-3 px-5 py-4 border-b border-border">
                <span
                  className="w-3 h-3 rounded-full ring-2 ring-offset-1 ring-offset-surface"
                  style={{ backgroundColor: col.color || 'var(--muted)', boxShadow: col.color ? `0 0 8px ${col.color}40` : 'none' }}
                />
                <span className="flex-1 text-[15px] font-semibold text-foreground">{col.name}</span>
                <span className="text-[13px] text-muted font-mono tabular-nums">{total}</span>
              </div>

              {/* Progress bar if column has items */}
              {total > 0 && done > 0 && (
                <div className="mx-5 mt-2.5 h-1 rounded-full bg-border overflow-hidden">
                  <div
                    className="h-full rounded-full bg-emerald-400 transition-all"
                    style={{ width: `${(done / total) * 100}%` }}
                  />
                </div>
              )}

              {/* Cards — scrollable */}
              <div className="flex-1 overflow-y-auto p-3 space-y-2.5">
                {(col.items || []).map((task) => (
                  <TaskCard key={task.id} task={task} labelMap={labelMap} />
                ))}
                {total === 0 && (
                  <div className="py-10 text-center text-[13px] text-muted2">No tasks</div>
                )}
              </div>
            </div>
          )
        })}
      </div>
    </div>
  )
}

function TaskCard({ task, labelMap }) {
  const labels = (task.label_ids || []).map((id) => labelMap[id]).filter(Boolean)
  const priority = task.priority ? PRIORITY_COLOR[task.priority] : null
  const overdue = !task.is_completed && isOverdue(task.due_date)

  return (
    <div className={`group rounded-xl border bg-background p-4 transition overflow-hidden ${
      task.is_completed ? 'border-border/60 opacity-70' : 'border-border hover:border-primary/30'
    }`}>
      {/* Labels */}
      {labels.length > 0 && (
        <div className="flex flex-wrap gap-1.5 mb-2.5">
          {labels.map((l) => (
            <span
              key={l.id}
              className="rounded-md px-2 py-0.5 text-[11px] font-medium text-white"
              style={{ backgroundColor: l.color }}
            >
              {l.name}
            </span>
          ))}
        </div>
      )}

      {/* Title */}
      <div className="flex items-start gap-2.5">
        {task.is_completed && <CheckCircle2 className="mt-0.5 w-5 h-5 shrink-0 text-emerald-400" />}
        <p className={`flex-1 min-w-0 text-[15px] font-medium leading-snug break-words overflow-wrap-anywhere ${
          task.is_completed ? 'text-muted line-through' : 'text-foreground'
        }`} style={{ overflowWrap: 'anywhere' }}>
          {task.title}
        </p>
      </div>

      {/* Description preview */}
      {task.description && !task.is_completed && (
        <p className="mt-2 text-[13px] text-muted leading-relaxed line-clamp-2 break-words" style={{ overflowWrap: 'anywhere' }}>
          {task.description.slice(0, 140)}
        </p>
      )}

      {/* Footer */}
      {(task.priority || task.due_date || task.assignee_names?.length > 0) && (
        <div className="mt-3 flex items-center justify-between gap-2">
          <div className="flex items-center gap-1.5">
            {priority && (
              <span className={`flex items-center gap-1 rounded-md px-2 py-0.5 text-[11px] font-medium ${priority.text} ${priority.bg}`}>
                <Flag className="w-3.5 h-3.5" />
                <span className="capitalize">{task.priority}</span>
              </span>
            )}
            {task.due_date && (
              <span className={`flex items-center gap-1 rounded-md px-2 py-0.5 text-[11px] font-mono ${
                overdue ? 'text-rose bg-rose/10' : 'text-muted bg-surface'
              }`}>
                <Calendar className="w-3.5 h-3.5" />
                {task.due_date.slice(0, 10)}
              </span>
            )}
          </div>
          {task.assignee_names?.length > 0 && (
            <div className="flex -space-x-2">
              {task.assignee_names.slice(0, 3).map((name, i) => (
                <div
                  key={i}
                  title={name}
                  className={`w-7 h-7 rounded-full bg-gradient-to-br ${avatarGradient(name)} grid place-items-center text-[10px] font-bold text-white ring-2 ring-background`}
                >
                  {initials(name)}
                </div>
              ))}
              {task.assignee_names.length > 3 && (
                <div className="w-7 h-7 rounded-full bg-surface2 grid place-items-center text-[10px] font-semibold text-muted ring-2 ring-background">
                  +{task.assignee_names.length - 3}
                </div>
              )}
            </div>
          )}
        </div>
      )}
    </div>
  )
}
