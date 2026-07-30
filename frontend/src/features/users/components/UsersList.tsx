import React, { useState, useEffect } from 'react';

export const UsersList = () => {
    const [users, setUsers] = useState<any[]>([]);

    useEffect(() => {
        // Mock fetch
        setUsers([
            { id: 1, name: 'Atty. Santos', email: 'santos@lawfirm.com' },
            { id: 2, name: 'Atty. Reyes', email: 'reyes@lawfirm.com' }
        ]);
    }, []);

    return (
        <div className="bg-white p-6 rounded-xl shadow mb-8">
            <div className="flex justify-between items-center mb-6">
                <h2 className="text-xl font-bold text-slate-800">Users (Lawyers/Staff)</h2>
                <button className="bg-blue-600 text-white px-4 py-2 rounded-lg font-medium hover:bg-blue-700">
                    + New User
                </button>
            </div>
            
            <div className="overflow-x-auto border border-slate-200 rounded-lg">
                <table className="min-w-full divide-y divide-slate-200">
                    <thead className="bg-slate-50">
                        <tr>
                            <th className="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">ID</th>
                            <th className="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Name</th>
                            <th className="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Email</th>
                            <th className="px-6 py-3 text-right text-xs font-medium text-slate-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody className="bg-white divide-y divide-slate-200">
                        {users.map((u) => (
                            <tr key={u.id}>
                                <td className="px-6 py-4 whitespace-nowrap text-sm text-slate-500">{u.id}</td>
                                <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-slate-900">{u.name}</td>
                                <td className="px-6 py-4 whitespace-nowrap text-sm text-slate-500">{u.email}</td>
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
