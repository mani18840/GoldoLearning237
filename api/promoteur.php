<?php
/* ===== API/PROMOTEUR.PHP ===== */
/* Le promoteur consulte et modère, mais ne valide PAS les cours avant publication
   (publication directe par l'enseignant).

   Actions :
   - stats               : compteurs globaux + 5 derniers cours publiés
   - cours-liste         : tous les cours avec enseignant + nb d'inscrits
   - cours-toggle (POST) : active/désactive un cours (n'affecte pas les inscrits existants)
   - enseignants-liste   : tous les enseignants + nb de cours créés
   - etudiants-liste     : tous les étudiants + nb de cours inscrits
   - utilisateur-toggle (POST) : bloque/réactive un compte (enseignant ou étudiant)
*/

session_start();
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/config.php"; // fournit $pdo (PDO)

if (!isset($_SESSION["user_id"]) || $_SESSION["role"] !== "promoteur") {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Non autorisé."]);
    exit;
}

$action = $_GET["action"] ?? "";

try {
    switch ($action) {

        case "stats":
            getStats($pdo);
            break;

        case "cours-liste":
            getCoursListe($pdo);
            break;

        case "cours-toggle":
            toggleCours($pdo);
            break;

        case "enseignants-liste":
            getUtilisateursListe($pdo, "enseignant");
            break;

        case "etudiants-liste":
            getUtilisateursListe($pdo, "etudiant");
            break;

        case "utilisateur-toggle":
            toggleUtilisateur($pdo);
            break;

        default:
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Action inconnue."]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Erreur serveur : " . $e->getMessage()]);
}

/* ================= FONCTIONS ================= */

function getStats($pdo) {
    $totalCours = (int)$pdo->query("SELECT COUNT(*) FROM cours WHERE publie = 1")->fetchColumn();
    $totalEnseignants = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'enseignant'")->fetchColumn();
    $totalEtudiants = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'etudiant'")->fetchColumn();
    $totalCertificats = (int)$pdo->query("SELECT COUNT(*) FROM certificats")->fetchColumn();

    $stmtRecents = $pdo->prepare("
        SELECT c.id, c.titre, c.date_creation, u.nom AS enseignant_nom,
               (SELECT COUNT(*) FROM inscriptions i WHERE i.cours_id = c.id) AS nb_inscrits
        FROM cours c
        INNER JOIN users u ON u.id = c.enseignant_id
        WHERE c.publie = 1
        ORDER BY c.date_creation DESC
        LIMIT 5
    ");
    $stmtRecents->execute();
    $coursRecents = $stmtRecents->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "success" => true,
        "stats" => [
            "total_cours" => $totalCours,
            "total_enseignants" => $totalEnseignants,
            "total_etudiants" => $totalEtudiants,
            "total_certificats" => $totalCertificats,
            "cours_recents" => $coursRecents
        ]
    ]);
}

function getCoursListe($pdo) {
    $stmt = $pdo->prepare("
        SELECT c.id, c.titre, c.publie, u.nom AS enseignant_nom,
               (SELECT COUNT(*) FROM inscriptions i WHERE i.cours_id = c.id) AS nb_inscrits
        FROM cours c
        INNER JOIN users u ON u.id = c.enseignant_id
        ORDER BY c.date_creation DESC
    ");
    $stmt->execute();
    $cours = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($cours as &$c) {
        $c["publie"] = (bool)$c["publie"];
    }

    echo json_encode(["success" => true, "cours" => $cours]);
}

function toggleCours($pdo) {
    $data = json_decode(file_get_contents("php://input"), true);
    $coursId = $data["cours_id"] ?? null;
    $publie = isset($data["publie"]) ? (int)$data["publie"] : null;

    if (!$coursId || $publie === null) {
        echo json_encode(["success" => false, "message" => "Requête invalide."]);
        return;
    }

    $stmt = $pdo->prepare("UPDATE cours SET publie = ? WHERE id = ?");
    $stmt->execute([$publie, $coursId]);

    echo json_encode(["success" => true, "message" => $publie ? "Cours réactivé." : "Cours désactivé."]);
}

function getUtilisateursListe($pdo, $role) {
    if ($role === "enseignant") {
        $stmt = $pdo->prepare("
            SELECT u.id, u.nom, u.email, u.actif,
                   (SELECT COUNT(*) FROM cours c WHERE c.enseignant_id = u.id) AS nb_cours
            FROM users u
            WHERE u.role = 'enseignant'
            ORDER BY u.nom ASC
        ");
        $stmt->execute();
        $utilisateurs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($utilisateurs as &$u) {
            $u["actif"] = (bool)$u["actif"];
        }

        echo json_encode(["success" => true, "enseignants" => $utilisateurs]);
    } else {
        $stmt = $pdo->prepare("
            SELECT u.id, u.nom, u.email, u.matricule, u.actif,
                   (SELECT COUNT(*) FROM inscriptions i WHERE i.etudiant_id = u.id) AS nb_cours_inscrits
            FROM users u
            WHERE u.role = 'etudiant'
            ORDER BY u.nom ASC
        ");
        $stmt->execute();
        $utilisateurs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($utilisateurs as &$u) {
            $u["actif"] = (bool)$u["actif"];
        }

        echo json_encode(["success" => true, "etudiants" => $utilisateurs]);
    }
}

function toggleUtilisateur($pdo) {
    $data = json_decode(file_get_contents("php://input"), true);
    $userId = $data["user_id"] ?? null;
    $actif = isset($data["actif"]) ? (int)$data["actif"] : null;

    if (!$userId || $actif === null) {
        echo json_encode(["success" => false, "message" => "Requête invalide."]);
        return;
    }

    // Empêche un promoteur de se bloquer lui-même par erreur
    if ((int)$userId === (int)$_SESSION["user_id"]) {
        echo json_encode(["success" => false, "message" => "Vous ne pouvez pas modifier votre propre statut."]);
        return;
    }

    $stmt = $pdo->prepare("UPDATE users SET actif = ? WHERE id = ?");
    $stmt->execute([$actif, $userId]);

    echo json_encode(["success" => true, "message" => $actif ? "Compte réactivé." : "Compte bloqué."]);
}