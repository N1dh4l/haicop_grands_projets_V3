// ================================================================
// FONCTION POUR OUVRIR LE MODAL DE MODIFICATION D'APPEL D'OFFRE
// ================================================================

/**
 * Ouvre le modal de modification et charge les données de l'appel d'offre
 * @param {number} idAppel - ID de l'appel d'offre à modifier
 */
function openEditAppelOffreModal(idAppel) {
    // Afficher le modal
    const modal = document.getElementById('editAppelOffreModal');
    modal.classList.add('show');
    modal.style.display = 'flex';
    
    // Afficher un loader pendant le chargement
    const modalBody = document.getElementById('editModalBody');
    modalBody.innerHTML = `
        <div style="text-align: center; padding: 40px;">
            <div class="loader" style="margin: 0 auto 20px;"></div>
            <p>جاري تحميل البيانات...</p>
        </div>
    `;
    
    // Charger les données de l'appel d'offre via AJAX
    fetch(`get_appel_offre_data.php?id=${idAppel}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Remplir le formulaire avec les données
                renderEditForm(data.appelOffre, data.lots, data.projets, data.fournisseurs);
            } else {
                showEditError(data.message || 'حدث خطأ في تحميل البيانات');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showEditError('حدث خطأ في الاتصال بالخادم');
        });
}

/**
 * Affiche le formulaire de modification avec les données chargées
 */
function renderEditForm(appelOffre, lots, projets, fournisseurs) {
    const modalBody = document.getElementById('editModalBody');
    
    // Stocker les fournisseurs globalement pour l'ajout de lots
    window.editFournisseurs = fournisseurs;
    
    modalBody.innerHTML = `
        <div id="editModalAlert"></div>
        
        <form id="editAppelOffreForm" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="${document.querySelector('[name="csrf_token"]').value}">
            <input type="hidden" name="action" value="update_appel_offre">
            <input type="hidden" name="idApp" id="editIdApp" value="${appelOffre.idApp}">
            
            <!-- SECTION PROJET -->
            <div class="form-group-full">
                <label>المشروع <span class="required">*</span></label>
                <select name="idpro" id="editIdPro" class="form-control" required>
                    <option value="">-- اختر المشروع --</option>
                    ${projets.map(p => `
                        <option value="${p.idPro}" ${p.idPro == appelOffre.idPro ? 'selected' : ''}>
                            ${escapeHtml(p.sujet)}
                        </option>
                    `).join('')}
                </select>
            </div>

            <!-- SECTION FICHIER -->
            <div class="form-group-full">
                <label>ملف الإسناد (اختياري - اترك فارغاً للاحتفاظ بالملف الحالي)</label>
                <input type="file" name="fichier" id="editFichier" class="form-control" 
                       accept=".pdf,.doc,.docx,.xls,.xlsx">
                <small style="color: #666; font-size: 12px; display: block; margin-top: 5px;">
                    الحجم الأقصى: 10MB - الأنواع المقبولة: PDF, Word, Excel
                </small>
                ${appelOffre.documentPath ? `
                    <div style="margin-top: 10px; padding: 10px; background: #f0f0f0; border-radius: 6px;">
                        <strong>الملف الحالي:</strong> 
                        <a href="${appelOffre.documentPath}" target="_blank" style="color: #667eea;">
                            📄 عرض الملف
                        </a>
                    </div>
                ` : ''}
            </div>

            <!-- SECTION LOTS -->
            <div class="lots-section">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <h3>📦 الصفقات</h3>
                    <button type="button" class="btn-add-lot" onclick="addEditLotRow()">
                        ➕ إضافة صفقة
                    </button>
                </div>

                <table class="lots-table">
                    <thead>
                        <tr>
                            <th>الصفقة <span class="required">*</span></th>
                            <th>صاحب الصفقة <span class="required">*</span></th>
                            <th>المبلغ <span class="required">*</span></th>
                            <th>الإجراء</th>
                        </tr>
                    </thead>
                    <tbody id="editLotsTableBody">
                        ${renderEditLots(lots, fournisseurs)}
                    </tbody>
                </table>
            </div>

            <!-- BOUTONS D'ACTION -->
            <div style="margin-top: 30px; text-align: center;">
                <button type="submit" class="btn btn-success" style="min-width: 150px;">
                    ✓ حفظ التعديلات
                </button>
                <button type="button" class="btn btn-secondary" onclick="closeEditAppelOffreModal()" 
                        style="min-width: 150px;">
                    ✕ إلغاء
                </button>
            </div>
        </form>
    `;
    
    // Initialiser le compteur de lots
    window.editLotCounter = lots.length;
    
    // Attacher l'événement de soumission du formulaire
    document.getElementById('editAppelOffreForm').addEventListener('submit', handleEditFormSubmit);
    
    // Attacher la validation du fichier
    document.getElementById('editFichier').addEventListener('change', validateEditFile);
}

/**
 * Génère le HTML des lignes de lots pour le formulaire d'édition
 */
function renderEditLots(lots, fournisseurs) {
    return lots.map((lot, index) => `
        <tr id="edit-lot-row-${index}">
            <td>
                <input type="text" 
                       name="lots[${index}][sujetLot]" 
                       class="form-control" 
                       value="${escapeHtml(lot.sujetLot)}" 
                       placeholder="موضوع الصفقة" 
                       required>
            </td>
            <td>
                <select name="lots[${index}][idFournisseur]" class="form-control" required>
                    <option value="">-- اختر صاحب الصفقة --</option>
                    ${fournisseurs.map(f => `
                        <option value="${f.idFournisseur}" ${f.idFournisseur == lot.idFournisseur ? 'selected' : ''}>
                            ${escapeHtml(f.nomFournisseur)}
                        </option>
                    `).join('')}
                </select>
            </td>
            <td>
                <input type="number" 
                       name="lots[${index}][somme]" 
                       class="form-control" 
                       value="${lot.somme}" 
                       step="0.01" 
                       min="0" 
                       placeholder="0.00" 
                       required>
            </td>
            <td>
                <button type="button" 
                        class="btn-remove-lot" 
                        onclick="removeEditLotRow(${index})"
                        ${lots.length === 1 ? 'disabled' : ''}>
                    🗑️
                </button>
            </td>
        </tr>
    `).join('');
}

/**
 * Ajoute une nouvelle ligne de lot dans le formulaire d'édition
 */
function addEditLotRow() {
    window.editLotCounter = window.editLotCounter || 0;
    window.editLotCounter++;
    
    const tbody = document.getElementById('editLotsTableBody');
    const rowId = `edit-lot-row-${window.editLotCounter}`;
    
    const fournisseurOptions = window.editFournisseurs.map(f => `
        <option value="${f.idFournisseur}">${escapeHtml(f.nomFournisseur)}</option>
    `).join('');
    
    const newRow = document.createElement('tr');
    newRow.id = rowId;
    newRow.innerHTML = `
        <td>
            <input type="text" 
                   name="lots[${window.editLotCounter}][sujetLot]" 
                   class="form-control" 
                   placeholder="موضوع الصفقة" 
                   required>
        </td>
        <td>
            <select name="lots[${window.editLotCounter}][idFournisseur]" class="form-control" required>
                <option value="">-- اختر صاحب الصفقة --</option>
                ${fournisseurOptions}
            </select>
        </td>
        <td>
            <input type="number" 
                   name="lots[${window.editLotCounter}][somme]" 
                   class="form-control" 
                   step="0.01" 
                   min="0" 
                   placeholder="0.00" 
                   required>
        </td>
        <td>
            <button type="button" 
                    class="btn-remove-lot" 
                    onclick="removeEditLotRow(${window.editLotCounter})">
                🗑️
            </button>
        </td>
    `;
    
    tbody.appendChild(newRow);
}

/**
 * Supprime une ligne de lot du formulaire d'édition
 */
function removeEditLotRow(rowId) {
    const row = document.getElementById(`edit-lot-row-${rowId}`);
    const tbody = document.getElementById('editLotsTableBody');
    
    // Vérifier qu'il reste au moins un lot
    if (tbody.children.length <= 1) {
        showEditAlert('يجب أن تحتوي الصفقة على قسط واحد على الأقل', 'warning');
        return;
    }
    
    if (row) {
        row.remove();
    }
}

/**
 * Gère la soumission du formulaire de modification
 */
function handleEditFormSubmit(e) {
    e.preventDefault();
    
    const form = e.target;
    const formData = new FormData(form);
    const alertDiv = document.getElementById('editModalAlert');
    
    // Désactiver le bouton de soumission
    const submitBtn = form.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.disabled = true;
    submitBtn.innerHTML = '⏳ جاري الحفظ...';
    
    // Afficher un message de chargement
    alertDiv.innerHTML = `
        <div style="background: #d1ecf1; color: #0c5460; padding: 12px; border-radius: 8px; margin-bottom: 20px;">
            جاري حفظ التعديلات...
        </div>
    `;
    
    // Envoyer les données
    fetch('appels_d_offres.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Afficher un message de succès
            alertDiv.innerHTML = `
                <div style="background: #d4edda; color: #155724; padding: 12px; border-radius: 8px; margin-bottom: 20px;">
                    ✓ ${data.message}
                </div>
            `;
            
            // Recharger la page après 1.5 secondes
            setTimeout(() => {
                window.location.reload();
            }, 1500);
        } else {
            // Afficher le message d'erreur
            alertDiv.innerHTML = `
                <div style="background: #f8d7da; color: #721c24; padding: 12px; border-radius: 8px; margin-bottom: 20px;">
                    ✕ ${data.message}
                </div>
            `;
            
            // Réactiver le bouton
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alertDiv.innerHTML = `
            <div style="background: #f8d7da; color: #721c24; padding: 12px; border-radius: 8px; margin-bottom: 20px;">
                ✕ حدث خطأ في الاتصال بالخادم
            </div>
        `;
        
        // Réactiver le bouton
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalText;
    });
}

/**
 * Valide le fichier uploadé
 */
function validateEditFile(e) {
    const file = e.target.files[0];
    
    if (!file) return;
    
    // Vérifier la taille (max 10MB)
    const maxSize = 10 * 1024 * 1024; // 10MB en bytes
    if (file.size > maxSize) {
        showEditAlert('حجم الملف يجب أن يكون أقل من 10 ميغابايت', 'error');
        e.target.value = '';
        return false;
    }
    
    // Vérifier le type de fichier
    const allowedTypes = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
    ];
    
    if (!allowedTypes.includes(file.type)) {
        showEditAlert('نوع الملف غير مقبول. يرجى اختيار ملف PDF أو Word أو Excel', 'error');
        e.target.value = '';
        return false;
    }
    
    return true;
}

/**
 * Ferme le modal de modification
 */
function closeEditAppelOffreModal() {
    const modal = document.getElementById('editAppelOffreModal');
    modal.classList.remove('show');
    modal.style.display = 'none';
    
    // Nettoyer le contenu
    const modalBody = document.getElementById('editModalBody');
    modalBody.innerHTML = '';
    
    // Réinitialiser les variables globales
    window.editLotCounter = 0;
    window.editFournisseurs = [];
}

/**
 * Affiche un message d'alerte dans le modal d'édition
 */
function showEditAlert(message, type = 'info') {
    const alertDiv = document.getElementById('editModalAlert');
    
    const colors = {
        success: { bg: '#d4edda', color: '#155724', icon: '✓' },
        error: { bg: '#f8d7da', color: '#721c24', icon: '✕' },
        warning: { bg: '#fff3cd', color: '#856404', icon: '⚠️' },
        info: { bg: '#d1ecf1', color: '#0c5460', icon: 'ℹ️' }
    };
    
    const style = colors[type] || colors.info;
    
    alertDiv.innerHTML = `
        <div style="background: ${style.bg}; color: ${style.color}; padding: 12px; border-radius: 8px; margin-bottom: 20px;">
            ${style.icon} ${message}
        </div>
    `;
    
    // Faire défiler vers le haut du modal
    document.querySelector('.modal-content').scrollTop = 0;
}

/**
 * Affiche une erreur dans le modal
 */
function showEditError(message) {
    const modalBody = document.getElementById('editModalBody');
    modalBody.innerHTML = `
        <div style="text-align: center; padding: 40px;">
            <div style="font-size: 48px; margin-bottom: 20px;">❌</div>
            <p style="color: #dc3545; font-size: 16px;">${message}</p>
            <button onclick="closeEditAppelOffreModal()" class="btn btn-secondary" style="margin-top: 20px;">
                إغلاق
            </button>
        </div>
    `;
}

/**
 * Échappe les caractères HTML pour éviter les injections XSS
 */
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// ================================================================
// FONCTION POUR OUVRIR LE MODAL DE SUPPRESSION D'APPEL D'OFFRE
// ================================================================

/**
 * Ouvre le modal de confirmation de suppression
 * @param {number} idAppel - ID de l'appel d'offre à supprimer
 * @param {string} projetNom - Nom du projet (pour affichage)
 */
function openDeleteAppelOffreModal(idAppel, projetNom) {
    const modal = document.getElementById('deleteAppelOffreModal');
    modal.classList.add('show');
    modal.style.display = 'flex';
    
    // Remplir le contenu du modal
    const modalBody = document.getElementById('deleteModalBody');
    modalBody.innerHTML = `
        <div style="text-align: center; padding: 20px;">
            <div style="font-size: 64px; color: #dc3545; margin-bottom: 20px;">⚠️</div>
            
            <h3 style="margin-bottom: 15px; color: #333;">هل أنت متأكد من حذف هذه الصفقة؟</h3>
            
            <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin: 20px 0;">
                <strong>المشروع:</strong> ${escapeHtml(projetNom)}
            </div>
            
            <p style="color: #666; margin-bottom: 30px;">
                سيتم حذف جميع البيانات المرتبطة بهذه الصفقة بشكل نهائي ولا يمكن التراجع عن هذا الإجراء.
            </p>
            
            <div id="deleteModalAlert"></div>
            
            <form id="deleteAppelOffreForm" onsubmit="handleDeleteFormSubmit(event, ${idAppel})">
                <input type="hidden" name="csrf_token" value="${document.querySelector('[name="csrf_token"]').value}">
                <input type="hidden" name="action" value="delete_appel_offre">
                <input type="hidden" name="idApp" value="${idAppel}">
                
                <div style="display: flex; gap: 15px; justify-content: center;">
                    <button type="submit" class="btn btn-danger" style="min-width: 150px;">
                        🗑️ نعم، احذف الصفقة
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="closeDeleteAppelOffreModal()" 
                            style="min-width: 150px;">
                        ✕ إلغاء
                    </button>
                </div>
            </form>
        </div>
    `;
}

/**
 * Gère la soumission du formulaire de suppression
 */
function handleDeleteFormSubmit(e, idAppel) {
    e.preventDefault();
    
    const form = e.target;
    const formData = new FormData(form);
    const alertDiv = document.getElementById('deleteModalAlert');
    
    // Désactiver les boutons
    const deleteBtn = form.querySelector('button[type="submit"]');
    const cancelBtn = form.querySelector('button[type="button"]');
    const originalText = deleteBtn.innerHTML;
    
    deleteBtn.disabled = true;
    cancelBtn.disabled = true;
    deleteBtn.innerHTML = '⏳ جاري الحذف...';
    
    // Afficher un message de chargement
    alertDiv.innerHTML = `
        <div style="background: #fff3cd; color: #856404; padding: 12px; border-radius: 8px; margin-bottom: 20px;">
            جاري حذف الصفقة...
        </div>
    `;
    
    // Envoyer la demande de suppression
    fetch('appels_d_offres.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Afficher un message de succès
            alertDiv.innerHTML = `
                <div style="background: #d4edda; color: #155724; padding: 12px; border-radius: 8px; margin-bottom: 20px;">
                    ✓ ${data.message}
                </div>
            `;
            
            // Recharger la page après 1.5 secondes
            setTimeout(() => {
                window.location.reload();
            }, 1500);
        } else {
            // Afficher le message d'erreur
            alertDiv.innerHTML = `
                <div style="background: #f8d7da; color: #721c24; padding: 12px; border-radius: 8px; margin-bottom: 20px;">
                    ✕ ${data.message}
                </div>
            `;
            
            // Réactiver les boutons
            deleteBtn.disabled = false;
            cancelBtn.disabled = false;
            deleteBtn.innerHTML = originalText;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alertDiv.innerHTML = `
            <div style="background: #f8d7da; color: #721c24; padding: 12px; border-radius: 8px; margin-bottom: 20px;">
                ✕ حدث خطأ في الاتصال بالخادم
            </div>
        `;
        
        // Réactiver les boutons
        deleteBtn.disabled = false;
        cancelBtn.disabled = false;
        deleteBtn.innerHTML = originalText;
    });
}

/**
 * Ferme le modal de suppression
 */
function closeDeleteAppelOffreModal() {
    const modal = document.getElementById('deleteAppelOffreModal');
    modal.classList.remove('show');
    modal.style.display = 'none';
    
    // Nettoyer le contenu
    const modalBody = document.getElementById('deleteModalBody');
    modalBody.innerHTML = '';
}

// ================================================================
// GESTION DES ÉVÉNEMENTS GLOBAUX
// ================================================================

// Fermer les modals en cliquant en dehors
window.addEventListener('click', function(event) {
    const editModal = document.getElementById('editAppelOffreModal');
    const deleteModal = document.getElementById('deleteAppelOffreModal');
    
    if (event.target === editModal) {
        closeEditAppelOffreModal();
    }
    
    if (event.target === deleteModal) {
        closeDeleteAppelOffreModal();
    }
});

// Fermer les modals avec la touche Échap
window.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeEditAppelOffreModal();
        closeDeleteAppelOffreModal();
    }
});
