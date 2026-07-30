import React, { useState } from 'react';

export const TemplatePicker = () => {
    const [selectedTemplate, setSelectedTemplate] = useState<string | null>(null);

    // Dummy data for templates
    const templates = [
        { id: '1', name: 'Entry of Appearance', mergeFields: ['client_name', 'court_branch'] },
        { id: '2', name: 'Motion for Extension of Time', mergeFields: ['client_name', 'due_date'] },
        { id: '3', name: 'Retainer Agreement', mergeFields: ['client_name', 'fee_amount'] }
    ];

    return (
        <div className="bg-white p-6 rounded-xl shadow">
            <h2 className="text-xl font-bold text-slate-800 mb-4">Generate Document from Template</h2>
            <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                {templates.map(tpl => (
                    <div 
                        key={tpl.id} 
                        className={`p-4 border rounded-lg cursor-pointer transition-colors ${selectedTemplate === tpl.id ? 'border-blue-500 bg-blue-50' : 'border-slate-200 hover:border-blue-300'}`}
                        onClick={() => setSelectedTemplate(tpl.id)}
                    >
                        <h3 className="font-semibold text-slate-900">{tpl.name}</h3>
                        <p className="text-sm text-slate-500 mt-2">Requires: {tpl.mergeFields.join(', ')}</p>
                    </div>
                ))}
            </div>
            
            {selectedTemplate && (
                <div className="mt-6">
                    <button className="bg-blue-600 text-white px-4 py-2 rounded-lg font-medium hover:bg-blue-700">
                        Generate Draft
                    </button>
                </div>
            )}
        </div>
    );
};
