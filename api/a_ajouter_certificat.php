<?php
/* ===== À AJOUTER À LA FIN DE api/certificat.php (après ta fonction genererCertificatPdf) =====
   Convention colonnes : id_xxx (id_etudiant, id_cours, id_lecon...)
   ⚠️ Si ton certificat.php a déjà session_start() tout en haut, RETIRE le session_start()
   ci-dessous pour éviter l'erreur "session already started". */

/* ---------- Fonction appelée par api/lecons.php quand un cours atteint 100% ---------- */

function genererCertificatSiNecessaire($pdo, $etudiantId, $coursId) {
    $check = $pdo->prepare("SELECT id FROM certificats WHERE id_etudiant = ? AND id_cours = ?");
    $check->execute([$etudiantId, $coursId]);
    if ($check->fetch()) return; // déjà généré

    $stmtUser = $pdo->prepare("SELECT nom, matricule FROM utilisateurs WHERE id = ?");
    $stmtUser->execute([$etudiantId]);
    $user = $stmtUser->fetch(PDO::FETCH_ASSOC);

    $stmtCours = $pdo->prepare("SELECT titre FROM cours WHERE id = ?");
    $stmtCours->execute([$coursId]);
    $titreCours = $stmtCours->fetchColumn();

    $dateObtention = date("Y-m-d H:i:s");

    $urlPdf = genererCertificatPdf(
        $user["nom"] ?? "Étudiant",
        $user["matricule"] ?? "N/A",
        $titreCours ?: "Cours",
        $dateObtention
    );

    $stmt = $pdo->prepare("
        INSERT INTO certificats (id_etudiant, id_cours, date_obtention, url_pdf)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$etudiantId, $coursId, $dateObtention, $urlPdf]);
}

/* ---------- Routeur HTTP : uniquement si ce fichier est appelé directement en AJAX ---------- */

if (basename($_SERVER["SCRIPT_FILENAME"]) === basename(__FILE__)) {

    session_start(); // ⚠️ RETIRE cette ligne si déjà présente en haut du fichier
    header("Content-Type: application/json; charset=UTF-8");

    require_once __DIR__ . "/config.php";

    if (!isset($_SESSION["user_id"]) || $_SESSION["role"] !== "etudiant") {
        http_response_code(401);
        echo json_encode(["success" => false, "message" => "Non autorisé."]);
        exit;
    }

    $etudiantId = $_SESSION["user_id"];
    $action = $_GET["action"] ?? "";

    if ($action === "liste") {
        $stmt = $pdo->prepare("
            SELECT cert.id, c.titre AS cours_titre, cert.date_obtention, cert.url_pdf
            FROM certificats cert
            INNER JOIN cours c ON c.id = cert.id_cours
            WHERE cert.id_etudiant = ?
            ORDER BY cert.date_obtention DESC
        ");
        $stmt->execute([$etudiantId]);
        $certificats = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(["success" => true, "certificats" => $certificats]);
    } else {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Action inconnue."]);
    }
}