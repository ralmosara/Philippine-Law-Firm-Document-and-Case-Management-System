import React from 'react';
import { TemplatePicker } from './TemplatePicker';
import { DocumentEditor } from './DocumentEditor';
import { DocumentDiffViewer } from './DocumentDiffViewer';
import { NotarialRegister } from './NotarialRegister';

export const DocumentsDashboard = () => {
    return (
        <div className="min-h-screen p-8 bg-slate-50">
            <h1 className="text-3xl font-bold text-slate-900 mb-8">Documents & Notarial Register</h1>
            
            <TemplatePicker />
            
            <DocumentEditor />

            <DocumentDiffViewer 
                oldValue="<p>Dear Juan Dela Cruz, your hearing is on October 10, 2026.</p>" 
                newValue="<p>Dear Juan Dela Cruz, your hearing is on <strong>October 15, 2026</strong>. Please be advised.</p>"
            />

            <NotarialRegister />
        </div>
    );
};
