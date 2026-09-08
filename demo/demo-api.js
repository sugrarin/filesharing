/*
 * Browser-only data layer for the demo. It mirrors the production API shape
 * and stores the state in localStorage, so the full interface is usable
 * without PHP, SQLite, or an administrator account.
 */
(() => {
    'use strict';

    const STORAGE_KEY = 'file-sharing-demo-v2';
    const ROOT_CATEGORY = 'All files';
    const FILE_ID_CHARACTERS = 'abcdefghijklmnopqrstuvwxyz0123456789';
    const FOLDER_ID_CHARACTERS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

    function clone(value) {
        return JSON.parse(JSON.stringify(value));
    }

    function randomId(characters) {
        let id = '';
        for (let i = 0; i < 5; i++) {
            id += characters[Math.floor(Math.random() * characters.length)];
        }
        return id;
    }

    function defaultState() {
        const now = new Date();
        const yesterday = new Date(now.getTime() - 24 * 60 * 60 * 1000);

        return {
            categories: [
                { name: ROOT_CATEGORY, parent: null },
                { name: 'Product documents', parent: null },
                { name: 'Reference guides', parent: 'Product documents' },
                { name: 'Team updates', parent: null }
            ],
            files: [
                {
                    id: 'guide',
                    name: 'iPhone User Guide.pdf',
                    originalName: 'iPhone User Guide.pdf',
                    extension: 'pdf',
                    size: 3717045,
                    uploadDate: now.toISOString(),
                    modified: false,
                    replacementDate: null,
                    category: 'Reference guides',
                    previewUrl: 'iPhone-User-Guide.pdf',
                    compression: 'none'
                },
                {
                    id: 'focus',
                    name: 'The Productivity Project Summary.pdf',
                    originalName: 'The Productivity Project Summary.pdf',
                    extension: 'pdf',
                    size: 2206112,
                    uploadDate: yesterday.toISOString(),
                    modified: false,
                    replacementDate: null,
                    category: 'Team updates',
                    previewUrl: 'The-Productivity-Project-Summary.pdf',
                    compression: 'none'
                }
            ],
            folderShares: { 'Product documents': 'Drive' }
        };
    }

    function readState() {
        try {
            const stored = localStorage.getItem(STORAGE_KEY);
            if (stored) {
                const state = JSON.parse(stored);
                if (Array.isArray(state.categories) && Array.isArray(state.files) && state.folderShares) {
                    return state;
                }
            }
        } catch (error) {
            console.warn('Demo data could not be restored.', error);
        }

        const state = defaultState();
        writeState(state);
        return state;
    }

    function writeState(state) {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
    }

    function orderedCategories(state) {
        const roots = state.categories
            .filter(category => !category.parent)
            .sort((a, b) => {
                if (a.name === ROOT_CATEGORY) return -1;
                if (b.name === ROOT_CATEGORY) return 1;
                return a.name.localeCompare(b.name);
            });
        const result = [];

        roots.forEach(root => {
            result.push({ name: root.name, parent: null });
            state.categories
                .filter(category => category.parent === root.name)
                .sort((a, b) => a.name.localeCompare(b.name))
                .forEach(child => result.push({ name: child.name, parent: root.name }));
        });

        return result;
    }

    function pageUrl() {
        return window.location.href.split('#')[0];
    }

    function clientFile(file) {
        return {
            ...clone(file),
            shareUrl: `${pageUrl()}#file=${encodeURIComponent(file.id)}`
        };
    }

    function responseFor(state, extra = {}) {
        return {
            success: true,
            categories: orderedCategories(state),
            files: state.files.map(clientFile),
            sharedCategories: Object.keys(state.folderShares),
            ...extra
        };
    }

    function error(message) {
        return { success: false, error: message };
    }

    function categoryExists(state, name) {
        return state.categories.some(category => category.name === name);
    }

    function uniqueId(state, characters, source) {
        for (let i = 0; i < 100; i++) {
            const id = randomId(characters);
            if (!source.includes(id)) return id;
        }
        throw new Error('Could not generate a demo ID.');
    }

    function fileExtension(fileName) {
        const parts = String(fileName || '').split('.');
        return parts.length > 1 ? parts.pop().toLowerCase() : '';
    }

    const compressionJobs = {};

    function shouldDemoCompress(record) {
        return record.extension === 'pdf' && record.size > 30 * 1024 && record.size <= 40 * 1024 * 1024;
    }

    function scheduleDemoCompression(record) {
        if (!shouldDemoCompress(record)) {
            record.compression = 'none';
            return 'none';
        }
        record.compression = 'pending';
        compressionJobs[record.id] = {
            doneAt: Date.now() + Math.min(4500, Math.max(1800, record.size / 900)),
            newSize: Math.max(1024, Math.round(record.size * 0.72))
        };
        return 'pending';
    }

    function settleCompression(state, record) {
        if (!record || record.compression !== 'pending') return record;
        let job = compressionJobs[record.id];
        if (!job) {
            job = compressionJobs[record.id] = {
                doneAt: Date.now() + 1200,
                newSize: Math.max(1024, Math.round(record.size * 0.72))
            };
        }
        if (Date.now() < job.doneAt) return record;
        record.size = job.newSize;
        record.compression = 'done';
        delete compressionJobs[record.id];
        writeState(state);
        return record;
    }

    function handle(action, input) {
        const state = readState();

        if (action === 'list' || action === 'categories') {
            state.files.forEach((file) => settleCompression(state, file));
            return responseFor(state);
        }

        if (action === 'file_status') {
            const record = state.files.find(item => item.id === input.id);
            if (!record) return error('File not found');
            settleCompression(state, record);
            return { success: true, file: clientFile(record) };
        }

        if (action === 'upload') {
            const file = input.file;
            if (!file || !file.name) return error('No file selected');
            const id = uniqueId(state, FILE_ID_CHARACTERS, state.files.map(item => item.id));
            const record = {
                id,
                name: file.name,
                originalName: file.name,
                extension: fileExtension(file.name),
                size: Number(file.size) || 0,
                uploadDate: new Date().toISOString(),
                modified: false,
                replacementDate: null,
                category: categoryExists(state, input.category) ? input.category : ROOT_CATEGORY,
                compression: 'none'
            };
            const compression = scheduleDemoCompression(record);
            state.files.push(record);
            writeState(state);
            return { success: true, file: clientFile(record), compression };
        }

        if (action === 'replace') {
            const file = input.file;
            const record = state.files.find(item => item.id === input.id);
            if (!record) return error('File not found');
            if (!file || !file.name) return error('No file selected');

            record.name = file.name;
            record.extension = fileExtension(file.name);
            record.size = Number(file.size) || 0;
            record.modified = true;
            record.replacementDate = new Date().toISOString();
            delete record.previewUrl;
            const compression = scheduleDemoCompression(record);
            writeState(state);
            return { success: true, file: clientFile(record), compression };
        }

        if (action === 'rename') {
            const record = state.files.find(item => item.id === input.id);
            if (!record) return error('File not found');
            if (!String(input.name || '').trim()) return error('A name is required');
            record.name = String(input.name).trim();
            writeState(state);
            return { success: true };
        }

        if (action === 'delete') {
            const index = state.files.findIndex(item => item.id === input.id);
            if (index === -1) return error('File not found');
            state.files.splice(index, 1);
            writeState(state);
            return { success: true };
        }

        if (action === 'update_category') {
            const record = state.files.find(item => item.id === input.id);
            if (!record) return error('File not found');
            if (!categoryExists(state, input.category)) return error('Category not found');
            record.category = input.category;
            writeState(state);
            return { success: true };
        }

        if (action === 'category_create') {
            const name = String(input.name || '').trim();
            const parent = String(input.parent || '').trim();
            if (!name) return error('A category name is required');
            if (categoryExists(state, name)) return error('Category already exists');

            if (parent) {
                const parentCategory = state.categories.find(category => category.name === parent);
                if (!parentCategory) return error('Parent category not found');
                if (parentCategory.parent || parent === ROOT_CATEGORY) return error('Subcategories can only be created under top-level categories');
            }

            state.categories.push({ name, parent: parent || null });
            writeState(state);
            return responseFor(state);
        }

        if (action === 'category_rename') {
            const oldName = String(input.oldName || '').trim();
            const newName = String(input.newName || '').trim();
            const category = state.categories.find(item => item.name === oldName);
            if (!category) return error('Category not found');
            if (oldName === ROOT_CATEGORY) return error('This category cannot be edited');
            if (!newName) return error('A category name is required');
            if (newName !== oldName && categoryExists(state, newName)) return error('Category already exists');

            const requestedParent = input.parent === null ? '' : String(input.parent || '').trim();
            if (requestedParent) {
                const parent = state.categories.find(item => item.name === requestedParent);
                if (!parent || parent.parent || requestedParent === ROOT_CATEGORY || requestedParent === oldName || requestedParent === newName) {
                    return error('Invalid parent category');
                }
                if (state.categories.some(item => item.parent === oldName)) {
                    return error('A category with subcategories cannot be nested');
                }
                category.parent = requestedParent;
            } else {
                category.parent = null;
            }

            category.name = newName;
            state.categories.forEach(item => {
                if (item.parent === oldName) item.parent = newName;
            });
            state.files.forEach(file => {
                if (file.category === oldName) file.category = newName;
            });
            if (state.folderShares[oldName]) {
                state.folderShares[newName] = state.folderShares[oldName];
                delete state.folderShares[oldName];
            }
            writeState(state);
            return responseFor(state);
        }

        if (action === 'category_delete') {
            const name = String(input.name || '').trim();
            if (name === ROOT_CATEGORY) return error('This category cannot be deleted');
            if (!categoryExists(state, name)) return error('Category not found');

            const namesToDelete = new Set([name]);
            state.categories.forEach(category => {
                if (category.parent === name) namesToDelete.add(category.name);
            });
            state.categories = state.categories.filter(category => !namesToDelete.has(category.name));
            state.files.forEach(file => {
                if (namesToDelete.has(file.category)) file.category = ROOT_CATEGORY;
            });
            namesToDelete.forEach(category => delete state.folderShares[category]);
            writeState(state);
            return responseFor(state);
        }

        if (action === 'folder_share') {
            const category = String(input.category || '').trim();
            if (!categoryExists(state, category) || category === ROOT_CATEGORY) return error('Invalid folder');
            if (!state.folderShares[category]) {
                state.folderShares[category] = uniqueId(
                    state,
                    FOLDER_ID_CHARACTERS,
                    Object.values(state.folderShares)
                );
                writeState(state);
            }
            const shareId = state.folderShares[category];
            return {
                success: true,
                shareId,
                shareUrl: `${pageUrl()}#folder=${encodeURIComponent(shareId)}`
            };
        }

        if (action === 'folder_unshare') {
            delete state.folderShares[String(input.category || '').trim()];
            writeState(state);
            return { success: true };
        }

        return error('Unknown demo action');
    }

    async function parseRequest(url, options) {
        const parsedUrl = new URL(url, window.location.href);
        let action = parsedUrl.searchParams.get('action');
        let input = {};
        const body = options && options.body;

        if (body instanceof FormData) {
            body.forEach((value, key) => {
                input[key] = value;
            });
        } else if (typeof body === 'string' && body) {
            input = JSON.parse(body);
        }
        action = action || input.action;
        if (!action && parsedUrl.searchParams.has('id')) {
            input.id = parsedUrl.searchParams.get('id');
        }
        if (!input.id && parsedUrl.searchParams.get('id')) {
            input.id = parsedUrl.searchParams.get('id');
        }
        return { action, input };
    }

    function isDemoApiUrl(url) {
        if (!url) return false;
        const resolved = new URL(String(url), window.location.href);
        return /(?:^|\/)api\.php$/.test(resolved.pathname);
    }

    function uploadDuration(file) {
        const size = file && typeof file.size === 'number' ? file.size : 0;
        return Math.min(5500, Math.max(1400, size / 18000));
    }

    function emitUploadProgress(xhr, file) {
        return new Promise((resolve) => {
            const duration = uploadDuration(file);
            const start = Date.now();
            const total = Math.max(1, Number(file.size) || 1);
            const tick = () => {
                const t = Math.min(1, (Date.now() - start) / duration);
                const eased = 1 - Math.pow(1 - t, 2);
                if (xhr.upload && typeof xhr.upload.onprogress === 'function') {
                    xhr.upload.onprogress({
                        lengthComputable: true,
                        loaded: Math.round(eased * total),
                        total
                    });
                }
                if (t < 1) {
                    requestAnimationFrame(tick);
                } else {
                    resolve();
                }
            };
            tick();
        });
    }

    function finishFakeXhr(xhr, payload) {
        const body = JSON.stringify(clone(payload));
        Object.defineProperty(xhr, 'status', { configurable: true, get: () => (payload.success ? 200 : 400) });
        Object.defineProperty(xhr, 'readyState', { configurable: true, get: () => 4 });
        Object.defineProperty(xhr, 'responseText', { configurable: true, get: () => body });
        Object.defineProperty(xhr, 'response', { configurable: true, get: () => body });
        if (typeof xhr.onload === 'function') {
            xhr.onload();
        }
        if (typeof xhr.onreadystatechange === 'function') {
            xhr.onreadystatechange();
        }
    }

    const NativeXHR = window.XMLHttpRequest;
    const nativeOpen = NativeXHR.prototype.open;
    const nativeSend = NativeXHR.prototype.send;

    NativeXHR.prototype.open = function (method, url, ...rest) {
        this.__demoMethod = method;
        this.__demoUrl = url;
        return nativeOpen.call(this, method, url, ...rest);
    };

    NativeXHR.prototype.send = function (body) {
        if (!isDemoApiUrl(this.__demoUrl)) {
            return nativeSend.call(this, body);
        }
        const xhr = this;
        parseRequest(this.__demoUrl, { body }).then(async ({ action, input }) => {
            if ((action === 'upload' || action === 'replace') && input.file) {
                await emitUploadProgress(xhr, input.file);
            }
            finishFakeXhr(xhr, handle(action, input));
        }).catch(() => {
            finishFakeXhr(xhr, error('Invalid request'));
        });
    };

    window.fetch = async (url, options = {}) => {
        const { action, input } = await parseRequest(url, options);
        const payload = handle(action, input);
        return {
            ok: payload.success,
            status: payload.success ? 200 : 400,
            json: async () => clone(payload)
        };
    };

    window.addEventListener('DOMContentLoaded', () => {
        const resetButton = document.getElementById('resetDemoBtn');
        if (!resetButton) return;
        resetButton.addEventListener('click', () => {
            localStorage.removeItem(STORAGE_KEY);
            window.location.reload();
        });
    });
})();
