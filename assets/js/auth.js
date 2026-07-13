/* ============================================
   GOLDOLEARNING237 — auth.js
   Gère : login + register (validation + AJAX)
   ============================================ */

document.addEventListener('DOMContentLoaded', function () {

    /* ==========================================
       UTILITAIRES PARTAGÉS
       ========================================== */

    function showMessage(msg, type) {
        const el = document.getElementById('authMessage');
        if (!el) return;
        el.textContent = msg;
        el.className = 'auth-message ' + type;
    }

    function clearMessage() {
        const el = document.getElementById('authMessage');
        if (el) el.className = 'auth-message';
    }

    function setFieldError(fieldId, errorId, msg) {
        const field = document.getElementById(fieldId);
        const error = document.getElementById(errorId);
        if (field) field.classList.add('invalid');
        // Protection si la balise d'erreur n'existe pas dans le HTML
        if (error) {
            error.textContent = msg;
        } else if (!errorId) {
            console.warn(`Attention : La balise #${errorId} n'existe pas dans votre HTML.`);
        }
    }

    function clearFieldError(fieldId, errorId) {
        const field = document.getElementById(fieldId);
        const error = document.getElementById(errorId);
        if (field) { field.classList.remove('invalid'); field.classList.add('valid'); }
        if (error) error.textContent = '';
    }

    function setLoading(isLoading) {
        const btn    = document.getElementById('submitBtn');
        const text   = document.getElementById('btnText');
        const loader = document.getElementById('btnLoader');
        if (!btn) return;
        btn.disabled = isLoading;
        if (text)   text.textContent = isLoading ? 'Chargement...' : btn.dataset.label || text.textContent;
        if (loader) loader.classList.toggle('hidden', !isLoading);
    }

    /* Afficher / masquer mot de passe */
    const toggleBtns = document.querySelectorAll('.toggle-pwd');
    toggleBtns.forEach(function (btn) {
        btn.addEventListener('click', function () {
            const input = btn.previousElementSibling;
            if (input) {
                input.type = (input.type === 'password') ? 'text' : 'password';
                btn.textContent = (input.type === 'password') ? '👁️' : '🙈';
            }
        });
    });

    /* ==========================================
       PAGE LOGIN
       ========================================== */
    const loginForm = document.getElementById('loginForm');
    if (loginForm) {

        const emailInput = document.getElementById('email');
        const pwdInput   = document.getElementById('password');

        /* Validation en temps réel */
        if (emailInput) {
            emailInput.addEventListener('blur', function () {
                if (!emailInput.value.trim()) {
                    setFieldError('email', 'emailError', 'L\'email est requis.');
                } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailInput.value)) {
                    setFieldError('email', 'emailError', 'Format d\'email invalide.');
                } else {
                    clearFieldError('email', 'emailError');
                }
            });
        }

        if (pwdInput) {
            pwdInput.addEventListener('blur', function () {
                if (!pwdInput.value) {
                    setFieldError('password', 'passwordError', 'Le mot de passe est requis.');
                } else {
                    clearFieldError('password', 'passwordError');
                }
            });
        }

        /* Soumission Login */
        loginForm.addEventListener('submit', function (e) {
            e.preventDefault();
            clearMessage();

            const email    = emailInput ? emailInput.value.trim() : '';
            const password = pwdInput ? pwdInput.value : '';
            let valid = true;

            if (!email) {
                setFieldError('email', 'emailError', 'L\'email est requis.');
                valid = false;
            }
            if (!password) {
                setFieldError('password', 'passwordError', 'Le mot de passe est requis.');
                valid = false;
            }
            if (!valid) return;

            setLoading(true);

            fetch('../api/auth.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'same-origin',
    body: JSON.stringify({ action: 'login', email: email, password: password })
})
            .then(function (res) { return res.json(); })
            .then(function (data) {
                setLoading(false);
                if (data.success) {
                    showMessage('Connexion réussie ! Redirection...', 'success');
                    setTimeout(function () {
                        const role = data.user.role;
                        if (role === 'enseignant') {
                            window.location.href = '../dashboard/enseignant.html';
                        } else if (role === 'promoteur') {
                            window.location.href = '../dashboard/promoteur.html';
                        } else {
                            window.location.href = '../dashboard/etudiant.html';
                        }
                    }, 1000);
                } else {
                    showMessage(data.message || 'Identifiants incorrects.', 'error');
                }
            })
            .catch(function (err) {
                console.error(err);
                setLoading(false);
                showMessage('Erreur de connexion. Vérifiez que XAMPP est actif.', 'error');
            });
        });
    }

    /* ==========================================
       PAGE REGISTER
       ========================================== */
    const registerForm = document.getElementById('registerForm');
    if (registerForm) {

        const nomInput       = document.getElementById('nom');
        const emailInput     = document.getElementById('email');
        const roleInput      = document.getElementById('role');
        const matriculeInput = document.getElementById('matricule');
        const pwdInput       = document.getElementById('password');
        const pwd2Input      = document.getElementById('password2');

        /* Indicateur de force du mot de passe */
        const strengthFill  = document.getElementById('strengthFill');
        const strengthLabel = document.getElementById('strengthLabel');

        if (pwdInput && strengthFill) {
            pwdInput.addEventListener('input', function () {
                const v = pwdInput.value;
                let score = 0;
                if (v.length >= 6)           score++;
                if (v.length >= 10)          score++;
                if (/[A-Z]/.test(v))         score++;
                if (/[0-9]/.test(v))         score++;
                if (/[^A-Za-z0-9]/.test(v))  score++;

                strengthFill.className = 'pwd-strength-fill';
                if (score <= 1) {
                    strengthFill.classList.add('weak');
                    if (strengthLabel) strengthLabel.textContent = 'Faible';
                } else if (score <= 3) {
                    strengthFill.classList.add('medium');
                    if (strengthLabel) strengthLabel.textContent = 'Moyen';
                } else {
                    strengthFill.classList.add('strong');
                    if (strengthLabel) strengthLabel.textContent = 'Fort';
                }
            });
        }

        /* Validation en temps réel */
        if (nomInput) {
            nomInput.addEventListener('blur', function () {
                if (!nomInput.value.trim()) {
                    setFieldError('nom', 'nomError', 'Le nom est requis.');
                } else {
                    clearFieldError('nom', 'nomError');
                }
            });
        }

        if (emailInput) {
            emailInput.addEventListener('blur', function () {
                if (!emailInput.value.trim()) {
                    setFieldError('email', 'emailError', 'L\'email est requis.');
                } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailInput.value)) {
                    setFieldError('email', 'emailError', 'Format d\'email invalide.');
                } else {
                    clearFieldError('email', 'emailError');
                }
            });
        }

        if (pwdInput) {
            pwdInput.addEventListener('blur', function () {
                if (pwdInput.value.length < 6) {
                    setFieldError('password', 'passwordError', 'Minimum 6 caractères.');
                } else {
                    clearFieldError('password', 'passwordError');
                }
            });
        }

        if (pwd2Input) {
            pwd2Input.addEventListener('blur', function () {
                if (pwd2Input.value !== pwdInput.value) {
                    setFieldError('password2', 'password2Error', 'Les mots de passe ne correspondent pas.');
                } else {
                    clearFieldError('password2', 'password2Error');
                }
            });
        }

        /* Soumission Register */
        registerForm.addEventListener('submit', function (e) {
            e.preventDefault();
            clearMessage();

            const nom       = nomInput ? nomInput.value.trim() : '';
            const email     = emailInput ? emailInput.value.trim() : '';
            const role      = roleInput ? roleInput.value : '';
            const matricule = matriculeInput ? matriculeInput.value.trim() : '';
            const password  = pwdInput ? pwdInput.value : '';
            const password2 = pwd2Input ? pwd2Input.value : '';
            const terms     = document.getElementById('terms');
            let valid = true;

            if (!nom)   { setFieldError('nom', 'nomError', 'Le nom est requis.'); valid = false; }
            if (!email) { setFieldError('email', 'emailError', 'L\'email est requis.'); valid = false; }
            if (!role)  { setFieldError('role', 'roleError', 'Choisissez un rôle.'); valid = false; }
            if (password.length < 6) { setFieldError('password', 'passwordError', 'Minimum 6 caractères.'); valid = false; }
            if (password !== password2) { setFieldError('password2', 'password2Error', 'Les mots de passe ne correspondent pas.'); valid = false; }
            if (terms && !terms.checked) { showMessage('Vous devez accepter les conditions d\'utilisation.', 'error'); valid = false; }

            if (!valid) return;

            setLoading(true);

            fetch('../api/auth.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ 
                    action: 'register', 
                    nom: nom, 
                    email: email, 
                    password: password, 
                    role: role,
                    matricule: matricule 
                })
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                setLoading(false);
                if (data.success) {
                    showMessage('Compte créé avec succès ! Redirection vers la connexion...', 'success');
                    setTimeout(function () {
                        window.location.href = 'login.html';
                    }, 1500);
                } else {
                    showMessage(data.message || 'Erreur lors de l\'inscription.', 'error');
                }
            })
            .catch(function (err) {
                console.error(err);
                setLoading(false);
                showMessage('Erreur lors du traitement. Vérifiez la console (F12).', 'error');
            });
        });
    }

});