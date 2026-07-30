import React, { useState } from 'react';

export const TrustLedgerDashboard = () => {
    const [balanceCents, setBalanceCents] = useState(5000000); // 50,000 PHP

    // Dummy data
    const transactions = [
        { id: 1, type: 'deposit', amount: 10000000, ref: 'CHECK-1029', date: '2026-07-01', balanceAfter: 10000000 },
        { id: 2, type: 'withdrawal', amount: -5000000, ref: 'INV-2026-001', date: '2026-07-15', balanceAfter: 5000000 },
    ];

    const formatCurrency = (cents: number) => {
        return new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(cents / 100);
    };

    return (
        <div className="bg-white p-6 rounded-xl shadow mb-8">
            <h2 className="text-2xl font-bold text-slate-800 mb-2">Trust Ledger</h2>
            <div className="flex justify-between items-center mb-6">
                <p className="text-slate-500">Matter: Estate of J. Dela Cruz</p>
                <div className="text-right">
                    <p className="text-sm text-slate-500 uppercase tracking-wide font-semibold">Current Balance</p>
                    <p className="text-3xl font-bold text-emerald-600">{formatCurrency(balanceCents)}</p>
                </div>
            </div>

            <div className="overflow-x-auto border border-slate-200 rounded-lg">
                <table className="min-w-full divide-y divide-slate-200">
                    <thead className="bg-slate-50">
                        <tr>
                            <th className="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Date</th>
                            <th className="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Type</th>
                            <th className="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Ref No.</th>
                            <th className="px-6 py-3 text-right text-xs font-medium text-slate-500 uppercase tracking-wider">Amount</th>
                            <th className="px-6 py-3 text-right text-xs font-medium text-slate-500 uppercase tracking-wider">Balance After</th>
                        </tr>
                    </thead>
                    <tbody className="bg-white divide-y divide-slate-200">
                        {transactions.map((tx) => (
                            <tr key={tx.id}>
                                <td className="px-6 py-4 whitespace-nowrap text-sm text-slate-900">{tx.date}</td>
                                <td className="px-6 py-4 whitespace-nowrap text-sm">
                                    <span className={`px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full ${
                                        tx.type === 'deposit' ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800'
                                    }`}>
                                        {tx.type.charAt(0).toUpperCase() + tx.type.slice(1)}
                                    </span>
                                </td>
                                <td className="px-6 py-4 whitespace-nowrap text-sm text-slate-500">{tx.ref}</td>
                                <td className={`px-6 py-4 whitespace-nowrap text-sm text-right font-medium ${tx.amount > 0 ? 'text-emerald-600' : 'text-rose-600'}`}>
                                    {tx.amount > 0 ? '+' : ''}{formatCurrency(tx.amount)}
                                </td>
                                <td className="px-6 py-4 whitespace-nowrap text-sm text-right text-slate-900 font-bold">
                                    {formatCurrency(tx.balanceAfter)}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            
            <div className="mt-6 flex gap-4">
                <button className="bg-emerald-600 text-white px-4 py-2 rounded-lg font-medium hover:bg-emerald-700">Record Deposit</button>
                <button className="bg-rose-600 text-white px-4 py-2 rounded-lg font-medium hover:bg-rose-700">Record Withdrawal</button>
            </div>
        </div>
    );
};
