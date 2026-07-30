import React from 'react';

export const MCLETracker = () => {
    // Dummy data
    const required = 36;
    const earned = 30;
    const remaining = required - earned;
    const periodName = '8th Compliance Period (2025-2028)';

    return (
        <div className="bg-white p-6 rounded-xl shadow mb-8">
            <h2 className="text-xl font-bold text-slate-800 mb-2">MCLE Tracker</h2>
            <p className="text-slate-500 mb-6">{periodName}</p>
            
            <div className="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                <div className="p-4 bg-slate-50 border border-slate-100 rounded-lg text-center">
                    <p className="text-sm font-medium text-slate-500 uppercase tracking-wide">Required</p>
                    <p className="text-3xl font-bold text-slate-800 mt-2">{required}</p>
                </div>
                <div className="p-4 bg-blue-50 border border-blue-100 rounded-lg text-center">
                    <p className="text-sm font-medium text-blue-600 uppercase tracking-wide">Earned</p>
                    <p className="text-3xl font-bold text-blue-700 mt-2">{earned}</p>
                </div>
                <div className="p-4 bg-amber-50 border border-amber-100 rounded-lg text-center">
                    <p className="text-sm font-medium text-amber-600 uppercase tracking-wide">Remaining</p>
                    <p className="text-3xl font-bold text-amber-700 mt-2">{remaining}</p>
                </div>
            </div>

            <div className="w-full bg-slate-200 rounded-full h-4 mb-2">
                <div className="bg-blue-600 h-4 rounded-full" style={{ width: `${(earned / required) * 100}%` }}></div>
            </div>
            <p className="text-sm text-slate-500 text-right">{Math.round((earned / required) * 100)}% Complete</p>
        </div>
    );
};
