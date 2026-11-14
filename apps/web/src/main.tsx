import React from 'react';
import ReactDOM from 'react-dom/client';
import App from './platform.tsx';
import ClientPreview from './ClientPreview.tsx';
import './styles.css';

const root = ReactDOM.createRoot(document.getElementById('root')!);
root.render(
  <React.StrictMode>
    {window.location.pathname === '/client-preview.html' ? <ClientPreview /> : <App />}
  </React.StrictMode>,
);
