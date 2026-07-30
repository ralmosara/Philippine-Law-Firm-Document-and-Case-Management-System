import React from 'react';
import ReactDiffViewer from 'react-diff-viewer-continued';

interface DocumentDiffViewerProps {
    oldValue: string;
    newValue: string;
}

export const DocumentDiffViewer: React.FC<DocumentDiffViewerProps> = ({ oldValue, newValue }) => {
    return (
        <div className="bg-white p-6 rounded-xl shadow mt-6">
            <h2 className="text-xl font-bold text-slate-800 mb-4">Version Changes</h2>
            <div className="border border-slate-200 rounded-lg overflow-hidden">
                <ReactDiffViewer 
                    oldValue={oldValue} 
                    newValue={newValue} 
                    splitView={true} 
                    hideLineNumbers={false}
                    useDarkTheme={false}
                />
            </div>
        </div>
    );
};
