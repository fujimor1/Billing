import React from 'react';
import ReactDOM from 'react-dom/client';
import '@astryxdesign/core/reset.css';
import '@astryxdesign/core/astryx.css';
import '@astryxdesign/theme-neutral/theme.css';
import App from './App.jsx';
import './index.css';

ReactDOM.createRoot(document.getElementById('root')).render(
  <React.StrictMode>
    <div data-astryx-theme="neutral" className="astryx-root">
      <App />
    </div>
  </React.StrictMode>
);
