import React from 'react';

export const FirmDashboard = () => {
    // Dummy data from the Materialized View
    const data = {
        total_matters: 124,
        active_matters: 42,
        total_billed: 850000000 // 8,500,000 PHP
    };
    
    const formatCurrency = (cents: number) => {
        return new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(cents / 100);
    };

    return (
        <div className="bg-white p-6 rounded-xl shadow mb-8">
            <h2 className="text-xl font-bold text-slate-800 mb-4">Firm Performance Dashboard</h2>
            <p className="text-sm text-slate-500 mb-6">Aggregated via PostgreSQL Materialized View</p>

            <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div className="p-6 bg-indigo-50 rounded-xl">
                    <h3 className="text-sm font-semibold text-indigo-800 uppercase tracking-wide">Total Matters</h3>
                    <p className="text-4xl font-black text-indigo-900 mt-2">{data.total_matters}</p>
                </div>
                
                <div className="p-6 bg-emerald-50 rounded-xl">
                    <h3 className="text-sm font-semibold text-emerald-800 uppercase tracking-wide">Active Matters</h3>
                    <p className="text-4xl font-black text-emerald-900 mt-2">{data.active_matters}</p>
                </div>
                
                <div className="p-6 bg-amber-50 rounded-xl">
                    <h3 className="text-sm font-semibold text-amber-800 uppercase tracking-wide">Total Billed</h3>
                    <p className="text-4xl font-black text-amber-900 mt-2">{formatCurrency(data.total_billed)}</p>
                </div>
            </div>
        </div>
    );
};
