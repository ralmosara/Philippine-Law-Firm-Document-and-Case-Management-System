import React, { useState } from 'react';

export const MatterForm = () => {
    const [caseNumber, setCaseNumber] = useState('');
    const [caseType, setCaseType] = useState('');
    const [status, setStatus] = useState('Active');

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        alert(`Saved Matter: ${caseNumber}`);
        setCaseNumber('');
        setCaseType('');
    };

    return (
        <div className="bg-white p-6 rounded-xl shadow w-full max-w-md">
            <h2 className="text-xl font-bold text-slate-800 mb-4">Matter Form</h2>
            
            <form onSubmit={handleSubmit} className="space-y-4">
                <div>
                    <label className="block text-sm font-medium text-slate-700 mb-1">Case Number</label>
                    <input 
                        type="text" 
                        value={caseNumber}
                        onChange={(e) => setCaseNumber(e.target.value)}
                        className="w-full p-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                        placeholder="e.g. CV-2026-100"
                        required
                    />
                </div>
                
                <div>
                    <label className="block text-sm font-medium text-slate-700 mb-1">Case Type</label>
                    <input 
                        type="text" 
                        value={caseType}
                        onChange={(e) => setCaseType(e.target.value)}
                        className="w-full p-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                        placeholder="e.g. Civil"
                    />
                </div>
                
                <div>
                    <label className="block text-sm font-medium text-slate-700 mb-1">Status</label>
                    <select 
                        value={status}
                        onChange={(e) => setStatus(e.target.value)}
                        className="w-full p-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                    >
                        <option value="Active">Active</option>
                        <option value="Pending">Pending</option>
                        <option value="Closed">Closed</option>
                    </select>
                </div>
                
                <button type="submit" className="w-full bg-blue-600 text-white py-2 rounded-lg font-medium hover:bg-blue-700">
                    Save Matter
                </button>
            </form>
        </div>
    );
};
