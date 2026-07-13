/* ===== ÉVALUATIONS (QCM) - à coller à la fin de enseignant.js, avant le dernier }); ===== */
/* Utilise la même fonction apiFetch déjà définie plus haut dans le fichier.
   Repose sur api/evaluations.php (actions : creer, detail, modifier, supprimer)
   et api/enseignant.php?action=mes_cours + action=lecons pour la liste. */

let questionIndex = 0; // compteur global pour générer des noms de group radio uniques

/* ---------- Chargement de la liste (cours > leçons > statut QCM) ---------- */

async function chargerEvaluations() {
    const container = document.getElementById('evaluationsListContainer');
    container.innerHTML = `<div class="empty-state"><span>⏳</span><p>Chargement...</p></div>`;

    const resCours = await apiFetch('../api/enseignant.php?action=mes_cours').then(r => r.json());
    if (!resCours.success || resCours.cours.length === 0) {
        container.innerHTML = `<div class="empty-state"><span>📝</span>
            <p>Créez d'abord un cours avec des leçons pour ajouter des évaluations.</p></div>`;
        return;
    }

    let html = '';
    let auMoinsUneLecon = false;

    for (const cours of resCours.cours) {
        const resLecons = await apiFetch('../api/enseignant.php?action=lecons&cours_id=' + cours.id).then(r => r.json());
        if (!resLecons.success || resLecons.lecons.length === 0) continue;

        auMoinsUneLecon = true;
        html += `<div class="eval-cours-groupe">
            <div class="eval-cours-titre">${echapper(cours.titre)}</div>`;

        resLecons.lecons.forEach(l => {
            const aEval = l.a_evaluation > 0;
            html += `
                <div class="eval-lecon-row">
                    <div class="eval-lecon-info">
                        <span>${l.type === 'pdf' ? '📄' : '🎬'}</span>
                        <span>${echapper(l.titre)}</span>
                        <span class="badge ${aEval ? 'badge-qcm-oui' : 'badge-qcm-non'}">
                            ${aEval ? 'QCM créé' : 'Pas de QCM'}
                        </span>
                    </div>
                    <div class="eval-lecon-actions">
                        ${aEval
                            ? `<button class="btn-secondary-sm" onclick="ouvrirEditionQcm(${l.id})">Modifier</button>
                               <button class="btn-danger-sm" onclick="supprimerQcmLecon(${l.id})">Supprimer</button>`
                            : `<button class="btn-secondary-sm" onclick="ouvrirCreationQcm(${l.id})">+ Créer un QCM</button>`
                        }
                    </div>
                </div>`;
        });

        html += `</div>`;
    }

    if (!auMoinsUneLecon) {
        container.innerHTML = `<div class="empty-state"><span>📝</span>
            <p>Ajoutez d'abord des leçons à vos cours pour créer des évaluations.</p></div>`;
        return;
    }

    container.innerHTML = html;
}

// À appeler quand on navigue vers la vue évaluations (adapte à ton switchView existant)
const eval_originalSwitchView = switchView;
switchView = function (viewName) {
    eval_originalSwitchView(viewName);
    if (viewName === 'evaluations') chargerEvaluations();
};

/* ---------- On garde en mémoire la correspondance lecon_id -> evaluation_id le temps de la liste chargée ---------- */
let leconVersEvaluationId = {};

/* On enrichit chargerEvaluations avec le mapping (ré-écriture propre) */
const _chargerEvaluationsOriginal = chargerEvaluations;
chargerEvaluations = async function () {
    await _chargerEvaluationsOriginal();
    // rien à faire ici, le mapping est reconstruit à l'ouverture d'édition via l'API detail par lecon
};

/* ---------- Ouverture modal : création ---------- */

function ouvrirCreationQcm(leconId) {
    document.getElementById('qcmModalTitre').textContent = 'Créer un QCM';
    document.getElementById('qcmLeconId').value = leconId;
    document.getElementById('qcmEvaluationId').value = '';
    document.getElementById('qcmTitreInput').value = '';
    document.getElementById('qcmMessage').textContent = '';
    document.getElementById('qcmMessage').className = 'auth-message';
    document.getElementById('qcmQuestionsContainer').innerHTML = '';
    questionIndex = 0;
    ajouterQuestionBloc(); // au moins une question au départ
    document.getElementById('modalQcm').classList.remove('hidden');
}
window.ouvrirCreationQcm = ouvrirCreationQcm;

/* ---------- Ouverture modal : édition ---------- */

async function ouvrirEditionQcm(leconId) {
    // On doit d'abord retrouver l'evaluation_id : on relit les leçons du cours concerné
    // Solution simple : on cherche via une requête detail par lecon en passant par evaluations.php?action=detail
    // mais cette action attend un evaluation_id, donc on ajoute un détour : on relit la liste "lecons" déjà en cache
    // Ici, plus simple : on demande à evaluations.php de résoudre à partir de la leçon si l'API le permet.
    // Comme notre backend actuel attend evaluation_id, on récupère d'abord l'info via mes_cours + lecons (a_evaluation ne donne pas l'ID).
    // => Solution : on ajoute un fetch qui recharge la leçon via lecons.php?action=detail (déjà existant côté étudiant, mais ici on est enseignant).
    // Le plus fiable : appeler evaluations.php avec lecon_id, si ton backend le permet, sinon on adapte getQcmDetailEnseignant pour accepter lecon_id en alternative.

    const res = await apiFetch('../api/evaluations.php?action=detail&lecon_id=' + leconId).then(r => r.json());

    if (!res.success) {
        alert(res.message || "Impossible de charger ce QCM.");
        return;
    }

    const evaluation = res.evaluation;

    document.getElementById('qcmModalTitre').textContent = 'Modifier le QCM';
    document.getElementById('qcmLeconId').value = leconId;
    document.getElementById('qcmEvaluationId').value = evaluation.id;
    document.getElementById('qcmTitreInput').value = evaluation.titre;
    document.getElementById('qcmMessage').textContent = '';
    document.getElementById('qcmMessage').className = 'auth-message';
    document.getElementById('qcmQuestionsContainer').innerHTML = '';
    questionIndex = 0;

    evaluation.questions.forEach(q => {
        ajouterQuestionBloc(q.enonce, q.options);
    });

    document.getElementById('modalQcm').classList.remove('hidden');
}
window.ouvrirEditionQcm = ouvrirEditionQcm;

/* ---------- Fermeture modal ---------- */

function fermerModalQcm() {
    document.getElementById('modalQcm').classList.add('hidden');
}
document.getElementById('closeModalQcm').addEventListener('click', fermerModalQcm);
document.getElementById('cancelQcm').addEventListener('click', fermerModalQcm);
document.getElementById('modalQcm').addEventListener('click', function (e) {
    if (e.target === document.getElementById('modalQcm')) fermerModalQcm();
});

/* ---------- Génération dynamique d'une question ---------- */

function ajouterQuestionBloc(enonceExistant = '', optionsExistantes = null) {
    const template = document.getElementById('templateQuestion');
    const clone = template.content.cloneNode(true);
    const bloc = clone.querySelector('.qcm-question-block');

    const currentIndex = questionIndex++;
    bloc.dataset.questionIndex = currentIndex;
    bloc.querySelector('.qcm-question-num').textContent = 'Question ' + (currentIndex + 1);
    bloc.querySelector('.qcm-enonce-input').value = enonceExistant;

    bloc.querySelector('.btn-suppr-question').addEventListener('click', function () {
        bloc.remove();
        renumeroterQuestions();
    });

    const optionsList = bloc.querySelector('.qcm-options-list');

    if (optionsExistantes && optionsExistantes.length > 0) {
        optionsExistantes.forEach(o => ajouterOptionBloc(optionsList, currentIndex, o.texte, o.est_correcte));
    } else {
        // 2 options vides par défaut
        ajouterOptionBloc(optionsList, currentIndex, '', false);
        ajouterOptionBloc(optionsList, currentIndex, '', false);
    }

    bloc.querySelector('.btn-ajouter-option').addEventListener('click', function () {
        ajouterOptionBloc(optionsList, currentIndex, '', false);
    });

    document.getElementById('qcmQuestionsContainer').appendChild(bloc);
}
document.getElementById('ajouterQuestionBtn').addEventListener('click', () => ajouterQuestionBloc());

function renumeroterQuestions() {
    document.querySelectorAll('.qcm-question-block').forEach((bloc, i) => {
        bloc.querySelector('.qcm-question-num').textContent = 'Question ' + (i + 1);
    });
}

/* ---------- Génération dynamique d'une option ---------- */

function ajouterOptionBloc(optionsListEl, questionIdx, texteExistant = '', estCorrecte = false) {
    const template = document.getElementById('templateOption');
    const clone = template.content.cloneNode(true);
    const row = clone.querySelector('.qcm-option-row');

    const radio = row.querySelector('.qcm-option-correcte');
    radio.name = 'correcte-q' + questionIdx;
    radio.checked = estCorrecte;

    row.querySelector('.qcm-option-texte').value = texteExistant;

    row.querySelector('.btn-suppr-option').addEventListener('click', function () {
        // Empêche de descendre sous 2 options
        if (optionsListEl.children.length <= 2) {
            alert('Une question doit avoir au moins 2 options.');
            return;
        }
        row.remove();
    });

    optionsListEl.appendChild(row);
}

/* ---------- Lecture de la structure du formulaire pour l'envoi ---------- */

function lireStructureQcm() {
    const questions = [];

    document.querySelectorAll('.qcm-question-block').forEach(bloc => {
        const enonce = bloc.querySelector('.qcm-enonce-input').value.trim();
        const options = [];

        bloc.querySelectorAll('.qcm-option-row').forEach(row => {
            const texte = row.querySelector('.qcm-option-texte').value.trim();
            const correcte = row.querySelector('.qcm-option-correcte').checked;
            options.push({ texte, correcte });
        });

        questions.push({ enonce, options });
    });

    return questions;
}

/* ---------- Sauvegarde (création ou modification) ---------- */

document.getElementById('saveQcmBtn').addEventListener('click', async function () {
    const leconId = document.getElementById('qcmLeconId').value;
    const evaluationId = document.getElementById('qcmEvaluationId').value;
    const titre = document.getElementById('qcmTitreInput').value.trim();
    const msgEl = document.getElementById('qcmMessage');
    const btn = document.getElementById('saveQcmBtn');
    const btnText = document.getElementById('qcmSaveBtnText');
    const loader = document.getElementById('qcmSaveBtnLoader');

    msgEl.textContent = '';
    msgEl.className = 'auth-message';
    document.getElementById('qcmTitreError').textContent = '';

    if (!titre) {
        document.getElementById('qcmTitreError').textContent = 'Le titre est requis.';
        return;
    }

    const questions = lireStructureQcm();

    // Validation côté client (le serveur revalide de toute façon)
    for (let i = 0; i < questions.length; i++) {
        const q = questions[i];
        if (!q.enonce) {
            msgEl.textContent = `La question ${i + 1} n'a pas d'énoncé.`;
            msgEl.className = 'auth-message error';
            return;
        }
        const nbCorrectes = q.options.filter(o => o.correcte).length;
        if (nbCorrectes !== 1) {
            msgEl.textContent = `La question ${i + 1} doit avoir exactement une bonne réponse cochée.`;
            msgEl.className = 'auth-message error';
            return;
        }
        if (q.options.some(o => !o.texte)) {
            msgEl.textContent = `Une option est vide dans la question ${i + 1}.`;
            msgEl.className = 'auth-message error';
            return;
        }
    }

    if (questions.length === 0) {
        msgEl.textContent = 'Ajoutez au moins une question.';
        msgEl.className = 'auth-message error';
        return;
    }

    btn.disabled = true;
    btnText.textContent = 'Enregistrement...';
    loader.classList.remove('hidden');

    const payload = evaluationId
        ? { evaluation_id: evaluationId, titre, questions }
        : { lecon_id: leconId, titre, questions };

    const action = evaluationId ? 'modifier' : 'creer';

    try {
        const res = await apiFetch('../api/evaluations.php?action=' + action, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        }).then(r => r.json());

        btn.disabled = false;
        btnText.textContent = 'Enregistrer';
        loader.classList.add('hidden');

        if (res.success) {
            msgEl.textContent = '✅ QCM enregistré !';
            msgEl.className = 'auth-message success';
            setTimeout(() => {
                fermerModalQcm();
                chargerEvaluations();
            }, 800);
        } else {
            msgEl.textContent = res.message || 'Erreur lors de l\'enregistrement.';
            msgEl.className = 'auth-message error';
        }
    } catch (err) {
        btn.disabled = false;
        btnText.textContent = 'Enregistrer';
        loader.classList.add('hidden');
        msgEl.textContent = 'Erreur réseau.';
        msgEl.className = 'auth-message error';
    }
});

/* ---------- Suppression d'un QCM ---------- */

async function supprimerQcmLecon(leconId) {
    if (!confirm('Supprimer ce QCM ? Les résultats déjà passés par les étudiants seront aussi supprimés.')) return;

    // Même limitation que ouvrirEditionQcm : on résout l'evaluation_id via lecon_id côté backend
    const resDetail = await apiFetch('../api/evaluations.php?action=detail&lecon_id=' + leconId).then(r => r.json());
    if (!resDetail.success) {
        alert(resDetail.message || 'QCM introuvable.');
        return;
    }

    const res = await apiFetch('../api/evaluations.php?action=supprimer', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ evaluation_id: resDetail.evaluation.id })
    }).then(r => r.json());

    if (res.success) {
        chargerEvaluations();
    } else {
        alert(res.message || 'Erreur lors de la suppression.');
    }
}
window.supprimerQcmLecon = supprimerQcmLecon;