import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom'
import ShareViewer from './ShareViewer'
import './share.css'

createRoot(document.getElementById('root')).render(
  <StrictMode>
    <BrowserRouter>
      <Routes>
        <Route path="/s/:tokenHash" element={<ShareViewer />} />
        {/* On the share-only host any other path is a typo or stray
            crawler; redirect to a friendly 404-feel rather than the
            full landing. */}
        <Route path="*" element={<Navigate to="/s/invalid" replace />} />
      </Routes>
    </BrowserRouter>
  </StrictMode>,
)
