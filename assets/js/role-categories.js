// assets/js/role-categories.js
// Role Categories Admin Page JavaScript

(function () {
    'use strict';

    const baseUrl = window.SF_BASE_URL || '';
    const allUsers = window.SF_ALL_USERS || [];

    /**
     * Get CSRF token from page
     * @returns {string} CSRF token value, or empty string if not found
     */
    function getCsrfToken() {
        return document.querySelector('input[name="csrf_token"]')?.value || '';
    }

    // Modal elements
    const categoryModal = document.getElementById('sfCategoryModal');
    const categoryModalTitle = document.getElementById('sfCategoryModalTitle');
    const categoryModalClose = document.getElementById('sfCategoryModalClose');
    const categoryForm = document.getElementById('sfCategoryForm');
    const categoryFormCancel = document.getElementById('sfCategoryFormCancel');

    const manageUsersModal = document.getElementById('sfManageUsersModal');
    const manageUsersModalTitle = document.getElementById('sfManageUsersModalTitle');
    const manageUsersModalClose = document.getElementById('sfManageUsersModalClose');
    const manageCategoryId = document.getElementById('manageCategoryId');
    const currentUsersList = document.getElementById('sfCurrentUsersList');
    const addUserSelect = document.getElementById('sfAddUserSelect');
    const addUserBtn = document.getElementById('sfAddUserBtn');

    // Buttons
    const addCategoryBtn = document.getElementById('sfAddCategoryBtn');
    const editCategoryBtns = document.querySelectorAll('.sf-edit-category-btn');
    const deleteCategoryBtns = document.querySelectorAll('.sf-delete-category-btn');
    const manageUsersBtns = document.querySelectorAll('.sf-manage-users-btn');

    // Open Add Category Modal
    if (addCategoryBtn) {
        addCategoryBtn.addEventListener('click', function () {
            categoryModalTitle.textContent = 'Lisää kategoria';
            categoryForm.reset();
            document.getElementById('categoryId').value = '';
            showModal(categoryModal);
        });
    }

    // Open Edit Category Modal
    editCategoryBtns.forEach(btn => {
        btn.addEventListener('click', async function () {
            const categoryId = this.dataset.id;

            try {
                const response = await fetch(`${baseUrl}/app/api/get_role_category.php?id=${categoryId}`);
                const data = await response.json();

                if (data.ok && data.category) {
                    const cat = data.category;
                    categoryModalTitle.textContent = 'Muokkaa kategoriaa';
                    document.getElementById('categoryId').value = cat.id;
                    document.getElementById('categoryName').value = cat.name;
                    document.getElementById('categoryType').value = cat.type;
                    document.getElementById('categoryWorksite').value = cat.worksite || '';
                    document.getElementById('categoryIsActive').checked = cat.is_active == 1;
                    showModal(categoryModal);
                } else {
                    window.sfToast('error', 'Virhe ladattaessa kategoriaa: ' + (data.error || 'Tuntematon virhe'));
                }
            } catch (error) {
                console.error('Error loading category:', error);
                window.sfToast('error', 'Virhe ladattaessa kategoriaa');
            }
        });
    });

    // Delete Category
    deleteCategoryBtns.forEach(btn => {
        btn.addEventListener('click', async function () {
            const categoryId = this.dataset.id;
            const categoryName = this.dataset.name;

            if (!confirm(`Haluatko varmasti poistaa kategorian "${categoryName}"?\n\nTämä poistaa myös kaikki käyttäjien liitokset tähän kategoriaan.`)) {
                return;
            }

            try {
                const response = await fetch(`${baseUrl}/app/api/delete_role_category.php`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': getCsrfToken()
                    },
                    body: JSON.stringify({ id: parseInt(categoryId) })
                });

                const data = await response.json();

                if (data.ok) {
                    window.sfToast('success', 'Kategoria poistettu');
                    location.reload();
                } else {
                    window.sfToast('error', 'Virhe poistettaessa kategoriaa: ' + (data.error || 'Tuntematon virhe'));
                }
            } catch (error) {
                console.error('Error deleting category:', error);
                window.sfToast('error', 'Virhe poistettaessa kategoriaa');
            }
        });
    });

    // Open Manage Users Modal
    manageUsersBtns.forEach(btn => {
        btn.addEventListener('click', async function () {
            const categoryId = this.dataset.id;
            const categoryName = this.dataset.name;

            manageUsersModalTitle.textContent = `Hallinnoi käyttäjiä: ${categoryName}`;
            manageCategoryId.value = categoryId;

            await loadCategoryUsers(categoryId);
            showModal(manageUsersModal);
        });
    });

    // Add User to Category
    if (addUserBtn) {
        addUserBtn.addEventListener('click', async function () {
            const userId = parseInt(addUserSelect.value);
            const categoryId = parseInt(manageCategoryId.value);

            if (!userId) {
                window.sfToast('error', 'Valitse käyttäjä');
                return;
            }

            try {
                const response = await fetch(`${baseUrl}/app/api/assign_user_to_category.php`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': getCsrfToken()
                    },
                    body: JSON.stringify({
                        user_id: userId,
                        category_id: categoryId
                    })
                });

                const data = await response.json();

                if (data.ok) {
                    window.sfToast('success', 'Käyttäjä lisätty');
                    addUserSelect.value = '';
                    await loadCategoryUsers(categoryId);
                } else {
                    window.sfToast('error', 'Virhe lisättäessä käyttäjää: ' + (data.error || 'Tuntematon virhe'));
                }
            } catch (error) {
                console.error('Error adding user:', error);
                window.sfToast('error', 'Virhe lisättäessä käyttäjää');
            }
        });
    }

    // Save Category Form
    if (categoryForm) {
        categoryForm.addEventListener('submit', async function (e) {
            e.preventDefault();

            const formData = {
                id: parseInt(document.getElementById('categoryId').value) || 0,
                name: document.getElementById('categoryName').value.trim(),
                type: document.getElementById('categoryType').value,
                worksite: document.getElementById('categoryWorksite').value || null,
                is_active: document.getElementById('categoryIsActive').checked
            };

            try {
                const response = await fetch(`${baseUrl}/app/api/save_role_category.php`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': getCsrfToken()
                    },
                    body: JSON.stringify(formData)
                });

                const data = await response.json();

                if (data.ok) {
                    window.sfToast('success', 'Kategoria tallennettu');
                    location.reload();
                } else {
                    window.sfToast('error', 'Virhe tallennettaessa kategoriaa: ' + (data.error || 'Tuntematon virhe'));
                }
            } catch (error) {
                console.error('Error saving category:', error);
                window.sfToast('error', 'Virhe tallennettaessa kategoriaa');
            }
        });
    }

    // Modal close handlers
    if (categoryModalClose) {
        categoryModalClose.addEventListener('click', () => hideModal(categoryModal));
    }

    if (categoryFormCancel) {
        categoryFormCancel.addEventListener('click', () => hideModal(categoryModal));
    }

    if (manageUsersModalClose) {
        manageUsersModalClose.addEventListener('click', () => hideModal(manageUsersModal));
    }

    // Close modal on outside click
    window.addEventListener('click', function (e) {
        if (e.target === categoryModal) {
            hideModal(categoryModal);
        }
        if (e.target === manageUsersModal) {
            hideModal(manageUsersModal);
        }
    });

    // Helper: Show modal
    function showModal(modal) {
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }

    // Helper: Hide modal
    function hideModal(modal) {
        modal.style.display = 'none';
        document.body.style.overflow = '';
    }

    // Helper: Load category users
    async function loadCategoryUsers(categoryId) {
        try {
            const response = await fetch(`${baseUrl}/app/api/get_role_category.php?id=${categoryId}`);
            const data = await response.json();

            if (data.ok && data.category) {
                renderCategoryUsers(data.category.users, categoryId);
            } else {
                currentUsersList.innerHTML = '<div class="sf-users-list-empty">Virhe ladattaessa käyttäjiä</div>';
            }
        } catch (error) {
            console.error('Error loading users:', error);
            currentUsersList.innerHTML = '<div class="sf-users-list-empty">Virhe ladattaessa käyttäjiä</div>';
        }
    }

    // Helper: Render category users
    function renderCategoryUsers(users, categoryId) {
        if (!users || users.length === 0) {
            currentUsersList.innerHTML = '<div class="sf-users-list-empty">Ei käyttäjiä tässä kategoriassa</div>';
            return;
        }

        currentUsersList.innerHTML = users.map(user => `
            <div class="sf-user-item">
                <div class="sf-user-info">
                    <div class="sf-user-name">${escapeHtml(user.first_name)} ${escapeHtml(user.last_name)}</div>
                    <div class="sf-user-email">${escapeHtml(user.email)}</div>
                </div>
                <button class="sf-user-remove" data-user-id="${user.id}" data-category-id="${categoryId}">
                    Poista
                </button>
            </div>
        `).join('');

        // Add remove user event listeners
        currentUsersList.querySelectorAll('.sf-user-remove').forEach(btn => {
            btn.addEventListener('click', async function () {
                const userId = parseInt(this.dataset.userId);
                const catId = parseInt(this.dataset.categoryId);

                if (!confirm('Haluatko varmasti poistaa käyttäjän tästä kategoriasta?')) {
                    return;
                }

                try {
                    const response = await fetch(`${baseUrl}/app/api/remove_user_from_category.php`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': getCsrfToken()
                        },
                        body: JSON.stringify({
                            user_id: userId,
                            category_id: catId
                        })
                    });

                    const data = await response.json();

                    if (data.ok) {
                        window.sfToast('success', 'Käyttäjä poistettu');
                        await loadCategoryUsers(catId);
                    } else {
                        window.sfToast('error', 'Virhe poistettaessa käyttäjää: ' + (data.error || 'Tuntematon virhe'));
                    }
                } catch (error) {
                    console.error('Error removing user:', error);
                    window.sfToast('error', 'Virhe poistettaessa käyttäjää');
                }
            });
        });
    }

    // Helper: Escape HTML
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Worksite Filter
    const GLOBAL_WORKSITE_VALUE = '__global__';
    const worksiteFilter = document.getElementById('sfWorksiteFilter');
    const filterCount = document.getElementById('sfFilterCount');

    if (worksiteFilter) {
        worksiteFilter.addEventListener('change', function () {
            const selectedWorksite = this.value;
            const cards = document.querySelectorAll('.sf-category-card');
            let visibleCount = 0;

            cards.forEach(card => {
                const cardWorksite = card.dataset.worksite || GLOBAL_WORKSITE_VALUE;

                if (selectedWorksite === '' || cardWorksite === selectedWorksite) {
                    card.classList.remove('sf-filtered-out');
                    visibleCount++;
                } else {
                    card.classList.add('sf-filtered-out');
                }
            });

            // Update count
            if (filterCount) {
                if (selectedWorksite === '') {
                    filterCount.textContent = '';
                } else {
                    filterCount.textContent = `${visibleCount} kategoriaa`;
                }
            }
        });

        // Initialize count
        const totalCards = document.querySelectorAll('.sf-category-card').length;
        if (filterCount && totalCards > 0) {
            filterCount.textContent = `${totalCards} kategoriaa yhteensä`;
        }
    }
})();