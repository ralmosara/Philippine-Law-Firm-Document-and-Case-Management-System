import React, { useState } from 'react';

export const ConflictCheckForm = () => {
    const [searchQuery, setSearchQuery] = useState('');
    const [hasSearched, setHasSearched] = useState(false);

    // Dummy results
    const results = [
        { id: 1, name: 'Dela Cruz, Juan', type: 'Client', matter: null },
        { id: 2, name: 'Dela Cruz, Pedro', type: 'Adverse Party', matter: 'Estate of J. Dela Cruz' },
    ];

    const handleSearch = (e: React.FormEvent) => {
        e.preventDefault();
        setHasSearched(true);
    };

    return (
        <div className="bg-white p-6 rounded-xl shadow mb-8">
            <h2 className="text-xl font-bold text-slate-800 mb-4">Conflict of Interest Check</h2>
            
            <form onSubmit={handleSearch} className="flex gap-4 mb-6">
                <input 
                    type="text" 
                    value={searchQuery}
                    onChange={(e) => setSearchQuery(e.target.value)}
                    className="flex-1 p-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                    placeholder="Search client or party name..."
                    required
                />
                <button type="submit" className="bg-blue-600 text-white px-6 py-2 rounded-lg font-medium hover:bg-blue-700">
                    Search
                </button>
            </form>

            {hasSearched && (
                <div>
                    <h3 className="text-lg font-semibold text-slate-700 mb-3">Potential Matches</h3>
                    <div className="border border-slate-200 rounded-lg overflow-hidden">
                        <table className="min-w-full divide-y divide-slate-200">
                            <thead className="bg-slate-50">
                                <tr>
                                    <th className="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase">Name</th>
                                    <th className="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase">Type</th>
                                    <th className="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase">Related Matter</th>
                                </tr>
                            </thead>
                            <tbody className="bg-white divide-y divide-slate-200">
                                {results.map((r) => (
                                    <tr key={r.id}>
                                        <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-slate-900">{r.name}</td>
                                        <td className="px-6 py-4 whitespace-nowrap text-sm text-slate-500">
                                            <span className={`px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full ${r.type === 'Client' ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800'}`}>
                                                {r.type}
                                            </span>
                                        </td>
                                        <td className="px-6 py-4 whitespace-nowrap text-sm text-slate-500">{r.matter || 'N/A'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}
        </div>
    );
};
