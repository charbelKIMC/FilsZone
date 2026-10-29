import { CONFIG } from '../config.js';

export class ApiError extends Error {
    constructor(message, status) {
        super(message);
        this.status = status;
    }
}

// Appel JSON à l'API avec le cookie de session.
// Un 401 déclenche l'événement "api:unauthorized" : chaque page décide quoi en faire (retour à la connexion).
export async function api(path, { method = 'GET', body, query, signal } = {}) {
    const url = new URL(CONFIG.apiBase + path, location.origin);
    for (const [key, value] of Object.entries(query ?? {})) {
        if (value !== '' && value != null) url.searchParams.set(key, value);
    }

    let res;
    try {
        res = await fetch(url, {
            method,
            credentials: 'include',
            headers: body ? { 'Content-Type': 'application/json' } : {},
            body: body ? JSON.stringify(body) : undefined,
            signal,
        });
    } catch (e) {
        if (e.name === 'AbortError') throw e;
        throw new ApiError('Serveur injoignable. Vérifiez votre connexion puis réessayez.', 0);
    }

    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
        if (res.status === 401) document.dispatchEvent(new CustomEvent('api:unauthorized', { detail: { path } }));
        throw new ApiError(data.error || `Erreur ${res.status}`, res.status);
    }
    return data;
}

export const get = (path, query) => api(path, { query });
export const post = (path, body) => api(path, { method: 'POST', body });
export const patch = (path, body) => api(path, { method: 'PATCH', body });
export const del = (path) => api(path, { method: 'DELETE' });
