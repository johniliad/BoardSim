import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import './styles.css';
import './components.css';
import './layout.css';
import App from './App';

const container = document.getElementById('root');

if (container === null) {
  throw new Error('Root container missing');
}

createRoot(container).render(
  <StrictMode>
    <App />
  </StrictMode>,
);
