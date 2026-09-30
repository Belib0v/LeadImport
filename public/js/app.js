import {importFile} from './api.js';

const elements = {
    form: document.getElementById('upload-form'),
    fileInput: document.getElementById('file'),
    submitButton: document.getElementById('submit'),
    result: document.getElementById('result'),
};

/**
 * @param {string} text
 * @param {'info'|'success'|'error'} type
 */
function showResult(text, type) {
    elements.result.textContent = text;
    elements.result.dataset.type = type;
    elements.result.hidden = false;
}

function setBusy(isBusy) {
    elements.submitButton.disabled = isBusy;
    elements.fileInput.disabled = isBusy;
}

elements.form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const file = elements.fileInput.files[0];
    if (!file) {
        return;
    }

    const maxSizeMb = Number(elements.fileInput.dataset.maxSizeMb);
    if (file.size > maxSizeMb * 1024 * 1024) {
        const sizeMb = (file.size / 1024 / 1024).toFixed(1);
        showResult(`The file is too large: ${sizeMb} MB, the limit is ${maxSizeMb} MB.`, 'error');
        return;
    }

    setBusy(true);
    showResult(`Importing "${file.name}"…`, 'info');
    try {
        const {imported, duplicates} = await importFile(file);
        showResult(`Done: ${imported} rows imported, ${duplicates} duplicates skipped.`, 'success');
    } catch (error) {
        showResult(`Error: ${error.message}`, 'error');
    } finally {
        setBusy(false);
    }
});
