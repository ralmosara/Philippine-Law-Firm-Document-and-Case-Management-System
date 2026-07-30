import React, { useState } from 'react';
import { apiClient } from '../../../shared/api/axios';

export const LoginForm = () => {
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const handleLogin = async (e: React.FormEvent) => {
        e.preventDefault();
        setLoading(true);
        setError(null);
        
        try {
            // First, fetch CSRF cookie
            await apiClient.get('/sanctum/csrf-cookie');
            // Then attempt login
            await apiClient.post('/login', { email, password });
            
            // On success, you'd typically refetch the user profile and redirect
            console.log('Login successful');
            // TODO: redirect or update global auth state
        } catch (err: any) {
            setError(err.response?.data?.message || 'Login failed');
        } finally {
            setLoading(false);
        }
    };

    return (
        <div className="min-h-screen flex items-center justify-center bg-slate-50">
            <div className="max-w-md w-full p-6 bg-white rounded-xl shadow-lg">
                <h2 className="text-2xl font-bold text-center text-slate-900 mb-8">Sign in to your account</h2>
                
                {error && (
                    <div className="mb-4 p-3 bg-red-50 text-red-700 rounded-lg text-sm">
                        {error}
                    </div>
                )}

                <form onSubmit={handleLogin} className="space-y-6">
                    <div>
                        <label className="block text-sm font-medium text-slate-700 mb-2">Email address</label>
                        <input 
                            type="email" 
                            required 
                            className="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                            value={email}
                            onChange={e => setEmail(e.target.value)}
                        />
                    </div>
                    
                    <div>
                        <label className="block text-sm font-medium text-slate-700 mb-2">Password</label>
                        <input 
                            type="password" 
                            required 
                            className="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                            value={password}
                            onChange={e => setPassword(e.target.value)}
                        />
                    </div>

                    <button 
                        type="submit" 
                        disabled={loading}
                        className="w-full bg-slate-900 text-white font-medium py-2.5 rounded-lg hover:bg-slate-800 transition-colors disabled:opacity-50"
                    >
                        {loading ? 'Signing in...' : 'Sign in'}
                    </button>
                </form>
            </div>
        </div>
    );
};
