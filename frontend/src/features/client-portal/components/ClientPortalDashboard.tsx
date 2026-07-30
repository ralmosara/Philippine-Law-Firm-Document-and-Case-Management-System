import React from 'react';

export const ClientPortalDashboard = () => {
    // Dummy Data
    const clientName = "Dela Cruz, Juan";
    const matters = [
        { id: 1, title: 'Estate Settlement of J. Dela Cruz', status: 'Active', next_deadline: '2026-08-15', trust_balance: 5000000 }
    ];
    
    const documents = [
        { id: 1, title: 'Petition for Letters of Administration', status: 'Filed', date: '2026-07-20' },
        { id: 2, title: 'Notice of Hearing', status: 'Pending', date: '2026-08-15' }
    ];
    
    const formatCurrency = (cents: number) => {
        return new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(cents / 100);
    };

    return (
        <div className="min-h-screen bg-slate-100 font-sans">
            <header className="bg-slate-900 text-white shadow-lg">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 flex justify-between items-center">
                    <h1 className="text-2xl font-bold tracking-tight">Client Portal</h1>
                    <div className="flex items-center gap-4">
                        <span className="text-slate-300">Welcome, {clientName}</span>
                        <button className="bg-slate-800 hover:bg-slate-700 px-4 py-2 rounded text-sm font-medium transition-colors">
                            Sign Out
                        </button>
                    </div>
                </div>
            </header>
            
            <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-8">
                
                {/* Active Matters Section */}
                <section>
                    <h2 className="text-xl font-bold text-slate-800 mb-4">Your Active Cases</h2>
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                        {matters.map((m) => (
                            <div key={m.id} className="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden hover:shadow-md transition-shadow">
                                <div className="p-6">
                                    <div className="flex justify-between items-start mb-4">
                                        <h3 className="text-lg font-bold text-slate-900">{m.title}</h3>
                                        <span className="bg-emerald-100 text-emerald-800 px-3 py-1 rounded-full text-xs font-semibold uppercase tracking-wide">
                                            {m.status}
                                        </span>
                                    </div>
                                    <div className="space-y-3">
                                        <div className="flex justify-between text-sm">
                                            <span className="text-slate-500">Next Deadline:</span>
                                            <span className="font-medium text-rose-600">{m.next_deadline}</span>
                                        </div>
                                        <div className="flex justify-between text-sm">
                                            <span className="text-slate-500">Trust Account Balance:</span>
                                            <span className="font-bold text-emerald-600">{formatCurrency(m.trust_balance)}</span>
                                        </div>
                                    </div>
                                </div>
                                <div className="bg-slate-50 px-6 py-3 border-t border-slate-100">
                                    <button className="text-blue-600 text-sm font-medium hover:text-blue-800">
                                        View Full Details &rarr;
                                    </button>
                                </div>
                            </div>
                        ))}
                    </div>
                </section>
                
                {/* Shared Documents Section */}
                <section>
                    <h2 className="text-xl font-bold text-slate-800 mb-4">Shared Documents</h2>
                    <div className="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
                        <table className="min-w-full divide-y divide-slate-200">
                            <thead className="bg-slate-50">
                                <tr>
                                    <th className="px-6 py-4 text-left text-xs font-bold text-slate-500 uppercase tracking-wider">Document Title</th>
                                    <th className="px-6 py-4 text-left text-xs font-bold text-slate-500 uppercase tracking-wider">Date</th>
                                    <th className="px-6 py-4 text-left text-xs font-bold text-slate-500 uppercase tracking-wider">Status</th>
                                    <th className="px-6 py-4 text-right text-xs font-bold text-slate-500 uppercase tracking-wider">Action</th>
                                </tr>
                            </thead>
                            <tbody className="bg-white divide-y divide-slate-200">
                                {documents.map((doc) => (
                                    <tr key={doc.id} className="hover:bg-slate-50 transition-colors">
                                        <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-slate-900">{doc.title}</td>
                                        <td className="px-6 py-4 whitespace-nowrap text-sm text-slate-500">{doc.date}</td>
                                        <td className="px-6 py-4 whitespace-nowrap text-sm">
                                            <span className={`px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full ${
                                                doc.status === 'Filed' ? 'bg-blue-100 text-blue-800' : 'bg-amber-100 text-amber-800'
                                            }`}>
                                                {doc.status}
                                            </span>
                                        </td>
                                        <td className="px-6 py-4 whitespace-nowrap text-sm text-right font-medium">
                                            <button className="text-indigo-600 hover:text-indigo-900 font-semibold">Download</button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>

            </main>
        </div>
    );
};
