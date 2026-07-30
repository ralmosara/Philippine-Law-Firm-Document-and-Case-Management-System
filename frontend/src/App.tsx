import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { LoginForm } from './features/auth/components/LoginForm';
import { DeadlineCalendar } from './features/deadlines/components/DeadlineCalendar';
import { DocumentsDashboard } from './features/documents/components/DocumentsDashboard';
import { BillingDashboard } from './features/billing/components/BillingDashboard';
import { ComplianceDashboard } from './features/compliance/components/ComplianceDashboard';
import { ClientPortalDashboard } from './features/client-portal/components/ClientPortalDashboard';
import { ClientsList } from './features/clients/components/ClientsList';
import { ClientForm } from './features/clients/components/ClientForm';
import { MattersList } from './features/matters/components/MattersList';
import { MatterForm } from './features/matters/components/MatterForm';
import { UsersList } from './features/users/components/UsersList';
import { UserForm } from './features/users/components/UserForm';

function App() {
  return (
    <BrowserRouter>
      <Routes>
        <Route path="/login" element={<LoginForm />} />
        <Route path="/calendar" element={<DeadlineCalendar />} />
        <Route path="/documents" element={<DocumentsDashboard />} />
        <Route path="/billing" element={<BillingDashboard />} />
        <Route path="/compliance" element={<ComplianceDashboard />} />
        <Route path="/client-portal" element={<ClientPortalDashboard />} />
        
        {/* CRUD Routes */}
        <Route path="/clients" element={<div className="min-h-screen bg-slate-100 p-8"><ClientsList /><ClientForm /></div>} />
        <Route path="/matters" element={<div className="min-h-screen bg-slate-100 p-8"><MattersList /><MatterForm /></div>} />
        <Route path="/users" element={<div className="min-h-screen bg-slate-100 p-8"><UsersList /><UserForm /></div>} />
        
        {/* Placeholder for protected routes later */}
        <Route path="*" element={<Navigate to="/clients" replace />} />
      </Routes>
    </BrowserRouter>
  )
}

export default App
