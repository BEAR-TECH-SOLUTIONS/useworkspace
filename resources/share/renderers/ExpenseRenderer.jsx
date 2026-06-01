import { DollarSign, Calendar, Repeat, Tag, Building, FileText } from 'lucide-react'

export default function ExpenseRenderer({ data }) {
  if (!data) return null

  const amount = parseFloat(data.amount) || 0
  const currency = data.currency || 'USD'

  return (
    <div className="max-w-3xl mx-auto">
      {/* Amount hero */}
      <div className="rounded-3xl card p-10 mb-8 text-center">
        <div className="kicker text-[11px] mb-3">amount</div>
        <div className="font-display text-6xl md:text-7xl font-medium text-foreground tracking-[-0.03em] tabular-nums">
          {new Intl.NumberFormat('en-US', { style: 'currency', currency }).format(amount)}
        </div>
        {data.name && <div className="mt-4 text-lg text-muted">{data.name}</div>}
      </div>

      {/* Metadata grid */}
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-8">
        {data.category && (
          <MetaCard icon={Tag} label="Category" value={data.category} />
        )}
        {data.billing_cycle && (
          <MetaCard icon={Repeat} label="Billing cycle" value={data.billing_cycle.replace(/_/g, ' ')} />
        )}
        {data.vendor && (
          <MetaCard icon={Building} label="Vendor" value={data.vendor} />
        )}
        {data.next_due_date && (
          <MetaCard icon={Calendar} label="Next due" value={new Date(data.next_due_date).toLocaleDateString()} />
        )}
      </div>

      {data.description && (
        <div className="rounded-2xl card p-6 mb-8">
          <div className="kicker text-[11px] mb-3">Description</div>
          <div className="text-base text-foreground leading-relaxed whitespace-pre-wrap">{data.description}</div>
        </div>
      )}

      {/* Payments timeline */}
      {data.payments?.length > 0 && (
        <div className="rounded-2xl card p-6">
          <div className="flex items-center gap-2 mb-5">
            <DollarSign className="w-5 h-5 text-primary-glow" />
            <span className="kicker text-[11px]">Payment history</span>
            <span className="text-[13px] text-muted font-mono ml-auto">{data.payments.length} payments</span>
          </div>
          <div className="space-y-0">
            {data.payments.map((p, i) => {
              const pAmount = parseFloat(p.amount) || 0
              const pCurrency = p.currency || currency
              return (
                <div key={i} className="flex items-center gap-3.5 py-3.5 border-b border-border last:border-0">
                  <div className="w-2 h-2 rounded-full bg-primary shrink-0" />
                  <div className="flex-1 min-w-0">
                    <div className="text-[15px] text-foreground font-mono tabular-nums">
                      {new Intl.NumberFormat('en-US', { style: 'currency', currency: pCurrency }).format(pAmount)}
                    </div>
                    {p.note && <div className="text-[13px] text-muted truncate mt-0.5">{p.note}</div>}
                  </div>
                  {p.paid_at && (
                    <span className="text-[13px] text-muted font-mono shrink-0">
                      {new Date(p.paid_at).toLocaleDateString()}
                    </span>
                  )}
                </div>
              )
            })}
          </div>
        </div>
      )}
    </div>
  )
}

function MetaCard({ icon: Icon, label, value }) {
  return (
    <div className="rounded-2xl card p-5">
      <div className="flex items-center gap-2 mb-2">
        <Icon className="w-4 h-4 text-muted" />
        <span className="kicker text-[11px]">{label}</span>
      </div>
      <div className="text-base text-foreground capitalize">{value}</div>
    </div>
  )
}
