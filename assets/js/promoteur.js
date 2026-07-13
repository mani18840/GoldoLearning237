/* ===== PROMOTEUR.JS ===== */
/* Même logique que enseignant.js / etudiant.js :
   - Vérification session
   - Navigation entre vues sans rechargement
   - Appels AJAX vers api/promoteur.php
   - Le promoteur ne valide PAS les cours (publication directe par l'enseignant) :
     il peut seulement consulter et modérer (activer/désactiver cours, bloquer comptes)
*/

const API_URL = "../api/promoteur.php";

let state = {
    user: null,
    coursListe: [],
    enseignantsListe: [],
    etudiantsListe: [],
    confirmAction: null // callback appelé si l'utilisateur confirme la modal
};

/* ---------- Utilitaires AJAX ---------- */

async function apiGet(action, params = {}) {
    const query = new URLSearchParams({ action, ...params }).toString();
    const res = await fetch(`${API_URL}?${query}`, { credentials: "include" });
    return res.json();
}

async function apiPost(action, data = {}) {
    const res = await fetch(`${API_URL}?action=${action}`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        credentials: "include",
        body: JSON.stringify(data)
    });
    return res.json();
}

/* ---------- Vérification session ---------- */

async function verifierSession() {
    try {
        const res = await fetch("../api/auth.php?action=check", { credentials: "include" });
        const data = await res.json();

        if (!data.success || data.role !== "promoteur") {
            window.location.href = "../login.html";
            return;
        }

        state.user = data.user;
        document.getElementById("userNomSidebar").textContent = data.user.nom;
        document.getElementById("userNomTop").textContent = data.user.nom;

        initApp();
    } catch (err) {
        console.error("Erreur vérification session :", err);
        window.location.href = "../login.html";
    }
}

/* ---------- Navigation entre vues ---------- */

function afficherVue(viewName) {
    document.querySelectorAll(".view").forEach(v => v.classList.remove("active"));
    document.querySelectorAll(".nav-item").forEach(n => n.classList.remove("active"));

    document.getElementById(`view-${viewName}`)?.classList.add("active");
    document.querySelector(`.nav-item[data-view="${viewName}"]`)?.classList.add("active");

    const titres = {
        apercu: "Aperçu de la plateforme",
        cours: "Tous les cours",
        enseignants: "Enseignants",
        etudiants: "Étudiants"
    };
    document.getElementById("viewTitle").textContent = titres[viewName] || "";

    if (viewName === "apercu") chargerApercu();
    if (viewName === "cours") chargerCours();
    if (viewName === "enseignants") chargerEnseignants();
    if (viewName === "etudiants") chargerEtudiants();

    document.getElementById("sidebar").classList.remove("open");
}

document.addEventListener("click", (e) => {
    const target = e.target.closest("[data-view]");
    if (target) {
        e.preventDefault();
        afficherVue(target.dataset.view);
    }
});

/* ---------- Aperçu ---------- */

async function chargerApercu() {
    const data = await apiGet("stats");
    if (!data.success) return;

    document.getElementById("statTotalCours").textContent = data.stats.total_cours;
    document.getElementById("statTotalEnseignants").textContent = data.stats.total_enseignants;
    document.getElementById("statTotalEtudiants").textContent = data.stats.total_etudiants;
    document.getElementById("statTotalCertificats").textContent = data.stats.total_certificats;

    const tbody = document.querySelector("#tableRecents tbody");
    if (!data.stats.cours_recents || data.stats.cours_recents.length === 0) {
        tbody.innerHTML = `<tr class="empty-row"><td colspan="5">Aucun cours publié pour l'instant.</td></tr>`;
        return;
    }

    tbody.innerHTML = data.stats.cours_recents.map(c => `
        <tr>
            <td>${escapeHtml(c.titre)}</td>
            <td>${escapeHtml(c.enseignant_nom)}</td>
            <td>${formatDate(c.date_creation)}</td>
            <td>${c.nb_inscrits}</td>
            <td class="table-actions">
                <button class="btn-icon" onclick="voirCours(${c.id})">Voir</button>
            </td>
        </tr>
    `).join("");
}

function voirCours(id) {
    afficherVue("cours");
    setTimeout(() => {
        document.getElementById("searchCours").value = "";
        const row = document.querySelector(`#tableCours tr[data-cours-id="${id}"]`);
        if (row) row.scrollIntoView({ behavior: "smooth", block: "center" });
    }, 300);
}
window.voirCours = voirCours;

/* ---------- Cours ---------- */

async function chargerCours() {
    const tbody = document.querySelector("#tableCours tbody");
    tbody.innerHTML = `<tr class="empty-row"><td colspan="5">Chargement...</td></tr>`;

    const data = await apiGet("cours-liste");
    if (!data.success) {
        tbody.innerHTML = `<tr class="empty-row"><td colspan="5">Erreur de chargement.</td></tr>`;
        return;
    }

    state.coursListe = data.cours;
    renderCours(data.cours);
}

function renderCours(liste) {
    const tbody = document.querySelector("#tableCours tbody");
    if (liste.length === 0) {
        tbody.innerHTML = `<tr class="empty-row"><td colspan="5">Aucun cours trouvé.</td></tr>`;
        return;
    }

    tbody.innerHTML = liste.map(c => `
        <tr data-cours-id="${c.id}">
            <td>${escapeHtml(c.titre)}</td>
            <td>${escapeHtml(c.enseignant_nom)}</td>
            <td><span class="badge ${c.publie ? "badge-active" : "badge-neutral"}">${c.publie ? "Publié" : "Brouillon"}</span></td>
            <td>${c.nb_inscrits}</td>
            <td class="table-actions">
                <button class="btn-icon" onclick="toggleCours(${c.id}, ${c.publie ? 0 : 1})">
                    ${c.publie ? "Désactiver" : "Activer"}
                </button>
            </td>
        </tr>
    `).join("");
}

document.getElementById("searchCours").addEventListener("input", (e) => {
    const q = e.target.value.toLowerCase();
    renderCours(state.coursListe.filter(c =>
        c.titre.toLowerCase().includes(q) || c.enseignant_nom.toLowerCase().includes(q)
    ));
});

function toggleCours(coursId, nouveauStatut) {
    ouvrirConfirmation(
        nouveauStatut ? "Réactiver ce cours ?" : "Désactiver ce cours ?",
        nouveauStatut
            ? "Le cours redeviendra visible dans le catalogue étudiant."
            : "Le cours ne sera plus visible dans le catalogue étudiant, mais les étudiants déjà inscrits garderont accès à leur contenu.",
        async () => {
            const data = await apiPost("cours-toggle", { cours_id: coursId, publie: nouveauStatut });
            if (data.success) {
                chargerCours();
            } else {
                alert(data.message || "Erreur.");
            }
        }
    );
}
window.toggleCours = toggleCours;

/* ---------- Enseignants ---------- */

async function chargerEnseignants() {
    const tbody = document.querySelector("#tableEnseignants tbody");
    tbody.innerHTML = `<tr class="empty-row"><td colspan="5">Chargement...</td></tr>`;

    const data = await apiGet("enseignants-liste");
    if (!data.success) {
        tbody.innerHTML = `<tr class="empty-row"><td colspan="5">Erreur de chargement.</td></tr>`;
        return;
    }

    state.enseignantsListe = data.enseignants;
    renderEnseignants(data.enseignants);
}

function renderEnseignants(liste) {
    const tbody = document.querySelector("#tableEnseignants tbody");
    if (liste.length === 0) {
        tbody.innerHTML = `<tr class="empty-row"><td colspan="5">Aucun enseignant trouvé.</td></tr>`;
        return;
    }

    tbody.innerHTML = liste.map(u => `
        <tr>
            <td>${escapeHtml(u.nom)}</td>
            <td>${escapeHtml(u.email)}</td>
            <td>${u.nb_cours}</td>
            <td><span class="badge ${u.actif ? "badge-active" : "badge-inactive"}">${u.actif ? "Actif" : "Bloqué"}</span></td>
            <td class="table-actions">
                <button class="btn-icon ${u.actif ? "danger" : ""}" onclick="toggleUtilisateur(${u.id}, ${u.actif ? 0 : 1}, 'enseignant')">
                    ${u.actif ? "Bloquer" : "Réactiver"}
                </button>
            </td>
        </tr>
    `).join("");
}

document.getElementById("searchEnseignants").addEventListener("input", (e) => {
    const q = e.target.value.toLowerCase();
    renderEnseignants(state.enseignantsListe.filter(u =>
        u.nom.toLowerCase().includes(q) || u.email.toLowerCase().includes(q)
    ));
});

/* ---------- Étudiants ---------- */

async function chargerEtudiants() {
    const tbody = document.querySelector("#tableEtudiants tbody");
    tbody.innerHTML = `<tr class="empty-row"><td colspan="6">Chargement...</td></tr>`;

    const data = await apiGet("etudiants-liste");
    if (!data.success) {
        tbody.innerHTML = `<tr class="empty-row"><td colspan="6">Erreur de chargement.</td></tr>`;
        return;
    }

    state.etudiantsListe = data.etudiants;
    renderEtudiants(data.etudiants);
}

function renderEtudiants(liste) {
    const tbody = document.querySelector("#tableEtudiants tbody");
    if (liste.length === 0) {
        tbody.innerHTML = `<tr class="empty-row"><td colspan="6">Aucun étudiant trouvé.</td></tr>`;
        return;
    }

    tbody.innerHTML = liste.map(u => `
        <tr>
            <td>${escapeHtml(u.nom)}</td>
            <td>${escapeHtml(u.matricule || "—")}</td>
            <td>${escapeHtml(u.email)}</td>
            <td>${u.nb_cours_inscrits}</td>
            <td><span class="badge ${u.actif ? "badge-active" : "badge-inactive"}">${u.actif ? "Actif" : "Bloqué"}</span></td>
            <td class="table-actions">
                <button class="btn-icon ${u.actif ? "danger" : ""}" onclick="toggleUtilisateur(${u.id}, ${u.actif ? 0 : 1}, 'etudiant')">
                    ${u.actif ? "Bloquer" : "Réactiver"}
                </button>
            </td>
        </tr>
    `).join("");
}

document.getElementById("searchEtudiants").addEventListener("input", (e) => {
    const q = e.target.value.toLowerCase();
    renderEtudiants(state.etudiantsListe.filter(u =>
        u.nom.toLowerCase().includes(q) ||
        u.email.toLowerCase().includes(q) ||
        (u.matricule || "").toLowerCase().includes(q)
    ));
});

function toggleUtilisateur(userId, nouveauStatut, type) {
    ouvrirConfirmation(
        nouveauStatut ? "Réactiver ce compte ?" : "Bloquer ce compte ?",
        nouveauStatut
            ? "L'utilisateur pourra de nouveau se connecter."
            : "L'utilisateur ne pourra plus se connecter à la plateforme.",
        async () => {
            const data = await apiPost("utilisateur-toggle", { user_id: userId, actif: nouveauStatut });
            if (data.success) {
                if (type === "enseignant") chargerEnseignants();
                else chargerEtudiants();
            } else {
                alert(data.message || "Erreur.");
            }
        }
    );
}
window.toggleUtilisateur = toggleUtilisateur;

/* ---------- Modal confirmation générique ---------- */

function ouvrirConfirmation(titre, message, onConfirm) {
    document.getElementById("confirmTitre").textContent = titre;
    document.getElementById("confirmMessage").textContent = message;
    state.confirmAction = onConfirm;
    document.getElementById("confirmModal").classList.add("open");
}

document.getElementById("confirmAnnulerBtn").addEventListener("click", () => {
    document.getElementById("confirmModal").classList.remove("open");
    state.confirmAction = null;
});

document.getElementById("confirmValiderBtn").addEventListener("click", async () => {
    document.getElementById("confirmModal").classList.remove("open");
    if (state.confirmAction) await state.confirmAction();
    state.confirmAction = null;
});

/* ---------- Déconnexion ---------- */

document.getElementById("logoutBtn").addEventListener("click", async (e) => {
    e.preventDefault();
    await fetch("../api/auth.php?action=logout", { credentials: "include" });
    window.location.href = "../login.html";
});

/* ---------- Burger mobile ---------- */

document.getElementById("burgerBtn").addEventListener("click", () => {
    document.getElementById("sidebar").classList.toggle("open");
});

/* ---------- Helpers ---------- */

function escapeHtml(str) {
    const div = document.createElement("div");
    div.textContent = str ?? "";
    return div.innerHTML;
}

function formatDate(dateStr) {
    const d = new Date(dateStr);
    return d.toLocaleDateString("fr-FR", { day: "2-digit", month: "short", year: "numeric" });
}

/* ---------- Init ---------- */

function initApp() {
    afficherVue("apercu");
}

document.addEventListener("DOMContentLoaded", verifierSession);