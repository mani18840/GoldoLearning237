<?php
/* ===== API/PROGRESSION.PHP ===== */
/* Convention colonnes : id_xxx
   - liste : progression détaillée pour chaque cours inscrit
   - cours : progression d'un seul cours
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
        case "liste": getProgressionListe($pdo, $etudiantId); break;
        case "cours": getProgressionCours($pdo, $etudiantId); break;
        default:
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Action inconnue."]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Erreur serveur : " . $e->getMessage()]);
}

function getProgressionListe($pdo, $etudiantId) {
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
        $detail = calculerDetailProgression($pdo, $etudiantId, $c["id"]);
        $c["total_lecons"] = $detail["total"];
        $c["lecons_terminees"] = $detail["done"];
        $c["progression"] = $detail["pourcentage"];
    }

    echo json_encode(["success" => true, "cours" => $cours]);
}

function getProgressionCours($pdo, $etudiantId) {
    $coursId = $_GET["cours_id"] ?? null;
    if (!$coursId) { echo json_encode(["success" => false, "message" => "Cours invalide."]); return; }

    $checkInsc = $pdo->prepare("SELECT id FROM inscriptions WHERE id_etudiant = ? AND id_cours = ?");
    $checkInsc->execute([$etudiantId, $coursId]);
    if (!$checkInsc->fetch()) {
        echo json_encode(["success" => false, "message" => "Vous n'êtes pas inscrit à ce cours."]);
        return;
    }

    $detail = calculerDetailProgression($pdo, $etudiantId, $coursId);

    echo json_encode([
        "success" => true,
        "total_lecons" => $detail["total"],
        "lecons_terminees" => $detail["done"],
        "progression" => $detail["pourcentage"]
    ]);
}

function calculerDetailProgression($pdo, $etudiantId, $coursId) {
    $stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM lecons WHERE id_cours = ?");
    $stmtTotal->execute([$coursId]);
    $total = (int)$stmtTotal->fetchColumn();

    $stmtDone = $pdo->prepare("
        SELECT COUNT(*) FROM progression p
        INNER JOIN lecons l ON l.id = p.id_lecon
        WHERE l.id_cours = ? AND p.id_etudiant = ? AND p.terminee = 1
    ");
    $stmtDone->execute([$coursId, $etudiantId]);
    $done = (int)$stmtDone->fetchColumn();

    return [
        "total" => $total,
        "done" => $done,
        "pourcentage" => $total > 0 ? round(($done / $total) * 100) : 0
    ];
}