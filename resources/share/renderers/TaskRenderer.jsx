import { Flag, Calendar, User, Columns, CheckCircle2, Circle, CheckSquare } from 'lucide-react'

const PRIORITY_COLOR = { low: 'text-cyan bg-cyan/10', medium: 'text-amber bg-amber/10', high: 'text-orange-400 bg-orange-400/10', urgent: 'text-rose bg-rose/10' }

export default function TaskRenderer({ data }) {
  if (!data) return null

  return (
    <div className="max-w-3xl mx-auto">
      <div className="flex items-start gap-4 mb-6">
        {data.is_completed && <CheckCircle2 className="w-8 h-8 text-emerald-400 mt-1.5 shrink-0" />}
        <h1 className={`font-display text-4xl font-medium tracking-[-0.03em] ${data.is_completed ? 'text-muted line-through' : 'text-foreground'}`}>
          {data.title}
        </h1>
      </div>

      {/* Metadata */}
      <div className="flex flex-wrap gap-2.5 mb-8">
        {data.priority && (
          <span className={`flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-[13px] font-medium ${PRIORITY_COLOR[data.priority] || 'text-muted bg-surface'}`}>
            <Flag className="w-4 h-4" />
            <span className="capitalize">{data.priority}</span>
          </span>
        )}
        {data.column_name && (
          <span className="flex items-center gap-1.5 rounded-lg bg-surface px-3 py-1.5 text-[13px] text-foreground">
            <Columns className="w-4 h-4 text-muted" />
            {data.column_name}
          </span>
        )}
        {data.due_date && (
          <span className="flex items-center gap-1.5 rounded-lg bg-surface px-3 py-1.5 text-[13px] text-foreground font-mono">
            <Calendar className="w-4 h-4 text-muted" />
            {data.due_date.slice(0, 10)}
          </span>
        )}
        {data.assignee_names?.map((name, i) => (
          <span key={i} className="flex items-center gap-1.5 rounded-lg bg-surface px-3 py-1.5 text-[13px] text-foreground">
            <User className="w-4 h-4 text-muted" />
            {name}
          </span>
        ))}
        {data.label_ids?.map((id) => (
          <span key={id} className="rounded-lg bg-surface px-3 py-1.5 text-[13px] text-muted font-mono">
            label #{id}
          </span>
        ))}
      </div>

      {/* Description */}
      {data.description && (
        <div className="rounded-2xl card p-6 mb-8">
          <div className="kicker text-[11px] mb-4">Description</div>
          <div className="text-base text-foreground leading-relaxed whitespace-pre-wrap">{data.description}</div>
        </div>
      )}

      {/* Checklists */}
      {data.checklists?.length > 0 && (
        <div className="rounded-2xl card p-6">
          <div className="flex items-center gap-2 mb-4">
            <CheckSquare className="w-5 h-5 text-primary-glow" />
            <span className="kicker text-[11px]">Checklist</span>
            <span className="text-[13px] text-muted font-mono ml-auto">
              {data.checklists.filter((c) => c.is_checked).length}/{data.checklists.length}
            </span>
          </div>
          <div className="space-y-2.5">
            {data.checklists
              .sort((a, b) => a.position - b.position)
              .map((item, i) => (
                <div key={i} className="flex items-center gap-3 text-[15px]">
                  {item.is_checked ? (
                    <CheckCircle2 className="w-5 h-5 text-primary-glow shrink-0" />
                  ) : (
                    <Circle className="w-5 h-5 text-muted shrink-0" />
                  )}
                  <span className={item.is_checked ? 'text-muted line-through' : 'text-foreground'}>{item.text}</span>
                </div>
              ))}
          </div>
        </div>
      )}
    </div>
  )
}
