import React from 'react';

export const NotarialRegister = () => {
    // Dummy data for Notarial Register
    const entries = [
        { id: 1, doc_number: '12', page_number: '3', book_number: 'I', series_year: 2026, title: 'Affidavit of Merit', notarized_by: 'Atty. Santos', date: '2026-07-29' },
        { id: 2, doc_number: '13', page_number: '3', book_number: 'I', series_year: 2026, title: 'Special Power of Attorney', notarized_by: 'Atty. Santos', date: '2026-07-29' },
    ];

    return (
        <div className="bg-white p-6 rounded-xl shadow mt-6">
            <h2 className="text-xl font-bold text-slate-800 mb-4">Notarial Register</h2>
            
            <div className="overflow-x-auto">
                <table className="min-w-full divide-y divide-slate-200">
                    <thead className="bg-slate-50">
                        <tr>
                            <th className="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Doc No.</th>
                            <th className="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Page No.</th>
                            <th className="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Book No.</th>
                            <th className="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Series Of</th>
                            <th className="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Document Title</th>
                            <th className="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Notary Public</th>
                            <th className="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Date</th>
                        </tr>
                    </thead>
                    <tbody className="bg-white divide-y divide-slate-200">
                        {entries.map((entry) => (
                            <tr key={entry.id}>
                                <td className="px-6 py-4 whitespace-nowrap text-sm text-slate-900">{entry.doc_number}</td>
                                <td className="px-6 py-4 whitespace-nowrap text-sm text-slate-900">{entry.page_number}</td>
                                <td className="px-6 py-4 whitespace-nowrap text-sm text-slate-900">{entry.book_number}</td>
                                <td className="px-6 py-4 whitespace-nowrap text-sm text-slate-900">{entry.series_year}</td>
                                <td className="px-6 py-4 whitespace-nowrap text-sm text-slate-900">{entry.title}</td>
                                <td className="px-6 py-4 whitespace-nowrap text-sm text-slate-900">{entry.notarized_by}</td>
                                <td className="px-6 py-4 whitespace-nowrap text-sm text-slate-900">{entry.date}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
};
