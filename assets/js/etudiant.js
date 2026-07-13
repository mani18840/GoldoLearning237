/* ===== ETUDIANT.JS ===== */
/* Suit la même logique que enseignant.js :
   - Vérification session au chargement
   - Navigation entre vues sans rechargement
   - Appels AJAX vers api/etudiant.php
   - Gestion modal QCM
*/

const API_URL = "../api/etudiant.php";

let state = {
    user: null,
    catalogue: [],
    mesCours: [],
    coursActif: null,   // cours ouvert dans le lecteur
    leconActive: null,  // leçon ouverte dans le lecteur
    qcmEnCours: null     // { evaluation_id, questions, reponses }
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

        if (!data.success || data.role !== "etudiant") {
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

    const view = document.getElementById(`view-${viewName}`);
    if (view) view.classList.add("active");

    const navItem = document.querySelector(`.nav-item[data-view="${viewName}"]`);
    if (navItem) navItem.classList.add("active");

    const titres = {
        catalogue: "Catalogue des cours",
        "mes-cours": "Mes cours",
        lecteur: state.coursActif ? state.coursActif.titre : "Lecteur",
        progression: "Ma progression",
        certificats: "Mes certificats"
    };
    document.getElementById("viewTitle").textContent = titres[viewName] || "";

    if (viewName === "catalogue") chargerCatalogue();
    if (viewName === "mes-cours") chargerMesCours();
    if (viewName === "progression") chargerProgression();
    if (viewName === "certificats") chargerCertificats();

    document.getElementById("sidebar").classList.remove("open");
}

document.addEventListener("click", (e) => {
    const target = e.target.closest("[data-view]");
    if (target) {
        e.preventDefault();
        afficherVue(target.dataset.view);
    }
});

/* ---------- Catalogue + inscription ---------- */

async function chargerCatalogue() {
    const grid = document.getElementById("catalogueGrid");
    grid.innerHTML = "<p class='empty-state'>Chargement...</p>";

    const data = await apiGet("catalogue");
    if (!data.success) {
        grid.innerHTML = "<p class='empty-state'>Erreur de chargement.</p>";
        return;
    }

    state.catalogue = data.cours;
    document.getElementById("statCoursDispo").textContent = data.cours.length;

    renderCatalogue(data.cours);
}

function renderCatalogue(liste) {
    const grid = document.getElementById("catalogueGrid");
    if (liste.length === 0) {
        grid.innerHTML = "<p class='empty-state'>Aucun cours disponible.</p>";
        return;
    }

    grid.innerHTML = liste.map(c => `
        <div class="course-card">
            <div class="course-card-banner">📖</div>
            <div class="course-card-body">
                <h3>${escapeHtml(c.titre)}</h3>
                <p>${escapeHtml(c.description || "").slice(0, 80)}...</p>
                <div class="course-card-footer">
                    ${c.deja_inscrit
                        ? `<span class="badge-complete">Déjà inscrit</span>`
                        : `<button class="btn btn-primary" onclick="sInscrire(${c.id})">S'inscrire</button>`
                    }
                </div>
            </div>
        </div>
    `).join("");
}

document.getElementById("searchCatalogue").addEventListener("input", (e) => {
    const q = e.target.value.toLowerCase();
    const filtre = state.catalogue.filter(c => c.titre.toLowerCase().includes(q));
    renderCatalogue(filtre);
});

async function sInscrire(coursId) {
    const data = await apiPost("inscrire", { cours_id: coursId });
    if (data.success) {
        alert("Inscription réussie !");
        chargerCatalogue();
    } else {
        alert(data.message || "Erreur lors de l'inscription.");
    }
}
window.sInscrire = sInscrire;

/* ---------- Mes cours ---------- */

async function chargerMesCours() {
    const grid = document.getElementById("mesCoursGrid");
    grid.innerHTML = "<p class='empty-state'>Chargement...</p>";

    const data = await apiGet("mes-cours");
    if (!data.success) {
        grid.innerHTML = "<p class='empty-state'>Erreur de chargement.</p>";
        return;
    }

    state.mesCours = data.cours;
    document.getElementById("statCoursInscrits").textContent = data.cours.length;
    document.getElementById("statCoursTermines").textContent =
        data.cours.filter(c => c.progression >= 100).length;

    if (data.cours.length === 0) {
        grid.innerHTML = "<p class='empty-state'>Vous n'êtes inscrit à aucun cours pour l'instant.</p>";
        return;
    }

    grid.innerHTML = data.cours.map(c => `
        <div class="course-card">
            <div class="course-card-banner">📖</div>
            <div class="course-card-body">
                <h3>${escapeHtml(c.titre)}</h3>
                <div class="course-progress-bar">
                    <div class="course-progress-fill" style="width:${c.progression}%"></div>
                </div>
                <div class="course-card-footer">
                    <span class="progress-percent">${c.progression}% terminé</span>
                    <button class="btn btn-secondary" onclick="ouvrirLecteur(${c.id})">Continuer</button>
                </div>
            </div>
        </div>
    `).join("");
}

/* ---------- Lecteur de leçon ---------- */

async function ouvrirLecteur(coursId) {
    const data = await apiGet("cours-detail", { cours_id: coursId });
    if (!data.success) {
        alert("Impossible de charger ce cours.");
        return;
    }

    state.coursActif = data.cours;
    state.leconActive = null;
    afficherVue("lecteur");
    renderListeLecons(data.cours.lecons);

    // Ouvre automatiquement la première leçon non terminée
    const premiere = data.cours.lecons.find(l => !l.terminee) || data.cours.lecons[0];
    if (premiere) ouvrirLecon(premiere.id);
}
window.ouvrirLecteur = ouvrirLecteur;

function renderListeLecons(lecons) {
    const list = document.getElementById("lecteurLeconsList");
    list.innerHTML = lecons.map(l => `
        <div class="lecon-item ${state.leconActive === l.id ? "active" : ""}" data-lecon-id="${l.id}">
            <span>${l.type === "pdf" ? "📄" : "🎬"}</span>
            <span>${escapeHtml(l.titre)}</span>
            ${l.terminee ? '<span class="check">✓</span>' : ""}
        </div>
    `).join("");

    list.querySelectorAll(".lecon-item").forEach(item => {
        item.addEventListener("click", () => ouvrirLecon(parseInt(item.dataset.leconId)));
    });
}

function ouvrirLecon(leconId) {
    const lecon = state.coursActif.lecons.find(l => l.id === leconId);
    if (!lecon) return;

    state.leconActive = leconId;
    renderListeLecons(state.coursActif.lecons);

    document.getElementById("lecteurTitreLecon").textContent = lecon.titre;

    const content = document.getElementById("lecteurContent");
    if (lecon.type === "pdf") {
        content.innerHTML = `<iframe src="${lecon.url}" title="${escapeHtml(lecon.titre)}"></iframe>`;
    } else {
        // vidéo YouTube ou lien externe
        const embed = toEmbedUrl(lecon.url);
        content.innerHTML = `<iframe src="${embed}" title="${escapeHtml(lecon.titre)}" allowfullscreen></iframe>`;
    }

    document.getElementById("marquerTermineBtn").disabled = lecon.terminee;
    document.getElementById("marquerTermineBtn").textContent =
        lecon.terminee ? "Leçon déjà terminée" : "Marquer comme terminée";

    const qcmBtn = document.getElementById("ouvrirQcmBtn");
    if (lecon.evaluation_id) {
        qcmBtn.style.display = "inline-block";
        qcmBtn.dataset.evaluationId = lecon.evaluation_id;
    } else {
        qcmBtn.style.display = "none";
    }
}

function toEmbedUrl(url) {
    const m = url.match(/(?:youtu\.be\/|v=)([\w-]{11})/);
    if (m) return `https://www.youtube.com/embed/${m[1]}`;
    return url;
}

document.getElementById("backToMesCours").addEventListener("click", () => afficherVue("mes-cours"));

document.getElementById("marquerTermineBtn").addEventListener("click", async () => {
    if (!state.leconActive) return;
    const data = await apiPost("terminer-lecon", { lecon_id: state.leconActive });
    if (data.success) {
        const lecon = state.coursActif.lecons.find(l => l.id === state.leconActive);
        lecon.terminee = true;
        renderListeLecons(state.coursActif.lecons);
        document.getElementById("marquerTermineBtn").disabled = true;
        document.getElementById("marquerTermineBtn").textContent = "Leçon déjà terminée";

        if (data.cours_termine) {
            alert("Félicitations, vous avez terminé ce cours ! Votre certificat est disponible.");
        }
    } else {
        alert(data.message || "Erreur.");
    }
});

/* ---------- QCM ---------- */

document.getElementById("ouvrirQcmBtn").addEventListener("click", async () => {
    const evaluationId = document.getElementById("ouvrirQcmBtn").dataset.evaluationId;
    const data = await apiGet("qcm-questions", { evaluation_id: evaluationId });
    if (!data.success) {
        alert("Impossible de charger le QCM.");
        return;
    }

    state.qcmEnCours = {
        evaluation_id: evaluationId,
        questions: data.questions,
        reponses: {}
    };

    document.getElementById("qcmTitre").textContent = data.titre || "Évaluation";
    renderQcm(data.questions);
    document.getElementById("qcmModal").classList.add("open");
});

function renderQcm(questions) {
    const body = document.getElementById("qcmBody");
    body.innerHTML = questions.map((q, i) => `
        <div class="qcm-question" data-question-id="${q.id}">
            <p class="enonce">${i + 1}. ${escapeHtml(q.enonce)}</p>
            ${q.options.map(opt => `
                <label class="qcm-option">
                    <input type="radio" name="q-${q.id}" value="${opt.id}">
                    ${escapeHtml(opt.texte)}
                </label>
            `).join("")}
        </div>
    `).join("");
}

document.getElementById("closeQcmModal").addEventListener("click", () => {
    document.getElementById("qcmModal").classList.remove("open");
});

document.getElementById("soumettreQcmBtn").addEventListener("click", async () => {
    const reponses = {};
    document.querySelectorAll(".qcm-question").forEach(q => {
        const qid = q.dataset.questionId;
        const checked = q.querySelector("input:checked");
        if (checked) reponses[qid] = checked.value;
    });

    if (Object.keys(reponses).length < state.qcmEnCours.questions.length) {
        if (!confirm("Certaines questions ne sont pas répondues. Soumettre quand même ?")) return;
    }

    const data = await apiPost("qcm-soumettre", {
        evaluation_id: state.qcmEnCours.evaluation_id,
        reponses
    });

    document.getElementById("qcmModal").classList.remove("open");

    if (data.success) {
        afficherResultatQcm(data.score, data.total, data.reussi);
    } else {
        alert(data.message || "Erreur lors de la soumission.");
    }
});

function afficherResultatQcm(score, total, reussi) {
    const body = document.getElementById("resultatBody");
    const pourcentage = Math.round((score / total) * 100);
    body.innerHTML = `
        <div class="result-score">${score}/${total}</div>
        <span class="result-status ${reussi ? "success" : "fail"}">
            ${reussi ? "Réussi ✓" : "Non validé"}
        </span>
        <p style="margin-top:12px;color:#777;font-size:13px;">${pourcentage}% de bonnes réponses</p>
    `;
    document.getElementById("resultatModal").classList.add("open");
}

document.getElementById("fermerResultatBtn").addEventListener("click", () => {
    document.getElementById("resultatModal").classList.remove("open");
    // Recharge le cours pour mettre à jour l'état de la leçon / progression
    if (state.coursActif) ouvrirLecteur(state.coursActif.id);
});

/* ---------- Progression ---------- */

async function chargerProgression() {
    const grid = document.getElementById("progressionGrid");
    grid.innerHTML = "<p class='empty-state'>Chargement...</p>";

    const data = await apiGet("progression");
    if (!data.success) {
        grid.innerHTML = "<p class='empty-state'>Erreur de chargement.</p>";
        return;
    }

    if (data.cours.length === 0) {
        grid.innerHTML = "<p class='empty-state'>Aucune progression pour l'instant.</p>";
        return;
    }

    grid.innerHTML = data.cours.map(c => `
        <div class="progress-card">
            <h3>${escapeHtml(c.titre)}</h3>
            <div class="course-progress-bar">
                <div class="course-progress-fill" style="width:${c.progression}%"></div>
            </div>
            <span class="progress-percent">${c.progression}% • ${c.lecons_terminees}/${c.total_lecons} leçons</span>
        </div>
    `).join("");
}

/* ---------- Certificats ---------- */

async function chargerCertificats() {
    const grid = document.getElementById("certificatsGrid");
    grid.innerHTML = "<p class='empty-state'>Chargement...</p>";

    const data = await apiGet("certificats");
    if (!data.success) {
        grid.innerHTML = "<p class='empty-state'>Erreur de chargement.</p>";
        return;
    }

    document.getElementById("statCertificats").textContent = data.certificats.length;

    if (data.certificats.length === 0) {
        grid.innerHTML = "<p class='empty-state'>Terminez un cours pour obtenir votre premier certificat.</p>";
        return;
    }

    grid.innerHTML = data.certificats.map(cert => `
        <div class="cert-card">
            <div class="cert-icon">🏆</div>
            <h3>${escapeHtml(cert.cours_titre)}</h3>
            <div class="cert-date">Obtenu le ${formatDate(cert.date_obtention)}</div>
            <a class="btn btn-primary" href="${cert.url_pdf}" target="_blank">Télécharger le PDF</a>
        </div>
    `).join("");
}

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
    return d.toLocaleDateString("fr-FR", { day: "2-digit", month: "long", year: "numeric" });
}

/* ---------- Init ---------- */

function initApp() {
    afficherVue("catalogue");
}

document.addEventListener("DOMContentLoaded", verifierSession);