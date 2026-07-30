import React, { useState } from 'react';

export const TimeTrackingForm = () => {
    const [duration, setDuration] = useState('');
    const [description, setDescription] = useState('');

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        alert(`Logged ${duration} minutes: ${description}`);
        setDuration('');
        setDescription('');
    };

    return (
        <div className="bg-white p-6 rounded-xl shadow w-full max-w-md">
            <h2 className="text-xl font-bold text-slate-800 mb-4">Log Billable Time</h2>
            
            <form onSubmit={handleSubmit} className="space-y-4">
                <div>
                    <label className="block text-sm font-medium text-slate-700 mb-1">Duration (minutes)</label>
                    <input 
                        type="number" 
                        min="1"
                        value={duration}
                        onChange={(e) => setDuration(e.target.value)}
                        className="w-full p-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                        placeholder="e.g. 60"
                        required
                    />
                </div>
                
                <div>
                    <label className="block text-sm font-medium text-slate-700 mb-1">Description of Work</label>
                    <textarea 
                        value={description}
                        onChange={(e) => setDescription(e.target.value)}
                        className="w-full p-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 h-24"
                        placeholder="Drafted answer to complaint..."
                        required
                    />
                </div>
                
                <button type="submit" className="w-full bg-blue-600 text-white py-2 rounded-lg font-medium hover:bg-blue-700">
                    Save Time Entry
                </button>
            </form>
        </div>
    );
};
