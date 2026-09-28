import { BookOpen, Plus } from 'lucide-react'
import { useState } from 'react'
import { useAbilities, useLookups } from '@/features/auth/session'
import { date } from '@/shared/lib/format'
import { useUrlState } from '@/shared/lib/hooks'
import { Button } from '@/shared/ui/Button'
import { Badge, EmptyState, ErrorState, PageLoader } from '@/shared/ui/Feedback'
import { SearchInput, Select } from '@/shared/ui/Form'
import { Highlight } from '@/shared/ui/Highlight'
import { Card, PageHeader } from '@/shared/ui/Layout'
import { useKnowledge, type KnowledgeItem } from '../api'
import { KnowledgeEditor, KnowledgeView } from './KnowledgeDialogs'

/** The firm's library of model pleadings, clauses, forms and jurisprudence notes. */
export function KnowledgePage() {
  const abilities = useAbilities()
  const lookups = useLookups()
  const [search, setSearch] = useUrlState('q', '')
  const [kind, setKind] = useUrlState('kind', '')
  const [tag, setTag] = useUrlState('tag', '')
  const [area, setArea] = useUrlState('area', '')
  const [openId, setOpenId] = useUrlState('open', '')
  const [editing, setEditing] = useState<KnowledgeItem | 'new' | null>(null)
  const query = useKnowledge({ search, kind, tag, practice_area: area })
  const kinds = query.data?.kinds ?? {}

  return (
    <>
      <PageHeader
        title="Knowledge bank"
        description="The firm's model pleadings, clauses, forms and jurisprudence notes. Insert them while drafting a pleading; the AI assistant also draws on entries for the matter's practice area."
        actions={abilities.work_matters && <Button icon={<Plus className="size-4" />} onClick={() => setEditing('new')}>Add entry</Button>}
      />
      <Card>
        <div className="flex flex-wrap items-center gap-2 border-b border-outline-variant p-3">
          <div className="min-w-60 flex-1"><SearchInput value={search} onChange={setSearch} placeholder="Search words, doctrines, G.R. numbers" label="Search the knowledge bank" /></div>
          <Select aria-label="Kind" value={kind} onChange={(e) => setKind(e.target.value)} className="w-44">
            <option value="">All kinds</option>
            {Object.entries(kinds).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
          </Select>
          <Select aria-label="Practice area" value={area} onChange={(e) => setArea(e.target.value)} className="w-44">
            <option value="">All practice areas</option>
            {lookups.data?.case_types.map((t) => <option key={t} value={t}>{t}</option>)}
          </Select>
        </div>
        {!!query.data?.tags.length && (
          <div className="flex flex-wrap gap-1.5 border-b border-outline-variant px-3 py-2">
            {query.data.tags.map((t) => (
              <button key={t} type="button" onClick={() => setTag(tag === t ? '' : t)} aria-pressed={tag === t}
                className={`rounded-[2px] px-1.5 py-px text-xs font-semibold ${tag === t ? 'bg-primary text-on-primary' : 'bg-surface-container-high text-on-surface-variant hover:bg-on-surface/10'}`}>
                {t}
              </button>
            ))}
          </div>
        )}
        {query.isPending ? <PageLoader /> : query.isError ? <div className="p-3"><ErrorState error={query.error} /></div> : query.data.data.length === 0 ? (
          <EmptyState icon={<BookOpen className="size-6" />} title={search || kind || tag || area ? 'Nothing matches' : 'The knowledge bank is empty'}
            description={search || kind || tag || area ? undefined : 'Add jurisprudence notes and clauses here, or keep a finished pleading from its document page.'} />
        ) : (
          <ul className="divide-y divide-outline-variant">
            {query.data.data.map((k) => (
              <li key={k.id}>
                <button type="button" onClick={() => setOpenId(String(k.id))} className="w-full px-4 py-3 text-left hover:bg-on-surface/5">
                  <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                    <span className="font-medium">{k.title}</span>
                    <Badge tone={k.kind === 'jurisprudence' ? 'primary' : 'neutral'}>{k.kind_label}</Badge>
                    {k.citation && <span className="text-xs text-on-surface-variant">{k.citation}</span>}
                  </div>
                  {k.snippet ? <Highlight text={k.snippet} className="mt-0.5 text-sm text-on-surface-variant" /> : k.doctrine && <p className="mt-0.5 line-clamp-2 text-sm text-on-surface-variant">{k.doctrine}</p>}
                  <div className="mt-1 flex flex-wrap gap-1 text-xs text-on-surface-variant">
                    {k.practice_area && <span>{k.practice_area} ·</span>}
                    {k.tags.map((t) => <span key={t}>#{t}</span>)}
                    <span>· {k.created_by ?? ''} {date(k.updated_at)}</span>
                  </div>
                </button>
              </li>
            ))}
          </ul>
        )}
      </Card>
      {openId && <KnowledgeView id={Number(openId)} onClose={() => setOpenId('')} onEdit={abilities.work_matters ? (item) => { setOpenId(''); setEditing(item) } : undefined} />}
      {editing && <KnowledgeEditor item={editing === 'new' ? undefined : editing} kinds={kinds} onClose={() => setEditing(null)} />}
    </>
  )
}
