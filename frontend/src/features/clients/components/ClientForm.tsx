import React, { useState } from 'react';

export const ClientForm = () => {
    const [name, setName] = useState('');
    const [email, setEmail] = useState('');

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        alert(`Saved Client: ${name}`);
        setName('');
        setEmail('');
    };

    return (
        <div className="bg-white p-6 rounded-xl shadow w-full max-w-md">
            <h2 className="text-xl font-bold text-slate-800 mb-4">Client Form</h2>
            
            <form onSubmit={handleSubmit} className="space-y-4">
                <div>
                    <label className="block text-sm font-medium text-slate-700 mb-1">Name</label>
                    <input 
                        type="text" 
                        value={name}
                        onChange={(e) => setName(e.target.value)}
                        className="w-full p-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                        placeholder="e.g. Dela Cruz, Juan"
                        required
                    />
                </div>
                
                <div>
                    <label className="block text-sm font-medium text-slate-700 mb-1">Email</label>
                    <input 
                        type="email" 
                        value={email}
                        onChange={(e) => setEmail(e.target.value)}
                        className="w-full p-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                        placeholder="juan@example.com"
                    />
                </div>
                
                <button type="submit" className="w-full bg-blue-600 text-white py-2 rounded-lg font-medium hover:bg-blue-700">
                    Save Client
                </button>
            </form>
        </div>
    );
};
