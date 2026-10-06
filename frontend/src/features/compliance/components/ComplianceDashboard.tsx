import { useAbilities } from '@/features/auth/session'
import { useUrlState } from '@/shared/lib/hooks'
import { PageHeader, Tabs } from '@/shared/ui/Layout'
import { ConflictCheckForm } from './ConflictCheckForm'
import { FirmDashboard } from './FirmDashboard'
import { AmlReviews } from './KnowYourClient'
import { MCLETracker } from './MCLETracker'

type Tab = 'conflicts' | 'mcle' | 'firm-mcle' | 'aml'

export function ComplianceDashboard() {
  const abilities = useAbilities()
  const [tab, setTab] = useUrlState('tab', 'conflicts')

  return (
    <>
      <PageHeader title="Compliance" description="Conflict-of-interest checks, Mandatory Continuing Legal Education and anti-money-laundering reviews." />
      <Tabs<Tab>
        label="Compliance sections"
        value={tab as Tab}
        onChange={setTab}
        tabs={[
          { value: 'conflicts', label: 'Conflict checks' },
          ...(abilities.practice_law ? [{ value: 'mcle' as const, label: 'My MCLE' }] : []),
          ...(abilities.manage_firm ? [{ value: 'firm-mcle' as const, label: 'Firm MCLE' }] : []),
          ...(abilities.manage_finances ? [{ value: 'aml' as const, label: 'AML reviews' }] : []),
        ]}
      />
      {tab === 'conflicts' && <ConflictCheckForm />}
      {tab === 'mcle' && abilities.practice_law && <MCLETracker />}
      {tab === 'firm-mcle' && abilities.manage_firm && <FirmDashboard />}
      {tab === 'aml' && abilities.manage_finances && <AmlReviews />}
    </>
  )
}
