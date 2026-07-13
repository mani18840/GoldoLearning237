<?php
/* ===== API/COURS.PHP ===== */
/* Convention colonnes : id_xxx (id_cours, id_enseignant, id_etudiant, id_module...)
   - catalogue      : cours publiés + statut d'inscription
   - detail         : cours + leçons (vérifie l'inscription)
   - inscrire (POST): inscrit l'étudiant connecté
   - mes-cours      : cours inscrits + % progression
*/

session_start();
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/config.php";

if (!isset($_SESSION["user_id"]) || $_SESSION["role"] !== "etudiant") {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Non autorisé."]);
    exit;
}

$etudiantId = $_SESSION["user_id"];
$action = $_GET["action"] ?? "";

try {
    switch ($action) {
        case "catalogue": getCatalogue($pdo, $etudiantId); break;
        case "detail":    getCoursDetail($pdo, $etudiantId); break;
        case "inscrire":  inscrire($pdo, $etudiantId); break;
        case "mes-cours": getMesCours($pdo, $etudiantId); break;
        default:
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Action inconnue."]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Erreur serveur : " . $e->getMessage()]);
}

function getCatalogue($pdo, $etudiantId) {
    $stmt = $pdo->prepare("SELECT id, titre, description FROM cours WHERE publie = 1 ORDER BY date_creation DESC");
    $stmt->execute();
    $cours = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmtInsc = $pdo->prepare("SELECT id_cours FROM inscriptions WHERE id_etudiant = ?");
    $stmtInsc->execute([$etudiantId]);
    $inscrits = $stmtInsc->fetchAll(PDO::FETCH_COLUMN);

    foreach ($cours as &$c) {
        $c["deja_inscrit"] = in_array($c["id"], $inscrits);
    }

    echo json_encode(["success" => true, "cours" => $cours]);
}

function getCoursDetail($pdo, $etudiantId) {
    $coursId = $_GET["cours_id"] ?? null;
    if (!$coursId) { echo json_encode(["success" => false, "message" => "Cours invalide."]); return; }

    $stmtCours = $pdo->prepare("SELECT id, titre, description FROM cours WHERE id = ?");
    $stmtCours->execute([$coursId]);
    $cours = $stmtCours->fetch(PDO::FETCH_ASSOC);

    if (!$cours) { echo json_encode(["success" => false, "message" => "Cours introuvable."]); return; }

    $checkInsc = $pdo->prepare("SELECT id FROM inscriptions WHERE id_etudiant = ? AND id_cours = ?");
    $checkInsc->execute([$etudiantId, $coursId]);
    if (!$checkInsc->fetch()) {
        echo json_encode(["success" => false, "message" => "Vous n'êtes pas inscrit à ce cours."]);
        return;
    }

    $stmtLecons = $pdo->prepare("
        SELECT l.id, l.titre, l.type, l.fichier_url AS url, e.id AS evaluation_id
        FROM lecons l
        LEFT JOIN evaluations e ON e.id_lecon = l.id
        WHERE l.id_cours = ?
        ORDER BY l.ordre ASC
    ");
    $stmtLecons->execute([$coursId]);
    $lecons = $stmtLecons->fetchAll(PDO::FETCH_ASSOC);

    $stmtProg = $pdo->prepare("
        SELECT p.id_lecon FROM progression p
        INNER JOIN lecons l ON l.id = p.id_lecon
        WHERE p.id_etudiant = ? AND l.id_cours = ? AND p.terminee = 1
    ");
    $stmtProg->execute([$etudiantId, $coursId]);
    $terminees = $stmtProg->fetchAll(PDO::FETCH_COLUMN);

    foreach ($lecons as &$l) {
        $l["terminee"] = in_array($l["id"], $terminees);
        $l["evaluation_id"] = $l["evaluation_id"] ? (int)$l["evaluation_id"] : null;
    }

    $cours["lecons"] = $lecons;
    echo json_encode(["success" => true, "cours" => $cours]);
}

function inscrire($pdo, $etudiantId) {
    $data = json_decode(file_get_contents("php://input"), true);
    $coursId = $data["cours_id"] ?? null;

    if (!$coursId) { echo json_encode(["success" => false, "message" => "Cours invalide."]); return; }

    $checkCours = $pdo->prepare("SELECT id FROM cours WHERE id = ? AND publie = 1");
    $checkCours->execute([$coursId]);
    if (!$checkCours->fetch()) {
        echo json_encode(["success" => false, "message" => "Ce cours n'existe pas ou n'est pas publié."]);
        return;
    }

    $check = $pdo->prepare("SELECT id FROM inscriptions WHERE id_etudiant = ? AND id_cours = ?");
    $check->execute([$etudiantId, $coursId]);
    if ($check->fetch()) {
        echo json_encode(["success" => false, "message" => "Vous êtes déjà inscrit à ce cours."]);
        return;
    }

    $stmt = $pdo->prepare("INSERT INTO inscriptions (id_etudiant, id_cours, date_inscription) VALUES (?, ?, NOW())");
    $stmt->execute([$etudiantId, $coursId]);

    echo json_encode(["success" => true, "message" => "Inscription réussie."]);
}

function getMesCours($pdo, $etudiantId) {
    $stmt = $pdo->prepare("
        SELECT c.id, c.titre
        FROM cours c
        INNER JOIN inscriptions i ON i.id_cours = c.id
        WHERE i.id_etudiant = ?
        ORDER BY i.date_inscription DESC
    ");
    $stmt->execute([$etudiantId]);
    $cours = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($cours as &$c) {
        $c["progression"] = calculerProgression($pdo, $etudiantId, $c["id"]);
    }

    echo json_encode(["success" => true, "cours" => $cours]);
}

function calculerProgression($pdo, $etudiantId, $coursId) {
    $stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM lecons WHERE id_cours = ?");
    $stmtTotal->execute([$coursId]);
    $total = (int)$stmtTotal->fetchColumn();
    if ($total === 0) return 0;

    $stmtDone = $pdo->prepare("
        SELECT COUNT(*) FROM progression p
        INNER JOIN lecons l ON l.id = p.id_lecon
        WHERE l.id_cours = ? AND p.id_etudiant = ? AND p.terminee = 1
    ");
    $stmtDone->execute([$coursId, $etudiantId]);
    $done = (int)$stmtDone->fetchColumn();

    return round(($done / $total) * 100);
}