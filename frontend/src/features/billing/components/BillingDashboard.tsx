import React from 'react';
import { TrustLedgerDashboard } from '../../trust/components/TrustLedgerDashboard';
import { TimeTrackingForm } from './TimeTrackingForm';

export const BillingDashboard = () => {
    return (
        <div className="min-h-screen p-8 bg-slate-50">
            <h1 className="text-3xl font-bold text-slate-900 mb-8">Billing & Trust Accounting</h1>
            
            <TrustLedgerDashboard />
            
            <div className="grid grid-cols-1 md:grid-cols-2 gap-8">
                <TimeTrackingForm />
                
                {/* Placeholder for Invoice generation */}
                <div className="bg-white p-6 rounded-xl shadow w-full">
                    <h2 className="text-xl font-bold text-slate-800 mb-4">Invoicing</h2>
                    <p className="text-slate-500 mb-4">Generate an invoice from unbilled time entries and expenses.</p>
                    <button className="w-full bg-slate-800 text-white py-2 rounded-lg font-medium hover:bg-slate-900">
                        Create New Invoice
                    </button>
                </div>
            </div>
        </div>
    );
};
