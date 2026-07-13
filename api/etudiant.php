<?php
/* ===== API/ETUDIANT.PHP ===== */
/* Backend du dashboard étudiant - même logique que api/enseignant.php
   Endpoints (GET sauf mention contraire) :
   - catalogue            : liste tous les cours + statut d'inscription
   - inscrire      (POST) : inscrit l'étudiant à un cours
   - mes-cours             : cours où l'étudiant est inscrit + progression
   - cours-detail          : détail d'un cours + liste des leçons (avec statut terminé)
   - terminer-lecon (POST) : marque une leçon comme terminée, génère certificat si cours fini
   - qcm-questions         : questions d'une évaluation (sans les bonnes réponses)
   - qcm-soumettre  (POST) : corrige le QCM, enregistre le résultat
   - progression           : progression détaillée par cours
   - certificats           : liste des certificats obtenus
*/

session_start();
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/../config/database.php"; // doit fournir $pdo (PDO)

/* ---------- Vérification session étudiant ---------- */

if (!isset($_SESSION["user_id"]) || $_SESSION["role"] !== "etudiant") {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Non autorisé."]);
    exit;
}

$etudiantId = $_SESSION["user_id"];
$action = $_GET["action"] ?? "";

try {
    switch ($action) {

        case "catalogue":
            getCatalogue($pdo, $etudiantId);
            break;

        case "inscrire":
            inscrire($pdo, $etudiantId);
            break;

        case "mes-cours":
            getMesCours($pdo, $etudiantId);
            break;

        case "cours-detail":
            getCoursDetail($pdo, $etudiantId);
            break;

        case "terminer-lecon":
            terminerLecon($pdo, $etudiantId);
            break;

        case "qcm-questions":
            getQcmQuestions($pdo, $etudiantId);
            break;

        case "qcm-soumettre":
            soumettreQcm($pdo, $etudiantId);
            break;

        case "progression":
            getProgression($pdo, $etudiantId);
            break;

        case "certificats":
            getCertificats($pdo, $etudiantId);
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

function getCatalogue($pdo, $etudiantId) {
    $stmt = $pdo->prepare("
        SELECT c.id, c.titre, c.description
        FROM cours c
        WHERE c.publie = 1
        ORDER BY c.date_creation DESC
    ");
    $stmt->execute();
    $cours = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmtInsc = $pdo->prepare("SELECT cours_id FROM inscriptions WHERE etudiant_id = ?");
    $stmtInsc->execute([$etudiantId]);
    $inscrits = $stmtInsc->fetchAll(PDO::FETCH_COLUMN);

    foreach ($cours as &$c) {
        $c["deja_inscrit"] = in_array($c["id"], $inscrits);
    }

    echo json_encode(["success" => true, "cours" => $cours]);
}

function inscrire($pdo, $etudiantId) {
    $data = json_decode(file_get_contents("php://input"), true);
    $coursId = $data["cours_id"] ?? null;

    if (!$coursId) {
        echo json_encode(["success" => false, "message" => "Cours invalide."]);
        return;
    }

    $check = $pdo->prepare("SELECT id FROM inscriptions WHERE etudiant_id = ? AND cours_id = ?");
    $check->execute([$etudiantId, $coursId]);
    if ($check->fetch()) {
        echo json_encode(["success" => false, "message" => "Vous êtes déjà inscrit à ce cours."]);
        return;
    }

    $stmt = $pdo->prepare("
        INSERT INTO inscriptions (etudiant_id, cours_id, date_inscription)
        VALUES (?, ?, NOW())
    ");
    $stmt->execute([$etudiantId, $coursId]);

    echo json_encode(["success" => true, "message" => "Inscription réussie."]);
}

function getMesCours($pdo, $etudiantId) {
    $stmt = $pdo->prepare("
        SELECT c.id, c.titre
        FROM cours c
        INNER JOIN inscriptions i ON i.cours_id = c.id
        WHERE i.etudiant_id = ?
        ORDER BY i.date_inscription DESC
    ");
    $stmt->execute([$etudiantId]);
    $cours = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($cours as &$c) {
        $c["progression"] = calculerProgression($pdo, $etudiantId, $c["id"]);
    }

    echo json_encode(["success" => true, "cours" => $cours]);
}

function getCoursDetail($pdo, $etudiantId) {
    $coursId = $_GET["cours_id"] ?? null;
    if (!$coursId) {
        echo json_encode(["success" => false, "message" => "Cours invalide."]);
        return;
    }

    $stmtCours = $pdo->prepare("SELECT id, titre, description FROM cours WHERE id = ?");
    $stmtCours->execute([$coursId]);
    $cours = $stmtCours->fetch(PDO::FETCH_ASSOC);

    if (!$cours) {
        echo json_encode(["success" => false, "message" => "Cours introuvable."]);
        return;
    }

    $stmtLecons = $pdo->prepare("
        SELECT l.id, l.titre, l.type, l.url, e.id AS evaluation_id
        FROM lecons l
        LEFT JOIN evaluations e ON e.lecon_id = l.id
        WHERE l.cours_id = ?
        ORDER BY l.ordre ASC
    ");
    $stmtLecons->execute([$coursId]);
    $lecons = $stmtLecons->fetchAll(PDO::FETCH_ASSOC);

    $stmtProg = $pdo->prepare("SELECT lecon_id FROM progression WHERE etudiant_id = ? AND terminee = 1");
    $stmtProg->execute([$etudiantId]);
    $terminees = $stmtProg->fetchAll(PDO::FETCH_COLUMN);

    foreach ($lecons as &$l) {
        $l["terminee"] = in_array($l["id"], $terminees);
        $l["evaluation_id"] = $l["evaluation_id"] ? (int)$l["evaluation_id"] : null;
    }

    $cours["lecons"] = $lecons;

    echo json_encode(["success" => true, "cours" => $cours]);
}

function terminerLecon($pdo, $etudiantId) {
    $data = json_decode(file_get_contents("php://input"), true);
    $leconId = $data["lecon_id"] ?? null;

    if (!$leconId) {
        echo json_encode(["success" => false, "message" => "Leçon invalide."]);
        return;
    }

    $check = $pdo->prepare("SELECT id FROM progression WHERE etudiant_id = ? AND lecon_id = ?");
    $check->execute([$etudiantId, $leconId]);

    if ($check->fetch()) {
        $upd = $pdo->prepare("UPDATE progression SET terminee = 1, date_completion = NOW() WHERE etudiant_id = ? AND lecon_id = ?");
        $upd->execute([$etudiantId, $leconId]);
    } else {
        $ins = $pdo->prepare("
            INSERT INTO progression (etudiant_id, lecon_id, terminee, date_completion)
            VALUES (?, ?, 1, NOW())
        ");
        $ins->execute([$etudiantId, $leconId]);
    }

    // Récupère le cours associé pour vérifier si terminé à 100%
    $stmtCours = $pdo->prepare("SELECT cours_id FROM lecons WHERE id = ?");
    $stmtCours->execute([$leconId]);
    $coursId = $stmtCours->fetchColumn();

    $coursTermine = false;
    if ($coursId) {
        $progression = calculerProgression($pdo, $etudiantId, $coursId);
        if ($progression >= 100) {
            $coursTermine = true;
            genererCertificatSiNecessaire($pdo, $etudiantId, $coursId);
        }
    }

    echo json_encode(["success" => true, "cours_termine" => $coursTermine]);
}

function getQcmQuestions($pdo, $etudiantId) {
    $evaluationId = $_GET["evaluation_id"] ?? null;
    if (!$evaluationId) {
        echo json_encode(["success" => false, "message" => "Évaluation invalide."]);
        return;
    }

    $stmtEval = $pdo->prepare("SELECT titre FROM evaluations WHERE id = ?");
    $stmtEval->execute([$evaluationId]);
    $titre = $stmtEval->fetchColumn();

    $stmtQ = $pdo->prepare("SELECT id, enonce FROM questions WHERE evaluation_id = ? ORDER BY ordre ASC");
    $stmtQ->execute([$evaluationId]);
    $questions = $stmtQ->fetchAll(PDO::FETCH_ASSOC);

    $stmtOpt = $pdo->prepare("SELECT id, texte FROM options_reponse WHERE question_id = ? ORDER BY ordre ASC");
    foreach ($questions as &$q) {
        $stmtOpt->execute([$q["id"]]);
        $q["options"] = $stmtOpt->fetchAll(PDO::FETCH_ASSOC);
    }

    echo json_encode(["success" => true, "titre" => $titre, "questions" => $questions]);
}

function soumettreQcm($pdo, $etudiantId) {
    $data = json_decode(file_get_contents("php://input"), true);
    $evaluationId = $data["evaluation_id"] ?? null;
    $reponses = $data["reponses"] ?? [];

    if (!$evaluationId) {
        echo json_encode(["success" => false, "message" => "Évaluation invalide."]);
        return;
    }

    // Récupère les bonnes réponses
    $stmtQ = $pdo->prepare("SELECT id FROM questions WHERE evaluation_id = ?");
    $stmtQ->execute([$evaluationId]);
    $questionIds = $stmtQ->fetchAll(PDO::FETCH_COLUMN);
    $total = count($questionIds);

    $stmtCorrecte = $pdo->prepare("SELECT id FROM options_reponse WHERE question_id = ? AND est_correcte = 1");

    $score = 0;
    foreach ($questionIds as $qid) {
        $stmtCorrecte->execute([$qid]);
        $bonneReponse = $stmtCorrecte->fetchColumn();
        $reponseEtudiant = $reponses[$qid] ?? null;

        if ($reponseEtudiant && (int)$reponseEtudiant === (int)$bonneReponse) {
            $score++;
        }
    }

    $seuilReussite = 0.6; // 60% pour valider
    $reussi = $total > 0 && ($score / $total) >= $seuilReussite;

    $stmtSave = $pdo->prepare("
        INSERT INTO resultats (etudiant_id, evaluation_id, score, total, reussi, date_passage)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    $stmtSave->execute([$etudiantId, $evaluationId, $score, $total, $reussi ? 1 : 0]);

    echo json_encode([
        "success" => true,
        "score" => $score,
        "total" => $total,
        "reussi" => $reussi
    ]);
}

function getProgression($pdo, $etudiantId) {
    $stmt = $pdo->prepare("
        SELECT c.id, c.titre
        FROM cours c
        INNER JOIN inscriptions i ON i.cours_id = c.id
        WHERE i.etudiant_id = ?
    ");
    $stmt->execute([$etudiantId]);
    $cours = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($cours as &$c) {
        $stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM lecons WHERE cours_id = ?");
        $stmtTotal->execute([$c["id"]]);
        $total = (int)$stmtTotal->fetchColumn();

        $stmtDone = $pdo->prepare("
            SELECT COUNT(*) FROM progression p
            INNER JOIN lecons l ON l.id = p.lecon_id
            WHERE l.cours_id = ? AND p.etudiant_id = ? AND p.terminee = 1
        ");
        $stmtDone->execute([$c["id"], $etudiantId]);
        $done = (int)$stmtDone->fetchColumn();

        $c["total_lecons"] = $total;
        $c["lecons_terminees"] = $done;
        $c["progression"] = $total > 0 ? round(($done / $total) * 100) : 0;
    }

    echo json_encode(["success" => true, "cours" => $cours]);
}

function getCertificats($pdo, $etudiantId) {
    $stmt = $pdo->prepare("
        SELECT cert.id, c.titre AS cours_titre, cert.date_obtention, cert.url_pdf
        FROM certificats cert
        INNER JOIN cours c ON c.id = cert.cours_id
        WHERE cert.etudiant_id = ?
        ORDER BY cert.date_obtention DESC
    ");
    $stmt->execute([$etudiantId]);
    $certificats = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(["success" => true, "certificats" => $certificats]);
}

/* ---------- Helpers internes ---------- */

function calculerProgression($pdo, $etudiantId, $coursId) {
    $stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM lecons WHERE cours_id = ?");
    $stmtTotal->execute([$coursId]);
    $total = (int)$stmtTotal->fetchColumn();

    if ($total === 0) return 0;

    $stmtDone = $pdo->prepare("
        SELECT COUNT(*) FROM progression p
        INNER JOIN lecons l ON l.id = p.lecon_id
        WHERE l.cours_id = ? AND p.etudiant_id = ? AND p.terminee = 1
    ");
    $stmtDone->execute([$coursId, $etudiantId]);
    $done = (int)$stmtDone->fetchColumn();

    return round(($done / $total) * 100);
}

function genererCertificatSiNecessaire($pdo, $etudiantId, $coursId) {
    $check = $pdo->prepare("SELECT id FROM certificats WHERE etudiant_id = ? AND cours_id = ?");
    $check->execute([$etudiantId, $coursId]);
    if ($check->fetch()) return; // déjà généré

    // Le PDF réel doit être généré par un script séparé (ex: certificat_generator.php avec FPDF/TCPDF).
    // Ici on enregistre l'entrée en base ; le chemin pointe vers le fichier généré.
    $urlPdf = "certificats/cert_" . $etudiantId . "_" . $coursId . "_" . time() . ".pdf";

    $stmt = $pdo->prepare("
        INSERT INTO certificats (etudiant_id, cours_id, date_obtention, url_pdf)
        VALUES (?, ?, NOW(), ?)
    ");
    $stmt->execute([$etudiantId, $coursId, $urlPdf]);

    // TODO : appeler ici la génération réelle du PDF (ex: generateCertificatPdf($etudiantId, $coursId, $urlPdf))
}