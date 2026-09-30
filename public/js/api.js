/** Calls to the import API (api/import.php). */

const API_URL = 'api/import.php'; // relative to the page, not to this script

/**
 * Uploads an .xlsx file and imports it.
 * Returns {imported, duplicates} (duplicates are rows already in the database)
 * or throws an Error with the server's message.
 */
export async function importFile(file) {
    const formData = new FormData();
    formData.append('file', file);

    const response = await fetch(API_URL, {method: 'POST', body: formData});
    const data = await response.json().catch(() => ({}));

    if (!response.ok || data.error) {
        throw new Error(data.error || `HTTP ${response.status}`);
    }
    return data;
}
