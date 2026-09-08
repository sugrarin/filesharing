let files = [];
// [{ name, parent }] — parent is null for top-level categories.
let categories = [{ name: 'All files', parent: null }];
let currentCategory = 'All files';
let searchQuery = '';
let sortBy = 'date';
let currentCategoryForEdit = null;
let lastCopiedFileId = null;
let currentFileForMenu = null;
let sharedCategories = new Set();
const csrfTokens = window.DISK_CSRF_TOKENS || {};
const MAX_UPLOAD_BYTES = 100 * 1024 * 1024;
const UPLOAD_CONCURRENCY = 3;
/** Delay before delete is committed; matches undo-timer-bar animation. */
const UNDO_DELAY_MS = 4000;
const TOAST_EXIT_MS = 220;
const TOAST_SUCCESS_MS = 2400;
const TOAST_ERROR_MS = 4000;
const TOAST_INFO_MS = 3000;
const COMPRESSION_POLL_MS = 1000;
const COMPRESSION_TIMEOUT_MS = 120000;
const ALLOWED_UPLOAD_EXTENSIONS = new Set([
    'pdf', 'doc', 'docx', 'odp', 'pptm', 'jpg', 'jpeg', 'png', 'zip', 'mp4', 'mov'
]);
let searchDebounceTimer = null;

const dropZone = document.getElementById('dropZone');
const fileInput = document.getElementById('fileInput');
const uploadBtn = document.getElementById('uploadBtn');
const searchInput = document.getElementById('searchInput');
const clearSearch = document.getElementById('clearSearch');
const sortByDate = document.getElementById('sortByDate');
const sortBySize = document.getElementById('sortBySize');
const filesContainer = document.getElementById('filesContainer');
/** Currently highlighted file row (actions visible). JS-driven to avoid Safari sticky :hover. */
let activeFileItem = null;
const categoriesList = document.getElementById('categoriesList');
const addCategoryBtn = document.getElementById('addCategoryBtn');
const categoryModal = document.getElementById('categoryModal');
const modalTitle = document.getElementById('modalTitle');
const categoryNameInput = document.getElementById('categoryNameInput');
const categoryParentField = document.getElementById('categoryParentField');
const categoryParentSelect = document.getElementById('categoryParentSelect');
const closeModal = document.getElementById('closeModal');
const cancelModal = document.getElementById('cancelModal');
const saveCategory = document.getElementById('saveCategory');
const categoryMenu = document.getElementById('categoryMenu');
const addSubcategoryBtn = document.getElementById('addSubcategoryBtn');
const copyCategoryLinkBtn = document.getElementById('copyCategoryLinkBtn');
const revokeCategoryShareBtn = document.getElementById('revokeCategoryShareBtn');
const renameCategoryBtn = document.getElementById('renameCategoryBtn');
const deleteCategoryBtn = document.getElementById('deleteCategoryBtn');
const toastContainer = document.getElementById('toastContainer');
const fileMenu = document.getElementById('fileMenu');
const fileCopyBtn = document.getElementById('fileCopyBtn');
const fileOpenBtn = document.getElementById('fileOpenBtn');
const fileRenameBtn = document.getElementById('fileRenameBtn');
const fileReplaceBtn = document.getElementById('fileReplaceBtn');
const fileDeleteBtn = document.getElementById('fileDeleteBtn');

function csrfToken(action) {
    return csrfTokens[action] || '';
}

function jsonHeaders(action) {
    return {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrfToken(action)
    };
}

function addCsrfToken(formData, action) {
    formData.append('csrf_token', csrfToken(action));
    return formData;
}

async function init() {
    await loadData();
    renderCategories();
    renderFiles();
    updateStats();
    updateSortTabs();
    setupEventListeners();
    resumeCompressionToasts();
}

function setupEventListeners() {
    uploadBtn.addEventListener('click', () => fileInput.click());
    fileInput.addEventListener('change', handleFileSelect);

    document.body.addEventListener('dragenter', handleDragEnter);
    document.body.addEventListener('dragover', handleDragOver);
    document.body.addEventListener('dragleave', handleDragLeave);
    document.body.addEventListener('drop', handleDrop);

    dropZone.addEventListener('dragenter', handleDragEnter);
    dropZone.addEventListener('dragover', handleDragOver);
    dropZone.addEventListener('dragleave', handleDragLeave);
    dropZone.addEventListener('drop', handleDrop);

    searchInput.addEventListener('input', handleSearch);
    clearSearch.addEventListener('click', handleClearSearch);

    sortByDate.addEventListener('click', () => setSortBy('date'));
    sortBySize.addEventListener('click', () => setSortBy('size'));

    setupFileRowHover();

    addCategoryBtn.addEventListener('click', () => openCategoryModal());
    closeModal.addEventListener('click', closeCategoryModal);
    cancelModal.addEventListener('click', closeCategoryModal);
    saveCategory.addEventListener('click', handleSaveCategory);
    categoryNameInput.addEventListener('keypress', (e) => {
        if (e.key === 'Enter') handleSaveCategory();
    });

    const closeFileRenameBtn = document.getElementById('closeFileRenameModal');
    const cancelFileRename = document.getElementById('cancelFileRename');
    const saveFileRename = document.getElementById('saveFileRename');
    const fileNameInput = document.getElementById('fileNameInput');

    closeFileRenameBtn.addEventListener('click', closeFileRenameModal);
    cancelFileRename.addEventListener('click', closeFileRenameModal);
    saveFileRename.addEventListener('click', handleSaveFileRename);
    fileNameInput.addEventListener('keypress', (e) => {
        if (e.key === 'Enter') handleSaveFileRename();
    });

    renameCategoryBtn.addEventListener('click', handleRenameCategory);
    deleteCategoryBtn.addEventListener('click', handleDeleteCategory);
    addSubcategoryBtn.addEventListener('click', handleAddSubcategory);
    copyCategoryLinkBtn.addEventListener('click', handleCategoryCopyLink);
    revokeCategoryShareBtn.addEventListener('click', handleRevokeCategoryShare);

    categoriesList.addEventListener('mouseover', (e) => {
        const item = e.target.closest('.category-item[data-tooltip]');
        if (item) showSidebarTooltip(item);
    });
    categoriesList.addEventListener('mouseout', (e) => {
        const item = e.target.closest('.category-item[data-tooltip]');
        if (item && !item.contains(e.relatedTarget)) hideSidebarTooltip();
    });
    categoriesList.addEventListener('scroll', hideSidebarTooltip);

    fileCopyBtn.addEventListener('click', handleFileCopy);
    fileOpenBtn.addEventListener('click', handleFileOpen);
    fileRenameBtn.addEventListener('click', handleFileRenameMenu);
    fileReplaceBtn.addEventListener('click', handleFileReplaceMenu);
    fileDeleteBtn.addEventListener('click', handleFileDeleteMenu);

    document.addEventListener('click', (e) => {
        if (!categoryMenu.contains(e.target) && !e.target.classList.contains('category-menu-btn')) {
            categoryMenu.style.display = 'none';
        }
        if (!fileMenu.contains(e.target) && !e.target.classList.contains('file-menu-btn')) {
            fileMenu.style.display = 'none';
        }
    });

    let mouseDownTarget = null;

    categoryModal.addEventListener('mousedown', (e) => {
        mouseDownTarget = e.target;
    });

    categoryModal.addEventListener('click', (e) => {
        if (e.target === categoryModal && mouseDownTarget === categoryModal) {
            closeCategoryModal();
        }
    });

    const fileRenameModal = document.getElementById('fileRenameModal');

    fileRenameModal.addEventListener('mousedown', (e) => {
        mouseDownTarget = e.target;
    });

    fileRenameModal.addEventListener('click', (e) => {
        if (e.target === fileRenameModal && mouseDownTarget === fileRenameModal) {
            closeFileRenameModal();
        }
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            if (categoryModal.style.display === 'flex') {
                closeCategoryModal();
            }
            if (fileRenameModal.style.display === 'flex') {
                closeFileRenameModal();
            }
        }
        trapFocusInOpenModal(e);
    });
}

function getOpenModal() {
    if (categoryModal.style.display === 'flex') return categoryModal;
    const fileRenameModal = document.getElementById('fileRenameModal');
    if (fileRenameModal && fileRenameModal.style.display === 'flex') return fileRenameModal;
    return null;
}

function setActiveFileItem(item) {
    if (activeFileItem === item) return;

    // Force-clear any stuck rows (Safari can leave classes if DOM was partially updated).
    if (filesContainer) {
        filesContainer.querySelectorAll('.file-item.is-active').forEach((el) => {
            if (el !== item) el.classList.remove('is-active');
        });
    } else if (activeFileItem) {
        activeFileItem.classList.remove('is-active');
    }

    activeFileItem = item || null;
    if (activeFileItem) {
        activeFileItem.classList.add('is-active');
    }
}

function clearActiveFileItem() {
    setActiveFileItem(null);
}

/**
 * Show file-actions via a single .is-active class (no CSS :hover).
 */
function setupFileRowHover() {
    if (!filesContainer) return;

    filesContainer.addEventListener('pointerover', (e) => {
        if (e.pointerType === 'touch') return;
        const item = e.target.closest('.file-item');
        if (item && filesContainer.contains(item)) {
            setActiveFileItem(item);
        }
    });

    filesContainer.addEventListener('pointerleave', (e) => {
        if (e.pointerType === 'touch') return;
        // Keep actions while focus is inside the row (e.g. <select>).
        if (activeFileItem && activeFileItem.contains(document.activeElement)) {
            return;
        }
        clearActiveFileItem();
    });

    filesContainer.addEventListener('focusin', (e) => {
        const item = e.target.closest('.file-item');
        if (item && filesContainer.contains(item)) {
            setActiveFileItem(item);
        }
    });

    filesContainer.addEventListener('focusout', () => {
        requestAnimationFrame(() => {
            if (!activeFileItem) return;
            if (activeFileItem.contains(document.activeElement)) return;
            // If pointer still over this row, keep actions (fine pointer only).
            try {
                if (activeFileItem.matches(':hover')) return;
            } catch (_) { /* ignore */ }
            clearActiveFileItem();
        });
    });

    filesContainer.addEventListener('scroll', clearActiveFileItem, { passive: true });
}

function trapFocusInOpenModal(e) {
    if (e.key !== 'Tab') return;
    const modal = getOpenModal();
    if (!modal) return;
    const focusable = modal.querySelectorAll(
        'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
    );
    if (!focusable.length) return;
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (e.shiftKey && document.activeElement === first) {
        e.preventDefault();
        last.focus();
    } else if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault();
        first.focus();
    }
}

function normalizeCategories(raw) {
    if (!Array.isArray(raw) || raw.length === 0) {
        return [{ name: 'All files', parent: null }];
    }
    return raw.map(item => {
        if (typeof item === 'string') {
            return { name: item, parent: null };
        }
        return {
            name: item.name,
            parent: item.parent || null
        };
    });
}

function getCategoryNames() {
    return categories.map(c => c.name);
}

function getCategoryMeta(name) {
    return categories.find(c => c.name === name) || null;
}

function getChildCategories(parentName) {
    return categories.filter(c => c.parent === parentName);
}

function hasChildren(categoryName) {
    return categories.some(c => c.parent === categoryName);
}

function attachUndoTimerBar(element) {
    removeUndoTimerBar(element);
    element.classList.add('undo-pending');
    const bar = document.createElement('div');
    bar.className = 'undo-timer-bar';
    bar.style.setProperty('--undo-ms', UNDO_DELAY_MS + 'ms');
    bar.setAttribute('aria-hidden', 'true');
    element.appendChild(bar);
}

function removeUndoTimerBar(element) {
    if (!element) return;
    element.classList.remove('undo-pending');
    element.querySelectorAll('.undo-timer-bar').forEach(bar => bar.remove());
}

// Parent category shows own files + files from direct children.
function getCategoryScopeNames(categoryName) {
    if (categoryName === 'All files') {
        return null; // all files
    }
    const names = [categoryName];
    getChildCategories(categoryName).forEach(c => names.push(c.name));
    return names;
}

function fileMatchesCategory(file, categoryName) {
    if (categoryName === 'All files') return true;
    const scope = getCategoryScopeNames(categoryName);
    // Child category: only own files. Parent: own + children.
    const meta = getCategoryMeta(categoryName);
    if (meta && meta.parent) {
        return file.category === categoryName;
    }
    return scope.includes(file.category);
}

async function loadData() {
    try {
        const response = await fetch('api.php?action=list');
        const data = await response.json();

        if (data.success) {
            files = data.files || [];
            categories = normalizeCategories(data.categories);
            sharedCategories = new Set(data.sharedCategories || []);
        }
    } catch (error) {
        console.error('Error loading data:', error);
        showToast('Error loading data', 'error');
    }
}

function createCategoryItem(categoryName, { isChild = false } = {}) {
    const li = document.createElement('li');
    const isActive = categoryName === currentCategory;
    const children = getChildCategories(categoryName);
    li.className = `category-item${isActive ? ' active' : ''}${isChild ? ' category-child' : ''}${children.length ? ' has-children' : ''}`;

    const nameSpan = document.createElement('span');
    nameSpan.className = 'category-name';
    nameSpan.dataset.category = categoryName;
    if (isChild) {
        const indentIcon = document.createElement('span');
        indentIcon.className = 'category-child-icon';
        indentIcon.textContent = '└';
        nameSpan.appendChild(indentIcon);
    }
    const nameText = document.createElement('span');
    nameText.textContent = categoryName;
    nameSpan.appendChild(nameText);
    nameSpan.addEventListener('click', () => selectCategory(categoryName));

    // Instant tooltip for long names (native title appears with a delay)
    const tooltipLimit = isChild ? 18 : 22;
    if (categoryName.length > tooltipLimit) {
        li.dataset.tooltip = categoryName;
    }

    li.appendChild(nameSpan);

    if (sharedCategories.has(categoryName)) {
        const shareIcon = document.createElement('span');
        shareIcon.className = 'category-share-icon';
        shareIcon.title = 'Link access is open';
        shareIcon.innerHTML = `
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon-svg-small">
                <path d="M8 7a4 4 0 1 0 8 0a4 4 0 0 0 -8 0" />
                <path d="M6 21v-2a4 4 0 0 1 4 -4h3" />
                <path d="M16 22l5 -5" />
                <path d="M21 21.5v-4.5h-4.5" />
            </svg>
        `;
        li.appendChild(shareIcon);
    }

    if (categoryName !== 'All files') {
        const menuBtn = document.createElement('button');
        menuBtn.className = 'category-btn-icon category-menu-btn';
        menuBtn.innerHTML = `
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="icon-svg-small">
                <circle cx="6" cy="12" r="1.5" fill="currentColor"/>
                <circle cx="12" cy="12" r="1.5" fill="currentColor"/>
                <circle cx="18" cy="12" r="1.5" fill="currentColor"/>
            </svg>
        `;
        menuBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            showCategoryMenu(e, categoryName);
        });
        li.appendChild(menuBtn);
    }

    return li;
}

function renderCategories() {
    categoriesList.innerHTML = '';

    const roots = categories.filter(c => !c.parent);

    roots.forEach(cat => {
        categoriesList.appendChild(createCategoryItem(cat.name));

        if (hasChildren(cat.name)) {
            getChildCategories(cat.name).forEach(child => {
                categoriesList.appendChild(createCategoryItem(child.name, { isChild: true }));
            });
        }
    });
}

function selectCategory(category) {
    currentCategory = category;
    hideSidebarTooltip();
    renderCategories();
    renderFiles();
    updateStats();
}

let sidebarTooltip = null;

// Tooltip to the right of the item; if there isn't enough room at the right edge of the window, wrap the text within the remaining width.
function showSidebarTooltip(item) {
    const text = item.dataset.tooltip;
    if (!text) return;

    if (!sidebarTooltip) {
        sidebarTooltip = document.createElement('div');
        sidebarTooltip.className = 'sidebar-tooltip';
        document.body.appendChild(sidebarTooltip);
    }

    const gap = 8;
    const margin = 8;
    const rect = item.getBoundingClientRect();

    sidebarTooltip.textContent = text;
    sidebarTooltip.style.maxWidth = 'none';
    sidebarTooltip.style.whiteSpace = 'nowrap';
    sidebarTooltip.style.left = (rect.right + gap) + 'px';
    sidebarTooltip.style.top = rect.top + 'px';
    sidebarTooltip.style.display = 'block';

    const available = window.innerWidth - (rect.right + gap) - margin;
    if (sidebarTooltip.offsetWidth > available) {
        sidebarTooltip.style.whiteSpace = 'normal';
        sidebarTooltip.style.maxWidth = Math.max(available, 120) + 'px';
    }
}

function hideSidebarTooltip() {
    if (sidebarTooltip) sidebarTooltip.style.display = 'none';
}

/**
 * Position a fixed context menu inside the viewport (flip up / clamp to edges).
 * Uses clientX/clientY because menus are position: fixed.
 */
function positionContextMenu(menu, e) {
    const margin = 10;
    menu.style.display = 'block';
    menu.style.visibility = 'hidden';
    // Reset before measuring so previous open doesn't affect size
    menu.style.left = '0px';
    menu.style.top = '0px';
    menu.style.right = 'auto';
    menu.style.bottom = 'auto';

    const menuWidth = menu.offsetWidth;
    const menuHeight = menu.offsetHeight;
    const vw = window.innerWidth;
    const vh = window.innerHeight;

    let x = e.clientX;
    let y = e.clientY;

    // Horizontal: open to the right of cursor, flip left if needed
    if (x + menuWidth > vw - margin) {
        x = Math.max(margin, vw - menuWidth - margin);
    } else {
        x = Math.max(margin, x);
    }

    // Vertical: open below cursor, flip above if not enough room
    if (y + menuHeight > vh - margin) {
        y = Math.max(margin, y - menuHeight);
        // If still overflows (very tall menu), clamp to top and let it fill viewport
        if (y + menuHeight > vh - margin) {
            y = Math.max(margin, vh - menuHeight - margin);
        }
    } else {
        y = Math.max(margin, y);
    }

    menu.style.left = x + 'px';
    menu.style.top = y + 'px';
    menu.style.right = 'auto';
    menu.style.bottom = 'auto';
    menu.style.visibility = 'visible';
}

function showCategoryMenu(e, category) {
    currentCategoryForEdit = category;
    revokeCategoryShareBtn.style.display = sharedCategories.has(category) ? '' : 'none';
    // Subcategories only under top-level categories (not under "All files" or another subcategory)
    const meta = getCategoryMeta(category);
    const canAddSub = meta && !meta.parent && category !== 'All files';
    addSubcategoryBtn.style.display = canAddSub ? '' : 'none';
    positionContextMenu(categoryMenu, e);
}

function showFileMenu(e, file) {
    currentFileForMenu = file;
    positionContextMenu(fileMenu, e);
}

function populateCategoryParentSelect(categoryName) {
    const meta = getCategoryMeta(categoryName);
    const currentParent = meta ? meta.parent : null;
    // Category with children cannot become a subcategory (only 1 nesting level).
    const lockedAsRoot = hasChildren(categoryName);

    categoryParentSelect.innerHTML = '';
    const noneOpt = document.createElement('option');
    noneOpt.value = '';
    noneOpt.textContent = '— Not linked —';
    categoryParentSelect.appendChild(noneOpt);

    if (!lockedAsRoot) {
        categories
            .filter(c => !c.parent && c.name !== 'All files' && c.name !== categoryName)
            .forEach(c => {
                const opt = document.createElement('option');
                opt.value = c.name;
                opt.textContent = c.name;
                categoryParentSelect.appendChild(opt);
            });
    }

    categoryParentSelect.value = currentParent || '';
    categoryParentSelect.disabled = lockedAsRoot;
}

function openCategoryModal(mode = 'create', categoryName = '') {
    if (mode === 'create') {
        modalTitle.textContent = 'New category';
    } else if (mode === 'create_sub') {
        modalTitle.textContent = 'New subcategory';
    } else {
        modalTitle.textContent = 'Edit category';
    }
    categoryNameInput.value = categoryName;
    categoryModal.style.display = 'flex';
    categoryModal.setAttribute('role', 'dialog');
    categoryModal.setAttribute('aria-modal', 'true');
    categoryNameInput.focus();
    categoryModal.dataset.mode = mode;
    categoryModal.dataset.parent = mode === 'create_sub' ? (currentCategoryForEdit || '') : '';
    if (mode === 'rename') {
        categoryModal.dataset.renameFrom = currentCategoryForEdit || categoryName || '';
        populateCategoryParentSelect(categoryModal.dataset.renameFrom);
        categoryParentField.style.display = '';
    } else {
        categoryModal.dataset.renameFrom = '';
        categoryParentField.style.display = 'none';
        categoryParentSelect.innerHTML = '';
        categoryParentSelect.disabled = false;
    }
}

function closeCategoryModal() {
    categoryModal.style.display = 'none';
    categoryNameInput.value = '';
    currentCategoryForEdit = null;
    categoryModal.dataset.mode = '';
    categoryModal.dataset.parent = '';
    categoryModal.dataset.renameFrom = '';
    categoryParentField.style.display = 'none';
    categoryParentSelect.innerHTML = '';
    categoryParentSelect.disabled = false;
}

async function handleSaveCategory() {
    const name = categoryNameInput.value.trim();
    if (!name) {
        showToast('Category name is required', 'error');
        return;
    }

    const mode = categoryModal.dataset.mode;
    const parentForSub = categoryModal.dataset.parent || currentCategoryForEdit;
    const renameFrom = categoryModal.dataset.renameFrom || currentCategoryForEdit;

    try {
        if (mode === 'create' || mode === 'create_sub') {
            const body = { action: 'category_create', name };
            if (mode === 'create_sub' && parentForSub) {
                body.parent = parentForSub;
            }

            const response = await fetch('api.php', {
                method: 'POST',
                headers: jsonHeaders('category_create'),
                body: JSON.stringify(body)
            });
            const data = await response.json();

            if (data.success) {
                categories = normalizeCategories(data.categories);
                sharedCategories = new Set(data.sharedCategories || []);
                renderCategories();
                showToast(mode === 'create_sub' ? 'Subcategory created' : 'Category created', 'success');
            } else {
                showToast(data.error || 'Error creating category', 'error');
            }
        } else {
            const parent = categoryParentSelect.disabled ? null : (categoryParentSelect.value || null);
            const response = await fetch('api.php', {
                method: 'POST',
                headers: jsonHeaders('category_rename'),
                body: JSON.stringify({
                    action: 'category_rename',
                    oldName: renameFrom,
                    newName: name,
                    parent
                })
            });
            const data = await response.json();

            if (data.success) {
                const oldName = renameFrom;
                categories = normalizeCategories(data.categories);
                files = data.files;
                sharedCategories = new Set(data.sharedCategories || []);
                if (currentCategory === oldName) {
                    currentCategory = name;
                }
                renderCategories();
                renderFiles();
                showToast('Category saved', 'success');
            } else {
                showToast(data.error || 'Error saving category', 'error');
            }
        }

        closeCategoryModal();
    } catch (error) {
        console.error('Error saving category:', error);
        showToast('Error saving category', 'error');
    }
}

function handleAddSubcategory() {
    categoryMenu.style.display = 'none';
    openCategoryModal('create_sub');
}

function handleRenameCategory() {
    categoryMenu.style.display = 'none';
    openCategoryModal('rename', currentCategoryForEdit);
}

function handleCategoryCopyLink() {
    categoryMenu.style.display = 'none';
    if (currentCategoryForEdit) {
        copyCategoryLink(currentCategoryForEdit);
    }
}

async function handleRevokeCategoryShare() {
    categoryMenu.style.display = 'none';
    const category = currentCategoryForEdit;
    if (!category) return;

    try {
        const response = await fetch('api.php', {
            method: 'POST',
            headers: jsonHeaders('folder_unshare'),
            body: JSON.stringify({ action: 'folder_unshare', category })
        });
        const data = await response.json();

        if (data.success) {
            sharedCategories.delete(category);
            renderCategories();
            showToast('Access revoked', 'success');
        } else {
            showToast(data.error || 'Error revoking access', 'error');
        }
    } catch (error) {
        console.error('Error revoking folder share:', error);
        showToast('Error revoking access', 'error');
    }
}

// File menu handlers
function handleFileCopy() {
    fileMenu.style.display = 'none';
    if (currentFileForMenu) {
        copyLink(currentFileForMenu);
    }
}

function handleFileOpen() {
    fileMenu.style.display = 'none';
    if (currentFileForMenu) {
        openFile(currentFileForMenu);
    }
}

function handleFileRenameMenu() {
    fileMenu.style.display = 'none';
    if (currentFileForMenu) {
        renameFile(currentFileForMenu);
    }
}

function handleFileReplaceMenu() {
    fileMenu.style.display = 'none';
    if (currentFileForMenu) {
        replaceFile(currentFileForMenu);
    }
}

function handleFileDeleteMenu() {
    fileMenu.style.display = 'none';
    if (currentFileForMenu) {
        deleteFile(currentFileForMenu);
    }
}

async function handleDeleteCategory() {
    categoryMenu.style.display = 'none';

    const categoryItems = document.querySelectorAll('.category-item');
    let categoryElement = null;
    categoryItems.forEach(item => {
        const nameSpan = item.querySelector('.category-name');
        if (nameSpan && nameSpan.dataset.category === currentCategoryForEdit) {
            categoryElement = item;
        }
    });

    if (!categoryElement) return;

    const nameSpan = categoryElement.querySelector('.category-name');
    const menuBtn = categoryElement.querySelector('.category-menu-btn');
    const originalOpacity = nameSpan.style.opacity;

    const originalHandler = (e) => {
        e.stopPropagation();
        showCategoryMenu(e, currentCategoryForEdit);
    };

    nameSpan.style.opacity = '0.4';
    menuBtn.className = 'category-btn-icon category-undo-btn';
    menuBtn.innerHTML = `<img src="icons/undo.png" alt="Undo" class="icon-img">`;
    menuBtn.style.opacity = '1';
    attachUndoTimerBar(categoryElement);

    const newMenuBtn = menuBtn.cloneNode(true);
    menuBtn.parentNode.replaceChild(newMenuBtn, menuBtn);
    const currentMenuBtn = newMenuBtn;

    let undoTimeout = null;
    let cancelled = false;

    const undoHandler = () => {
        cancelled = true;
        clearTimeout(undoTimeout);
        removeUndoTimerBar(categoryElement);
        nameSpan.style.opacity = originalOpacity;
        currentMenuBtn.className = 'category-btn-icon category-menu-btn';
        currentMenuBtn.innerHTML = `
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="icon-svg-small">
                <circle cx="6" cy="12" r="1.5" fill="currentColor"/>
                <circle cx="12" cy="12" r="1.5" fill="currentColor"/>
                <circle cx="18" cy="12" r="1.5" fill="currentColor"/>
            </svg>
        `;
        currentMenuBtn.removeEventListener('click', undoHandler);
        currentMenuBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            showCategoryMenu(e, currentCategoryForEdit);
        });
    };

    currentMenuBtn.addEventListener('click', undoHandler);

    undoTimeout = setTimeout(async () => {
        if (cancelled) return;

        menuBtn.removeEventListener('click', undoHandler);

        try {
            const response = await fetch('api.php', {
                method: 'POST',
                headers: jsonHeaders('category_delete'),
                body: JSON.stringify({ action: 'category_delete', name: currentCategoryForEdit })
            });
            const data = await response.json();

            if (data.success) {
                const deletedName = currentCategoryForEdit;
                categories = normalizeCategories(data.categories);
                files = data.files;
                sharedCategories = new Set(data.sharedCategories || []);
                // Reset selection if deleted category (or its child) was active
                if (currentCategory === deletedName || !getCategoryNames().includes(currentCategory)) {
                    currentCategory = 'All files';
                }
                renderCategories();
                renderFiles();
                updateStats();
                showToast('Category deleted', 'success');
            } else {
                showToast(data.error || 'Error deleting category', 'error');
                removeUndoTimerBar(categoryElement);
                nameSpan.style.opacity = originalOpacity;
            }
        } catch (error) {
            console.error('Error deleting category:', error);
            showToast('Error deleting category', 'error');
            removeUndoTimerBar(categoryElement);
            nameSpan.style.opacity = originalOpacity;
        }
    }, UNDO_DELAY_MS);
}

function renderFiles() {
    clearActiveFileItem();

    let filteredFiles = files.filter(f => fileMatchesCategory(f, currentCategory));

    if (searchQuery) {
        const q = searchQuery.toLowerCase();
        filteredFiles = filteredFiles.filter(f =>
            f.name.toLowerCase().includes(q) ||
            f.id.toLowerCase().includes(q)
        );
    }

    filteredFiles.sort((a, b) => {
        if (sortBy === 'date') {
            return new Date(b.uploadDate) - new Date(a.uploadDate);
        }
        return b.size - a.size;
    });

    filesContainer.replaceChildren();

    if (filteredFiles.length === 0) {
        const empty = document.createElement('div');
        empty.className = 'empty-state';
        const img = document.createElement('img');
        img.src = 'icons/empty.png';
        img.alt = '';
        img.className = 'empty-state-icon';
        const p = document.createElement('p');
        p.textContent = searchQuery ? 'Nothing found' : 'No files';
        empty.appendChild(img);
        empty.appendChild(p);
        filesContainer.appendChild(empty);
        return;
    }

    const fragment = document.createDocumentFragment();
    filteredFiles.forEach(file => {
        fragment.appendChild(createFileItem(file));
    });
    filesContainer.appendChild(fragment);
}

function createFileItem(file) {
    const div = document.createElement('div');
    div.className = 'file-item';

    const icon = document.createElement('div');
    icon.className = 'file-icon';
    appendFileIcon(icon, file.id);

    const info = document.createElement('div');
    info.className = 'file-info';

    const name = document.createElement('div');
    name.className = 'file-name';
    name.textContent = file.id === lastCopiedFileId ? file.name + ' •' : file.name;

    const meta = document.createElement('div');
    meta.className = 'file-meta';
    const dateStr = file.modified ? `🔄 ${formatDate(file.replacementDate || file.uploadDate)}` : formatDate(file.uploadDate);
    meta.textContent = `${dateStr} · ${formatSize(file.size)}`;

    info.appendChild(name);
    info.appendChild(meta);

    const actions = document.createElement('div');
    actions.className = 'file-actions';

    const copyBtn = createActionButton('copy-link.png', 'Copy link', () => copyLink(file));
    const openBtn = createActionButton('see-open.png', 'Open', () => openFile(file));

    const categorySelect = document.createElement('select');
    categorySelect.className = 'category-select';
    categorySelect.setAttribute('aria-label', 'File category');
    categories.forEach(cat => {
        const option = document.createElement('option');
        option.value = cat.name;
        option.textContent = cat.parent ? `  ${cat.name}` : cat.name;
        option.selected = cat.name === file.category;
        categorySelect.appendChild(option);
    });
    categorySelect.addEventListener('change', (e) => updateFileCategory(file.id, e.target.value));

    const renameBtn = createActionButton('edit.png', 'Rename', () => renameFile(file));
    const replaceBtn = createActionButton('update.png', 'Replace file', () => replaceFile(file));
    const deleteBtn = createActionButton('delete.png', 'Delete', () => deleteFile(file), true);

    actions.appendChild(copyBtn);
    actions.appendChild(openBtn);
    actions.appendChild(renameBtn);
    actions.appendChild(replaceBtn);
    actions.appendChild(deleteBtn);
    actions.appendChild(categorySelect);

    const menuBtn = document.createElement('button');
    menuBtn.className = 'file-menu-btn';
    menuBtn.type = 'button';
    menuBtn.setAttribute('aria-label', 'Actions menu');
    menuBtn.title = 'Actions menu';
    const menuSvg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    menuSvg.setAttribute('viewBox', '0 0 24 24');
    menuSvg.setAttribute('class', 'icon-svg-small');
    for (const cx of [6, 12, 18]) {
        const c = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
        c.setAttribute('cx', String(cx));
        c.setAttribute('cy', '12');
        c.setAttribute('r', '1.5');
        c.setAttribute('fill', 'currentColor');
        menuSvg.appendChild(c);
    }
    menuBtn.appendChild(menuSvg);
    menuBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        showFileMenu(e, file);
    });

    div.appendChild(icon);
    div.appendChild(info);
    div.appendChild(actions);
    div.appendChild(menuBtn);

    return div;
}

function createActionButton(iconFile, title, onClick, isDanger = false) {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = `action-btn ${isDanger ? 'danger' : ''}`;
    btn.title = title;
    btn.setAttribute('aria-label', title);
    const img = document.createElement('img');
    img.src = 'icons/' + iconFile;
    img.alt = '';
    img.className = 'icon-img';
    btn.appendChild(img);
    btn.addEventListener('click', onClick);
    return btn;
}

function appendFileIcon(container, fileId) {
    let hash = 0;
    const id = String(fileId || '');
    for (let i = 0; i < id.length; i++) {
        hash = ((hash << 5) - hash) + id.charCodeAt(i);
        hash = hash & hash;
    }
    const iconNumber = (Math.abs(hash) % 4) + 1;
    const img = document.createElement('img');
    img.src = 'icons/file-' + iconNumber + '.png';
    img.alt = '';
    img.className = 'file-icon-img';
    img.width = 40;
    img.height = 40;
    container.appendChild(img);
}

function formatDate(dateStr) {
    const date = new Date(dateStr);
    if (Number.isNaN(date.getTime())) {
        return '';
    }
    const now = new Date();
    const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    const fileDate = new Date(date.getFullYear(), date.getMonth(), date.getDate());

    const time = date.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });

    if (fileDate.getTime() === today.getTime()) {
        return `Today, ${time}`;
    }

    const yesterday = new Date(today);
    yesterday.setDate(yesterday.getDate() - 1);
    if (fileDate.getTime() === yesterday.getTime()) {
        return `Yesterday, ${time}`;
    }

    return date.toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' }) + ', ' + time;
}

function formatSize(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    if (bytes < 1024 * 1024 * 1024) return (bytes / (1024 * 1024)).toFixed(2) + ' MB';
    return (bytes / (1024 * 1024 * 1024)).toFixed(2) + ' GB';
}

let dragCounter = 0;

function handleDragOver(e) {
    e.preventDefault();
    e.stopPropagation();
    dropZone.classList.add('drag-over');
}

function handleDragLeave(e) {
    e.preventDefault();
    e.stopPropagation();

    dragCounter--;
    if (dragCounter === 0) {
        dropZone.classList.remove('drag-over');
    }
}

function handleDragEnter(e) {
    e.preventDefault();
    e.stopPropagation();
    dragCounter++;
    dropZone.classList.add('drag-over');
}

function handleDrop(e) {
    e.preventDefault();
    e.stopPropagation();
    dragCounter = 0;
    dropZone.classList.remove('drag-over');

    const files = Array.from(e.dataTransfer.files);
    uploadFiles(files);
}

function handleFileSelect(e) {
    const files = Array.from(e.target.files);
    uploadFiles(files);
    e.target.value = '';
}

async function uploadFiles(filesToUpload) {
    const queue = [];
    for (const file of Array.from(filesToUpload)) {
        if (file.size > MAX_UPLOAD_BYTES) {
            showToast(`File too large: ${file.name}`, 'error');
            continue;
        }
        const ext = clientFileExtension(file.name);
        if (!ALLOWED_UPLOAD_EXTENSIONS.has(ext)) {
            showToast(`File type not allowed: ${file.name}`, 'error');
            continue;
        }
        const toastId = nextToastId('up');
        showToast('', 'loading', {
            id: toastId,
            title: file.name,
            description: 'Uploading…',
            persistent: true,
            progress: 0
        });
        queue.push({ file, toastId });
    }
    if (queue.length === 0) return;

    const workerCount = Math.min(UPLOAD_CONCURRENCY, queue.length);
    const workers = [];
    for (let i = 0; i < workerCount; i++) {
        workers.push((async () => {
            while (queue.length > 0) {
                const next = queue.shift();
                if (next) {
                    await uploadFile(next.file, next.toastId);
                }
            }
        })());
    }
    await Promise.all(workers);
}

function clientFileExtension(fileName) {
    const parts = String(fileName || '').split('.');
    if (parts.length < 2) return '';
    return parts.pop().toLowerCase();
}

function postFormWithProgress(formData, csrfAction, onProgress) {
    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open('POST', 'api.php');
        xhr.setRequestHeader('X-CSRF-Token', csrfToken(csrfAction));
        xhr.upload.onprogress = (event) => {
            if (event.lengthComputable && typeof onProgress === 'function') {
                onProgress(event.loaded / event.total);
            }
        };
        xhr.onload = () => {
            let data;
            try {
                data = JSON.parse(xhr.responseText);
            } catch (error) {
                reject(new Error('Invalid server response'));
                return;
            }
            resolve(data);
        };
        xhr.onerror = () => reject(new Error('Network error'));
        xhr.onabort = () => reject(new Error('Upload cancelled'));
        xhr.send(formData);
    });
}

function sleep(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

async function waitForCompression(fileId) {
    const deadline = Date.now() + COMPRESSION_TIMEOUT_MS;
    while (Date.now() < deadline) {
        await sleep(COMPRESSION_POLL_MS);
        try {
            const response = await fetch('api.php?action=file_status&id=' + encodeURIComponent(fileId));
            const data = await response.json();
            if (data.success && data.file && data.file.compression !== 'pending') {
                return data;
            }
        } catch (error) {
            console.error('Error checking compression status:', error);
        }
    }
    return null;
}

function applyFileUpdate(fileData) {
    if (!fileData || !fileData.id) return;
    const idx = files.findIndex((item) => item.id === fileData.id);
    if (idx === -1) return;
    files[idx] = { ...files[idx], ...fileData };
    renderFiles();
    updateStats();
}

async function maybeWaitForCompression(data, toastId) {
    const file = data && data.file;
    const compression = (data && data.compression) || (file && file.compression);
    if (compression !== 'pending' || !file || !file.id) return;

    updateToast(toastId, {
        description: 'Compressing…',
        type: 'loading',
        persistent: true,
        progress: 'indeterminate'
    });

    const result = await waitForCompression(file.id);
    if (result && result.file) {
        applyFileUpdate(result.file);
    }
}

function resumeCompressionToasts() {
    files.forEach((file) => {
        if (!file || file.compression !== 'pending') return;
        const toastId = 'cmp-' + file.id;
        showToast('', 'loading', {
            id: toastId,
            title: file.name,
            description: 'Compressing…',
            persistent: true,
            progress: 'indeterminate'
        });
        waitForCompression(file.id).then((result) => {
            if (result && result.file) {
                applyFileUpdate(result.file);
            }
            finishFileToast(toastId, { type: 'success', description: 'Uploaded' });
        });
    });
}

async function uploadFile(file, toastId) {
    const formData = new FormData();
    formData.append('file', file);
    formData.append('action', 'upload');
    formData.append('category', currentCategory);
    addCsrfToken(formData, 'upload');

    updateToast(toastId, {
        description: 'Uploading…',
        type: 'loading',
        persistent: true,
        progress: 0
    });

    try {
        const data = await postFormWithProgress(formData, 'upload', (ratio) => {
            const pct = Math.round(ratio * 100);
            updateToast(toastId, {
                description: pct >= 100 ? 'Uploading…' : 'Uploading ' + pct + '%',
                progress: ratio,
                type: 'loading',
                persistent: true
            });
        });

        if (!data.success) {
            finishFileToast(toastId, { type: 'error', description: data.error || 'Upload failed' });
            return;
        }

        files.push(data.file);
        renderFiles();
        updateStats();

        await maybeWaitForCompression(data, toastId);
        finishFileToast(toastId, { type: 'success', description: 'Uploaded' });
    } catch (error) {
        console.error('Error uploading file:', error);
        finishFileToast(toastId, { type: 'error', description: 'Upload failed' });
    }
}

async function copyLink(file) {
    const link = file.shareUrl || (window.location.origin + '/s/' + file.id + '/');
    await copyToClipboard(link);
    lastCopiedFileId = file.id;
    renderFiles();
    showToast('Link copied to clipboard', 'success');
}

async function copyCategoryLink(category) {
    try {
        const response = await fetch('api.php', {
            method: 'POST',
            headers: jsonHeaders('folder_share'),
            body: JSON.stringify({ action: 'folder_share', category })
        });
        const data = await response.json();

    if (data.success && data.shareId) {
        const link = data.shareUrl || (window.location.origin + '/f/' + data.shareId);
        await copyToClipboard(link);
        sharedCategories.add(category);
        renderCategories();
        showToast('Folder link copied', 'success');
    } else {
        showToast(data.error || 'Error copying link', 'error');
    }
    } catch (error) {
        console.error('Error copying folder link:', error);
        showToast('Error copying link', 'error');
    }
}

async function copyToClipboard(text) {
    if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(text);
        return;
    }

    const textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.setAttribute('readonly', '');
    textarea.style.position = 'fixed';
    textarea.style.opacity = '0';
    document.body.appendChild(textarea);
    textarea.select();
    const copied = document.execCommand('copy');
    textarea.remove();

    if (!copied) {
        throw new Error('Clipboard API is not available');
    }
}

function openFile(file) {
    const link = file.previewUrl || ('/s/' + file.id + '.' + file.extension);
    window.open(link, '_blank');
}

let currentFileForRename = null;

function renameFile(file) {
    currentFileForRename = file;
    const fileRenameModal = document.getElementById('fileRenameModal');
    const fileNameInput = document.getElementById('fileNameInput');

    fileNameInput.value = file.name;
    fileRenameModal.style.display = 'flex';
    fileRenameModal.setAttribute('aria-modal', 'true');
    fileRenameModal.setAttribute('role', 'dialog');

    setTimeout(() => {
        fileNameInput.focus();
        fileNameInput.select();
    }, 100);
}

async function handleSaveFileRename() {
    const newName = document.getElementById('fileNameInput').value.trim();

    if (!newName || newName === currentFileForRename.name) {
        closeFileRenameModal();
        return;
    }

    try {
        const response = await fetch('api.php', {
            method: 'POST',
            headers: jsonHeaders('rename'),
            body: JSON.stringify({ action: 'rename', id: currentFileForRename.id, name: newName })
        });
        const data = await response.json();

        if (data.success) {
            const fileIndex = files.findIndex(f => f.id === currentFileForRename.id);
            if (fileIndex !== -1) {
                files[fileIndex].name = newName;
                renderFiles();
                updateStats();
                showToast('File renamed', 'success');
            }
        } else {
            showToast(data.error || 'Error renaming file', 'error');
        }
    } catch (error) {
        console.error('Error renaming file:', error);
        showToast('Error renaming file', 'error');
    }

    closeFileRenameModal();
}

function closeFileRenameModal() {
    document.getElementById('fileRenameModal').style.display = 'none';
    document.getElementById('fileNameInput').value = '';
    currentFileForRename = null;
}

function replaceFile(file) {
    const input = document.createElement('input');
    input.type = 'file';
    input.style.display = 'none';
    input.onchange = async (e) => {
        const newFile = e.target.files[0];
        if (!newFile) {
            document.body.removeChild(input);
            return;
        }

        if (newFile.size > MAX_UPLOAD_BYTES) {
            showToast('File too large', 'error');
            document.body.removeChild(input);
            return;
        }
        const ext = clientFileExtension(newFile.name);
        if (!ALLOWED_UPLOAD_EXTENSIONS.has(ext)) {
            showToast('File type not allowed', 'error');
            document.body.removeChild(input);
            return;
        }

        const formData = new FormData();
        formData.append('file', newFile);
        formData.append('action', 'replace');
        formData.append('id', file.id);
        addCsrfToken(formData, 'replace');

        const toastId = 'rp-' + file.id;
        showToast('', 'loading', {
            id: toastId,
            title: newFile.name,
            description: 'Uploading…',
            persistent: true,
            progress: 0
        });

        try {
            const data = await postFormWithProgress(formData, 'replace', (ratio) => {
                const pct = Math.round(ratio * 100);
                updateToast(toastId, {
                    description: pct >= 100 ? 'Uploading…' : 'Uploading ' + pct + '%',
                    progress: ratio,
                    type: 'loading',
                    persistent: true
                });
            });

            if (data.success) {
                const fileIndex = files.findIndex(f => f.id === file.id);
                if (fileIndex !== -1) {
                    files[fileIndex] = data.file;
                    renderFiles();
                }
                await maybeWaitForCompression(data, toastId);
                finishFileToast(toastId, { type: 'success', description: 'Uploaded' });
            } else {
                finishFileToast(toastId, { type: 'error', description: data.error || 'Could not replace file' });
            }
        } catch (error) {
            console.error('Error replacing file:', error);
            finishFileToast(toastId, { type: 'error', description: 'Could not replace file' });
        }

        document.body.removeChild(input);
    };

    document.body.appendChild(input);
    input.click();
}

function deleteFile(file) {
    const fileItems = document.querySelectorAll('.file-item');
    let fileElement = null;
    fileItems.forEach(item => {
        const fileNameEl = item.querySelector('.file-name');
        if (fileNameEl && fileNameEl.textContent === file.name) {
            fileElement = item;
        }
    });

    if (!fileElement) return;

    const fileName = fileElement.querySelector('.file-name');
    const fileIcon = fileElement.querySelector('.file-icon');
    const fileMeta = fileElement.querySelector('.file-meta');
    const fileActions = fileElement.querySelector('.file-actions');
    const originalNameOpacity = fileName.style.opacity;
    const originalIconOpacity = fileIcon.style.opacity;
    const originalMetaOpacity = fileMeta.style.opacity;
    const originalMetaText = fileMeta.textContent;
    const originalActionsHTML = fileActions.innerHTML;

    fileName.style.opacity = '0.5';
    fileIcon.style.opacity = '0.5';
    fileMeta.style.opacity = '0.9';
    fileMeta.textContent = 'File deleted';
    fileActions.innerHTML = `
        <button class="action-btn undo-btn" title="Undo delete">
            <img src="icons/undo.png" alt="Undo" class="icon-img">
        </button>
    `;
    attachUndoTimerBar(fileElement);

    const toastId = 'del-' + file.id;
    showToast('', 'loading', {
        id: toastId,
        title: file.name,
        description: 'Deleting…',
        persistent: true
    });

    const undoBtn = fileActions.querySelector('.undo-btn');
    let undoTimeout = null;
    let cancelled = false;

    const restoreFileRow = () => {
        removeUndoTimerBar(fileElement);
        fileName.style.opacity = originalNameOpacity;
        fileIcon.style.opacity = originalIconOpacity;
        fileMeta.style.opacity = originalMetaOpacity;
        fileMeta.textContent = originalMetaText;
        fileActions.innerHTML = originalActionsHTML;

        const copyBtn = fileActions.querySelector('[title="Copy link"]');
        const openBtn = fileActions.querySelector('[title="Open"]');
        const renameBtn = fileActions.querySelector('[title="Rename"]');
        const replaceBtn = fileActions.querySelector('[title="Replace file"]');
        const deleteBtn = fileActions.querySelector('[title="Delete"]');
        const categorySelect = fileActions.querySelector('.category-select');

        if (copyBtn) copyBtn.addEventListener('click', () => copyLink(file));
        if (openBtn) openBtn.addEventListener('click', () => openFile(file));
        if (renameBtn) renameBtn.addEventListener('click', () => renameFile(file));
        if (replaceBtn) replaceBtn.addEventListener('click', () => replaceFile(file));
        if (deleteBtn) deleteBtn.addEventListener('click', () => deleteFile(file));
        if (categorySelect) categorySelect.addEventListener('change', (e) => updateFileCategory(file.id, e.target.value));
    };

    const undoHandler = () => {
        cancelled = true;
        clearTimeout(undoTimeout);
        restoreFileRow();
        dismissToast(toastId);
    };

    undoBtn.addEventListener('click', undoHandler);

    undoTimeout = setTimeout(async () => {
        if (cancelled) return;

        try {
            const response = await fetch('api.php', {
                method: 'POST',
                headers: jsonHeaders('delete'),
                body: JSON.stringify({ action: 'delete', id: file.id })
            });
            const data = await response.json();

            if (data.success) {
                files = files.filter(f => f.id !== file.id);
                renderFiles();
                updateStats();
                finishFileToast(toastId, { type: 'success', description: 'Deleted' });
            } else {
                finishFileToast(toastId, { type: 'error', description: data.error || 'Could not delete file' });
                restoreFileRow();
            }
        } catch (error) {
            console.error('Error deleting file:', error);
            finishFileToast(toastId, { type: 'error', description: 'Could not delete file' });
            restoreFileRow();
        }
    }, UNDO_DELAY_MS);
}

async function updateFileCategory(fileId, newCategory) {
    try {
        const response = await fetch('api.php', {
            method: 'POST',
            headers: jsonHeaders('update_category'),
            body: JSON.stringify({ action: 'update_category', id: fileId, category: newCategory })
        });
        const data = await response.json();

        if (data.success) {
            const fileIndex = files.findIndex(f => f.id === fileId);
            if (fileIndex !== -1) {
                files[fileIndex].category = newCategory;
                renderFiles();
                showToast('Category updated', 'success');
            }
        } else {
            showToast(data.error || 'Error updating category', 'error');
        }
    } catch (error) {
        console.error('Error updating category:', error);
        showToast('Error updating category', 'error');
    }
}

function handleSearch(e) {
    searchQuery = e.target.value;
    clearSearch.style.display = searchQuery ? 'block' : 'none';
    clearTimeout(searchDebounceTimer);
    searchDebounceTimer = setTimeout(() => {
        renderFiles();
    }, 200);
}

function handleClearSearch() {
    searchInput.value = '';
    searchQuery = '';
    clearSearch.style.display = 'none';
    renderFiles();
}

function setSortBy(nextSort) {
    if (sortBy === nextSort) return;
    sortBy = nextSort;
    updateSortTabs();
    renderFiles();
}

function updateSortTabs() {
    const isDate = sortBy === 'date';
    sortByDate.classList.toggle('active', isDate);
    sortByDate.setAttribute('aria-selected', isDate ? 'true' : 'false');
    sortBySize.classList.toggle('active', !isDate);
    sortBySize.setAttribute('aria-selected', !isDate ? 'true' : 'false');
}

function updateStats() {
    const fileCount = document.getElementById('fileCount');
    const totalSize = document.getElementById('totalSize');
    const todayCount = document.getElementById('todayCount');

    const scoped = files.filter(f => fileMatchesCategory(f, currentCategory));

    fileCount.textContent = scoped.length;

    const totalBytes = scoped.reduce((sum, f) => sum + f.size, 0);
    totalSize.textContent = formatSize(totalBytes);

    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const todayFiles = scoped.filter(f => new Date(f.uploadDate) >= today);
    todayCount.textContent = todayFiles.length;
}

function showToast(message, type = 'info', options = {}) {
    const id = options.id || nextToastId('msg');
    const persistent = options.persistent === true;
    const title = options.title !== undefined ? options.title : message;
    const description = options.description !== undefined ? options.description : (options.title ? message : '');
    ensureToastElement(id, {
        title,
        description,
        type,
        progress: options.progress
    });
    clearToastTimer(id);
    if (!persistent) {
        const duration = options.duration
            || (type === 'error' ? TOAST_ERROR_MS : type === 'success' ? TOAST_SUCCESS_MS : TOAST_INFO_MS);
        toastTimers.set(id, setTimeout(() => dismissToast(id), duration));
    }
    return id;
}

function nextToastId(prefix) {
    toastSeq += 1;
    return prefix + '-' + toastSeq;
}

let toastSeq = 0;
const toastTimers = new Map();

function prefersReducedMotion() {
    return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

function toastIconSvg(type) {
    if (type === 'loading') {
        return '<svg class="toast-spinner" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-dasharray="42 18"/></svg>';
    }
    if (type === 'success') {
        return '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M5 10.5l3.4 3.4L15 6.8" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    }
    if (type === 'error') {
        return '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M6 6l8 8M14 6l-8 8" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>';
    }
    return '<svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="7.2" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M10 9.2v4.2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><circle cx="10" cy="6.6" r="0.9" fill="currentColor"/></svg>';
}

function currentToastProgress(toast) {
    const wrap = toast.querySelector('.toast-progress');
    if (!wrap || wrap.hidden) return null;
    if (wrap.hasAttribute('data-indeterminate')) return 'indeterminate';
    const width = toast.querySelector('.toast-progress-bar').style.width || '0%';
    return Math.max(0, Math.min(1, parseFloat(width) / 100));
}

function renderToast(toast, options) {
    const type = options.type || 'info';
    toast.dataset.type = type;
    toast.setAttribute('role', 'status');
    toast.setAttribute('aria-live', type === 'error' ? 'assertive' : 'polite');
    toast.querySelector('.toast-icon').innerHTML = toastIconSvg(type);

    const titleEl = toast.querySelector('.toast-title');
    const descEl = toast.querySelector('.toast-description');
    titleEl.textContent = options.title || '';
    titleEl.hidden = !options.title;
    descEl.textContent = options.description || '';
    descEl.hidden = !options.description;

    const progressWrap = toast.querySelector('.toast-progress');
    const progressBar = toast.querySelector('.toast-progress-bar');
    if (typeof options.progress === 'number') {
        progressWrap.hidden = false;
        progressWrap.removeAttribute('data-indeterminate');
        progressBar.style.width = Math.round(Math.max(0, Math.min(1, options.progress)) * 100) + '%';
    } else if (options.progress === 'indeterminate') {
        progressWrap.hidden = false;
        progressWrap.setAttribute('data-indeterminate', 'true');
        progressBar.style.width = '';
    } else {
        progressWrap.hidden = true;
        progressWrap.removeAttribute('data-indeterminate');
        progressBar.style.width = '0%';
    }
}

function ensureToastElement(id, options) {
    let toast = toastContainer.querySelector('[data-toast-id="' + id + '"]');
    if (!toast) {
        toast = document.createElement('div');
        toast.className = 'toast';
        toast.dataset.toastId = id;
        toast.innerHTML =
            '<div class="toast-icon"></div>' +
            '<div class="toast-body">' +
                '<div class="toast-title"></div>' +
                '<div class="toast-description"></div>' +
            '</div>' +
            '<div class="toast-progress" hidden><div class="toast-progress-bar"></div></div>';
        toastContainer.appendChild(toast);
        requestAnimationFrame(() => {
            toast.dataset.mounted = 'true';
        });
    }
    renderToast(toast, options);
    return toast;
}

function clearToastTimer(id) {
    const timer = toastTimers.get(id);
    if (timer) {
        clearTimeout(timer);
        toastTimers.delete(id);
    }
}

function updateToast(id, patch) {
    const toast = toastContainer.querySelector('[data-toast-id="' + id + '"]');
    if (!toast) {
        return showToast(patch.description || patch.title || '', patch.type || 'info', { id, ...patch });
    }
    const titleEl = toast.querySelector('.toast-title');
    const descEl = toast.querySelector('.toast-description');
    renderToast(toast, {
        title: patch.title !== undefined ? patch.title : titleEl.textContent,
        description: patch.description !== undefined ? patch.description : descEl.textContent,
        type: patch.type || toast.dataset.type,
        progress: patch.progress !== undefined ? patch.progress : currentToastProgress(toast)
    });
    if (patch.persistent === false) {
        const type = patch.type || toast.dataset.type;
        const duration = patch.duration || (type === 'error' ? TOAST_ERROR_MS : TOAST_SUCCESS_MS);
        clearToastTimer(id);
        toastTimers.set(id, setTimeout(() => dismissToast(id), duration));
    } else if (patch.persistent === true) {
        clearToastTimer(id);
    }
}

function dismissToast(id) {
    const toast = toastContainer.querySelector('[data-toast-id="' + id + '"]');
    clearToastTimer(id);
    if (!toast || toast.dataset.removed === 'true') return;
    toast.dataset.removed = 'true';
    toast.dataset.mounted = 'false';
    const delay = prefersReducedMotion() ? 0 : TOAST_EXIT_MS;
    setTimeout(() => toast.remove(), delay);
}

function finishFileToast(id, options) {
    updateToast(id, {
        type: options.type || 'success',
        description: options.description,
        title: options.title,
        progress: null,
        persistent: false
    });
}

init();
