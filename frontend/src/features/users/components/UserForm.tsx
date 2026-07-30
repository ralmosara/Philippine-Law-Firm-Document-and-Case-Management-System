import React, { useState } from 'react';

export const UserForm = () => {
    const [name, setName] = useState('');
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        alert(`Saved User: ${name}`);
        setName('');
        setEmail('');
        setPassword('');
    };

    return (
        <div className="bg-white p-6 rounded-xl shadow w-full max-w-md">
            <h2 className="text-xl font-bold text-slate-800 mb-4">User Form</h2>
            
            <form onSubmit={handleSubmit} className="space-y-4">
                <div>
                    <label className="block text-sm font-medium text-slate-700 mb-1">Name</label>
                    <input 
                        type="text" 
                        value={name}
                        onChange={(e) => setName(e.target.value)}
                        className="w-full p-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                        placeholder="e.g. Atty. Santos"
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
                        placeholder="santos@lawfirm.com"
                        required
                    />
                </div>
                
                <div>
                    <label className="block text-sm font-medium text-slate-700 mb-1">Password</label>
                    <input 
                        type="password" 
                        value={password}
                        onChange={(e) => setPassword(e.target.value)}
                        className="w-full p-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                        placeholder="********"
                        required
                    />
                </div>
                
                <button type="submit" className="w-full bg-blue-600 text-white py-2 rounded-lg font-medium hover:bg-blue-700">
                    Save User
                </button>
            </form>
        </div>
    );
};
