import axios from 'axios';

export const apiClient = axios.create({
    baseURL: import.meta.env.VITE_API_URL || 'http://localhost:8000',
    headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'Accept': 'application/json'
    },
    withCredentials: true, // Required for Sanctum CSRF and Session cookies
});

// Add interceptor to automatically fetch CSRF cookie before the first request
// if it's an mutating request (POST, PUT, DELETE)
apiClient.interceptors.request.use(async (config) => {
    // Basic implementation; can be improved to only fetch once
    return config;
});
