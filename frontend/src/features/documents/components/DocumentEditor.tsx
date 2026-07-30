import React, { useState } from 'react';

export const DocumentEditor = () => {
    const [content, setContent] = useState("<p>Dear Juan Dela Cruz, your hearing is on October 10, 2026.</p>");

    const handleSave = () => {
        // In reality, this would trigger an API call to save a new version
        console.log("Saving new version with content:", content);
        alert("New version saved successfully!");
    };

    return (
        <div className="bg-white p-6 rounded-xl shadow mt-6">
            <h2 className="text-xl font-bold text-slate-800 mb-4">Edit Document</h2>
            
            {/* MVP: Raw text editor. In a real app, use a WYSIWYG like TipTap or Quill */}
            <textarea 
                className="w-full h-64 p-4 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 font-mono text-sm"
                value={content}
                onChange={(e) => setContent(e.target.value)}
            />

            <div className="mt-4 flex justify-end">
                <button 
                    onClick={handleSave}
                    className="bg-emerald-600 text-white px-6 py-2 rounded-lg font-medium hover:bg-emerald-700"
                >
                    Save as New Version
                </button>
            </div>
        </div>
    );
};
