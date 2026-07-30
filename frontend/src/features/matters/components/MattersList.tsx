import React, { useState, useEffect } from 'react';

export const MattersList = () => {
    const [matters, setMatters] = useState<any[]>([]);

    useEffect(() => {
        // Mock fetch
        setMatters([
            { id: 1, case_number: '2026-001', case_type: 'Civil', client_name: 'Dela Cruz, Juan', status: 'Active' },
            { id: 2, case_number: '2026-002', case_type: 'Corporate', client_name: 'Acme Corp', status: 'Pending' }
        ]);
    }, []);

    return (
        <div className="bg-white p-6 rounded-xl shadow mb-8">
            <div className="flex justify-between items-center mb-6">
                <h2 className="text-xl font-bold text-slate-800">Matters</h2>
                <button className="bg-blue-600 text-white px-4 py-2 rounded-lg font-medium hover:bg-blue-700">
                    + Open Matter
                </button>
            </div>
            
            <div className="overflow-x-auto border border-slate-200 rounded-lg">
                <table className="min-w-full divide-y divide-slate-200">
                    <thead className="bg-slate-50">
                        <tr>
                            <th className="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Case Number</th>
                            <th className="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Type</th>
                            <th className="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Client</th>
                            <th className="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Status</th>
                            <th className="px-6 py-3 text-right text-xs font-medium text-slate-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody className="bg-white divide-y divide-slate-200">
                        {matters.map((m) => (
                            <tr key={m.id}>
                                <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-slate-900">{m.case_number}</td>
                                <td className="px-6 py-4 whitespace-nowrap text-sm text-slate-500">{m.case_type}</td>
                                <td className="px-6 py-4 whitespace-nowrap text-sm text-slate-900">{m.client_name}</td>
                                <td className="px-6 py-4 whitespace-nowrap text-sm">
                                    <span className="bg-emerald-100 text-emerald-800 px-2 py-1 rounded-full text-xs font-semibold uppercase">
                                        {m.status}
                                    </span>
                                </td>
                                <td className="px-6 py-4 whitespace-nowrap text-sm text-right font-medium">
                                    <button className="text-blue-600 hover:text-blue-900 mr-3">Edit</button>
                                    <button className="text-rose-600 hover:text-rose-900">Delete</button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
};
