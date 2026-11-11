// The single shared Axios instance lives in ./lib/api.js (CSRF, 401/419/403/422/501 handling).
// Importing it here guarantees it is configured before the app renders.
import './lib/api.js';
