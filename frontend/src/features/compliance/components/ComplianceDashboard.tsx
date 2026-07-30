import React from 'react';
import { ConflictCheckForm } from './ConflictCheckForm';
import { MCLETracker } from './MCLETracker';
import { FirmDashboard } from './FirmDashboard';

export const ComplianceDashboard = () => {
    return (
        <div className="min-h-screen p-8 bg-slate-50">
            <h1 className="text-3xl font-bold text-slate-900 mb-8">Compliance & Firm Reporting</h1>
            
            <FirmDashboard />
            
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-8">
                <ConflictCheckForm />
                <MCLETracker />
            </div>
        </div>
    );
};
