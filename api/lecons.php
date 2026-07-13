<?php
/* ===== API/LECONS.PHP ===== */
/* Convention colonnes : id_xxx
   - detail          : contenu d'une leçon (vérifie l'inscription au cours parent)
   - terminer (POST) : marque terminée, déclenche le certificat si le cours est fini
*/

session_start();
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/config.php";
require_once __DIR__ . "/certificat.php"; // fournit genererCertificatSiNecessaire()

if (!isset($_SESSION["user_id"]) || $_SESSION["role"] !== "etudiant") {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Non autorisé."]);
    exit;
}

$etudiantId = $_SESSION["user_id"];
$action = $_GET["action"] ?? "";

try {
    switch ($action) {
        case "detail":    getLeconDetail($pdo, $etudiantId); break;
        case "terminer":  terminerLecon($pdo, $etudiantId); break;
        default:
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Action inconnue."]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Erreur serveur : " . $e->getMessage()]);
}

function getLeconDetail($pdo, $etudiantId) {
    $leconId = $_GET["lecon_id"] ?? null;
    if (!$leconId) { echo json_encode(["success" => false, "message" => "Leçon invalide."]); return; }

    $stmt = $pdo->prepare("
        SELECT l.id, l.titre, l.type, l.fichier_url AS url, l.id_cours, e.id AS evaluation_id
        FROM lecons l
        LEFT JOIN evaluations e ON e.id_lecon = l.id
        WHERE l.id = ?
    ");
    $stmt->execute([$leconId]);
    $lecon = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$lecon) { echo json_encode(["success" => false, "message" => "Leçon introuvable."]); return; }

    $checkInsc = $pdo->prepare("SELECT id FROM inscriptions WHERE id_etudiant = ? AND id_cours = ?");
    $checkInsc->execute([$etudiantId, $lecon["id_cours"]]);
    if (!$checkInsc->fetch()) {
        echo json_encode(["success" => false, "message" => "Vous n'êtes pas inscrit à ce cours."]);
        return;
    }

    $checkProg = $pdo->prepare("SELECT terminee FROM progression WHERE id_etudiant = ? AND id_lecon = ?");
    $checkProg->execute([$etudiantId, $leconId]);
    $prog = $checkProg->fetch(PDO::FETCH_ASSOC);

    $lecon["terminee"] = $prog ? (bool)$prog["terminee"] : false;
    $lecon["evaluation_id"] = $lecon["evaluation_id"] ? (int)$lecon["evaluation_id"] : null;

    echo json_encode(["success" => true, "lecon" => $lecon]);
}

function terminerLecon($pdo, $etudiantId) {
    $data = json_decode(file_get_contents("php://input"), true);
    $leconId = $data["lecon_id"] ?? null;

    if (!$leconId) { echo json_encode(["success" => false, "message" => "Leçon invalide."]); return; }

    $stmtLecon = $pdo->prepare("SELECT id_cours FROM lecons WHERE id = ?");
    $stmtLecon->execute([$leconId]);
    $coursId = $stmtLecon->fetchColumn();

    if (!$coursId) { echo json_encode(["success" => false, "message" => "Leçon introuvable."]); return; }

    $checkInsc = $pdo->prepare("SELECT id FROM inscriptions WHERE id_etudiant = ? AND id_cours = ?");
    $checkInsc->execute([$etudiantId, $coursId]);
    if (!$checkInsc->fetch()) {
        echo json_encode(["success" => false, "message" => "Vous n'êtes pas inscrit à ce cours."]);
        return;
    }

    $check = $pdo->prepare("SELECT id FROM progression WHERE id_etudiant = ? AND id_lecon = ?");
    $check->execute([$etudiantId, $leconId]);

    if ($check->fetch()) {
        $upd = $pdo->prepare("UPDATE progression SET terminee = 1, date_completion = NOW() WHERE id_etudiant = ? AND id_lecon = ?");
        $upd->execute([$etudiantId, $leconId]);
    } else {
        $ins = $pdo->prepare("INSERT INTO progression (id_etudiant, id_lecon, terminee, date_completion) VALUES (?, ?, 1, NOW())");
        $ins->execute([$etudiantId, $leconId]);
    }

    $coursTermine = false;
    $progressionPct = calculerProgressionCours($pdo, $etudiantId, $coursId);
    if ($progressionPct >= 100) {
        $coursTermine = true;
        genererCertificatSiNecessaire($pdo, $etudiantId, $coursId);
    }

    echo json_encode(["success" => true, "cours_termine" => $coursTermine, "progression" => $progressionPct]);
}

function calculerProgressionCours($pdo, $etudiantId, $coursId) {
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