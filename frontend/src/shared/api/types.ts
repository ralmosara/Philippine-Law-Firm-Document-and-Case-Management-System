/**
 * Types mirroring the Laravel API Resources in backend/app/Http/Resources.
 * Money is always integer centavos (`*_cents`); dates are ISO strings.
 */

export interface Paginated<T> {
  data: T[]
  meta: { current_page: number; last_page: number; per_page: number; total: number; from: number | null; to: number | null }
  links: { next: string | null; prev: string | null }
}

export interface Option<V extends string = string> {
  value: V
  label: string
}

export type RoleValue = 'managing_partner' | 'partner' | 'associate' | 'paralegal' | 'staff'
export type MatterStatus = 'intake' | 'filed' | 'pre_trial' | 'trial' | 'decision' | 'appeal' | 'closed'
export type DeadlineKind = 'filing' | 'hearing' | 'task'
export type DeadlineStatus = 'pending' | 'completed' | 'missed' | 'cancelled'
export type DocumentStatus = 'draft' | 'final' | 'pending_signature' | 'signed' | 'notarized'
export type InvoiceStatus = 'draft' | 'issued' | 'partially_paid' | 'paid' | 'void' | 'written_off'
export type FeeArrangement = 'hourly' | 'flat' | 'retainer' | 'contingency' | 'pro_bono'
export type ConflictStatus = 'clear' | 'flagged' | 'waived' | 'declined'

export interface UserRef {
  id: number
  name: string
}

export interface User extends UserRef {
  email: string
  role: RoleValue
  role_label: string
  is_lawyer: boolean
  ibp_number: string | null
  roll_number: string | null
  ptr_number?: string | null
  mcle_compliance_number?: string | null
  ptr_date?: string | null
  ptr_place?: string | null
  ibp_date?: string | null
  ibp_chapter?: string | null
  ibp_lifetime?: boolean
  /** For lawyers: PTR or IBP details missing or not for this year. */
  credential_problems?: string[]
  away_from?: string | null
  away_until?: string | null
  cover_user_id?: number | null
  is_away?: boolean
  mobile_number: string | null
  hourly_rate_cents: number
  daily_target_minutes: number | null
  is_active: boolean
  two_factor_enabled: boolean
  last_login_at: string | null
  created_at: string
}

export interface Abilities {
  manage_firm: boolean
  manage_finances: boolean
  work_matters: boolean
  practice_law: boolean
}

export interface Firm {
  id: number
  name: string
  tin: string | null
  address: string | null
  vat_registered: boolean
  email?: string | null
  phone?: string | null
  require_two_factor: boolean
  /** Suggested creditable withholding on professional fees, in basis points (1000 = 10%). */
  default_withholding_bps?: number
}

export interface Session {
  user: User
  firm: Firm
  abilities: Abilities
}

export interface Lookups {
  roles: Option<RoleValue>[]
  matter_statuses: Option<MatterStatus>[]
  party_roles: Option[]
  deadline_kinds: Option<DeadlineKind>[]
  case_types: string[]
  notarial_act_types: Option[]
  expense_categories: Option[]
  fee_arrangements: Option<FeeArrangement>[]
}

export interface Client {
  id: number
  type: 'individual' | 'corporate'
  name: string
  tin: string | null
  email: string | null
  phone: string | null
  address: string | null
  notes: string | null
  aliases?: string | null
  portal_enabled: boolean
  /** The client has turned on two-step sign-in for the portal. */
  portal_two_factor?: boolean
  portal_locale: 'en' | 'fil'
  /** Hearing notices and reminders by email and SMS (when the firm sends them). */
  hearing_reminders: boolean
  portal_password_set: boolean
  last_portal_login_at: string | null
  matters_count?: number
  active_matters_count?: number
  matters?: Matter[]
  created_at: string
}

export interface MatterRef {
  id: number
  reference: string
  title: string
}

export interface MatterParty {
  id: number
  role: string
  role_label: string
  is_adverse: boolean
  name: string
  counsel_name: string | null
  contact: string | null
  notes: string | null
}

export interface Matter extends MatterRef {
  case_type: string
  case_number: string | null
  court: string | null
  court_branch: string | null
  judge: string | null
  status: MatterStatus
  status_label: string
  allowed_transitions: Option<MatterStatus>[]
  description: string | null
  opened_at: string
  closed_at: string | null
  client_id: number
  client?: Client
  responsible_lawyer?: UserRef | null
  parties?: MatterParty[]
  next_deadline?: Deadline | null
  unbilled_cents?: number
  unbilled_expenses_cents?: number
  fee_arrangement?: FeeArrangement
  fee_arrangement_label?: string
  fixed_fee_cents?: number | null
  acceptance_fee_cents?: number | null
  appearance_fee_cents?: number | null
  contingency_basis_points?: number | null
  client_role?: string
  nature_of_action?: string | null
  retainer_auto_bill?: boolean
  retainer_billing_day?: number
  retainer_auto_issue?: boolean
  retainer_billed_through?: string | null
  created_at: string
}

export interface StatusEvent {
  id: number
  from_status: MatterStatus | null
  from_label: string | null
  to_status: MatterStatus
  to_label: string
  reason: string | null
  changed_by?: UserRef | null
  created_at: string
}

export interface DeadlineEvent {
  id: number
  event_type: string
  payload: Record<string, unknown> | null
  user?: UserRef | null
  created_at: string
}

export interface Deadline {
  id: number
  matter_id: number
  kind: DeadlineKind
  title: string
  progress: 'todo' | 'in_progress' | 'review'
  priority: 'low' | 'normal' | 'high' | 'urgent'
  trigger_date: string | null
  due_date: string
  due_time: string | null
  location: string | null
  status: DeadlineStatus
  notes: string | null
  /** Tasks only: finishing one creates the next. */
  repeat: 'weekly' | 'monthly' | 'quarterly' | 'yearly' | null
  repeat_until: string | null
  next_task_id: number | null
  days_remaining: number
  completed_at: string | null
  rule?: { id: number; name: string; legal_basis: string | null } | null
  assignee?: UserRef | null
  matter?: MatterRef & { case_number: string | null }
  events?: DeadlineEvent[]
}

export interface DeadlineRule {
  id: number
  name: string
  trigger_event: string
  period_days: number
  period_type: 'calendar' | 'working_days'
  legal_basis: string | null
  notes: string | null
  is_active: boolean
  is_system: boolean
}

export interface DeadlineComputation {
  due_date: string
  nominal_date: string
  adjustments: { date: string; reason: string }[]
}

export interface Holiday {
  id: number
  date: string
  name: string
  type: 'regular' | 'special_non_working' | 'court_closure'
}

export interface WorkflowTemplate {
  id: number
  case_type: string
  name: string
  tasks: { title: string; days_offset: number; kind?: DeadlineKind | null }[]
  is_active: boolean
}

export interface DocumentTemplate {
  id: number
  name: string
  category: string | null
  body: string
  merge_fields: string[]
  updated_at: string
}

export interface DocumentVersion {
  id: number
  version_number: number
  content: string
  change_summary: string | null
  creator?: UserRef | null
  created_at: string
}

export interface LegalDocument {
  id: number
  title: string
  status: DocumentStatus
  is_editable: boolean
  current_version: number
  shared_with_client: boolean
  matter?: MatterRef
  template?: { id: number; name: string } | null
  creator?: UserRef | null
  latest_version?: DocumentVersion | null
  created_at: string
  updated_at: string
}

export interface NotarialEntry {
  id: number
  doc_number: number
  page_number: number
  book_number: number
  series_year: number
  act_type: string
  document_title: string
  principal_name: string
  competent_evidence: string | null
  fee_cents: number
  notarized_at: string
  notary?: UserRef
  matter?: MatterRef | null
}

export interface TrustAccount {
  id: number
  account_number: string
  balance_cents: number
  minimum_balance_cents?: number | null
  below_minimum?: boolean
  replenishment_requested_at?: string | null
  status: 'open' | 'closed'
  client?: { id: number; name: string }
  matter?: MatterRef | null
  created_at: string
}

export interface TrustTransaction {
  id: number
  type: 'deposit' | 'disbursement'
  amount_cents: number
  signed_amount_cents: number
  balance_after_cents: number
  reference: string | null
  description: string
  creator?: UserRef | null
  created_at: string
}

export interface TimeEntry {
  id: number
  work_date: string
  minutes: number
  rate_cents: number
  amount_cents: number
  description: string
  is_billable: boolean
  invoice_id: number | null
  is_invoiced: boolean
  user?: UserRef
  matter?: MatterRef
  created_at: string
}

export interface InvoiceLine {
  id: number
  kind: 'time' | 'expense' | 'fee'
  work_date: string | null
  description: string
  minutes: number | null
  rate_cents: number | null
  amount_cents: number
  /** Set when the line was written down before issue. */
  original_amount_cents: number | null
  adjustment_reason: string | null
}

export interface Invoice {
  id: number
  number: string
  status: InvoiceStatus
  is_overdue: boolean
  subtotal_cents: number
  vat_cents: number
  expenses_cents: number
  total_cents: number
  /** Cash received plus tax withheld, across active payments. */
  settled_cents: number
  withholding_cents: number
  balance_cents: number
  /** Fees (before VAT) on which tax can still be withheld. */
  withholding_room_cents: number
  /** Off professional fees, before VAT; subtotal_cents is after it. */
  discount_cents: number
  discount_reason: string | null
  written_off_cents: number
  written_off_at: string | null
  write_off_reason: string | null
  written_off_by?: string | null
  issued_at: string | null
  due_at: string | null
  paid_at: string | null
  payment_reference: string | null
  notes: string | null
  client?: { id: number; name: string }
  matter?: MatterRef
  lines?: InvoiceLine[]
  can_pay_online: boolean
  payments?: OnlinePayment[]
  invoice_payments?: InvoicePayment[]
  reminders_paused_at?: string | null
  reminders?: { stage: string; label: string; balance_cents: number; sent_by: string | null; sent_at: string }[]
  created_at: string
}

export type PaymentMethod = 'cash' | 'check' | 'bank_transfer' | 'e_wallet' | 'card' | 'online' | 'trust' | 'other'

/** Money received against an invoice, plus tax the client withheld (evidenced by BIR Form 2307). */
export interface InvoicePayment {
  id: number
  invoice_id: number
  received_on: string
  method: PaymentMethod
  amount_cents: number
  withholding_cents: number
  credited_cents: number
  reference: string | null
  notes: string | null
  form_2307_received_at: string | null
  form_2307_file_id: number | null
  is_trust: boolean
  is_online: boolean
  recorded_by?: string | null
  voided_at: string | null
  void_reason: string | null
  invoice?: { id: number; number: string; client: string | null; matter_id: number }
  created_at: string
}

export interface OnlinePayment {
  id: number
  provider: string
  /** `unapplied`: money received after the invoice was paid or voided; needs a refund. */
  status: 'pending' | 'paid' | 'unapplied'
  /** A refund through PayMongo of an unapplied payment. */
  refund: { id: string; status: string; reason: string | null; at: string | null } | null
  method: string | null
  amount_cents: number
  reference: string | null
  paid_at: string | null
  created_at: string
}

export interface MatterFile {
  id: number
  matter_id: number
  name: string
  mime_type: string
  size_bytes: number
  sha256: string
  description: string | null
  shared_with_client: boolean
  scan_status: 'clean' | 'not_scanned'
  text_status: 'pending' | 'extracted' | 'unsupported' | 'failed'
  text_source?: 'text' | 'ocr' | null
  /** Search results only: text around the hits, marked ⟦like this⟧. */
  snippet?: string
  matter?: MatterRef
  uploader?: UserRef | null
  created_at: string
}

export interface SignatureRequest {
  id: number
  document_id: number
  status: 'pending' | 'signed' | 'declined' | 'cancelled' | 'expired'
  message: string | null
  version_number?: number
  content_sha256: string
  expires_at: string | null
  client?: { id: number; name: string; email: string | null }
  requester?: UserRef | null
  responded_at: string | null
  signer_name: string | null
  signature_method: 'drawn' | 'typed' | null
  signature_image: string | null
  signer_ip: string | null
  signer_user_agent: string | null
  decline_reason: string | null
  created_at: string
}

export interface ConflictMatch {
  source: 'client' | 'party'
  id: number
  name: string
  relationship: string
  is_adverse: boolean
  matter_id: number | null
  matter_reference: string | null
  matter_title: string | null
  /** How close the names are (60-100) and why they matched; absent on older checks. */
  score?: number
  reason?: string
}

export interface ConflictCheck {
  id: number
  search_term: string
  status: ConflictStatus
  match_count: number
  matches: ConflictMatch[]
  requester?: UserRef | null
  resolver?: UserRef | null
  resolved_at: string | null
  resolution_notes: string | null
  created_at: string
}

export interface McleStatus {
  period_id: number
  period_name: string
  period_end: string
  required_units: number
  earned_units: number
  remaining_units: number
  is_compliant: boolean
  percent: number
}

export interface McleCredit {
  id: number
  title: string
  provider: string | null
  subject_area: string | null
  units: string
  date_earned: string
  certificate_number: string | null
}

export interface McleCompliancePeriod {
  id: number
  name: string
  start_date: string
  end_date: string
  required_units: number
}

export interface Dashboard {
  as_of: string
  metrics: {
    active_matters: number
    new_matters_this_month: number
    revenue_collected_ytd_cents: number
    billed_ytd_cents: number
    collection_rate: number | null
    outstanding_receivables_cents: number
    overdue_receivables_cents: number
    unbilled_wip_cents: number
    trust_funds_held_cents: number
    deadlines_next_7_days: number
    missed_deadlines_30_days: number
  }
  matters_by_status: { status: MatterStatus; label: string; count: number }[]
  revenue_trend: { month: string; label: string; collected_cents: number }[]
  utilization: { user_id: number; name: string; billable_minutes: number; target_minutes_to_date: number; utilization_percent: number }[]
}

export interface AuditEntry {
  id: number
  action: string
  actor: { type: string; id: number; name: string } | null
  subject_type: string | null
  subject_id: number | null
  changes: Record<string, unknown> | null
  ip_address: string | null
  created_at: string
}

export interface Expense {
  id: number
  matter_id: number
  expense_date: string
  category: string
  category_label: string
  description: string
  amount_cents: number
  is_billable: boolean
  is_invoiced: boolean
  invoice_id: number | null
  receipt?: { id: number; name: string } | null
  user?: UserRef
  matter?: MatterRef
  created_at: string
}
