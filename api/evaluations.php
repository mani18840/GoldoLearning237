<?php
/* ===== API/EVALUATIONS.PHP ===== */
/* Gère les évaluations (QCM) :
   Côté étudiant :
   - questions          : récupère les questions + options d'une évaluation (sans révéler la bonne réponse)
   - soumettre  (POST)  : corrige côté serveur, enregistre le résultat
   Côté enseignant :
   - creer      (POST)  : crée un QCM complet (évaluation + questions + options) pour une leçon
   - detail             : récupère un QCM AVEC les bonnes réponses (via evaluation_id OU lecon_id)
   - modifier   (POST)  : remplace entièrement les questions/options d'une évaluation existante
   - supprimer  (POST)  : supprime une évaluation et tout son contenu

   Convention de colonnes : id_xxx (comme le reste de ta base : id_cours, id_enseignant, etc.)

   Tables attendues :
     evaluations      (id, id_lecon, titre, date_creation)
     questions        (id, id_evaluation, enonce, ordre)
     options_reponse  (id, id_question, texte, est_correcte, ordre)
     resultats        (id, id_etudiant, id_evaluation, score, total, reussi, date_passage)

   Appelé depuis assets/js/etudiant.js et assets/js/enseignant.js sur : api/evaluations.php?action=...
*/

session_start();
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/config.php"; // fournit $pdo (PDO)

if (!isset($_SESSION["user_id"])) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Non autorisé."]);
    exit;
}

$userId = $_SESSION["user_id"];
$role = $_SESSION["role"];
$action = $_GET["action"] ?? "";

$actionsEtudiant = ["questions", "soumettre"];
$actionsEnseignant = ["creer", "detail", "modifier", "supprimer"];

if (in_array($action, $actionsEtudiant) && $role !== "etudiant") {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Réservé aux étudiants."]);
    exit;
}
if (in_array($action, $actionsEnseignant) && $role !== "enseignant") {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Réservé aux enseignants."]);
    exit;
}

try {
    switch ($action) {

        case "questions":
            getQuestions($pdo, $userId);
            break;

        case "soumettre":
            soumettre($pdo, $userId);
            break;

        case "creer":
            creerQcm($pdo, $userId);
            break;

        case "detail":
            getQcmDetailEnseignant($pdo, $userId);
            break;

        case "modifier":
            modifierQcm($pdo, $userId);
            break;

        case "supprimer":
            supprimerQcm($pdo, $userId);
            break;

        default:
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Action inconnue."]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Erreur serveur : " . $e->getMessage()]);
}

/* ================= FONCTIONS ÉTUDIANT ================= */

function getQuestions($pdo, $etudiantId) {
    $evaluationId = $_GET["evaluation_id"] ?? null;
    if (!$evaluationId) {
        echo json_encode(["success" => false, "message" => "Évaluation invalide."]);
        return;
    }

    if (!etudiantPeutAccederEvaluation($pdo, $etudiantId, $evaluationId)) {
        echo json_encode(["success" => false, "message" => "Vous n'avez pas accès à cette évaluation."]);
        return;
    }

    $stmtEval = $pdo->prepare("SELECT titre FROM evaluations WHERE id = ?");
    $stmtEval->execute([$evaluationId]);
    $titre = $stmtEval->fetchColumn();

    if ($titre === false) {
        echo json_encode(["success" => false, "message" => "Évaluation introuvable."]);
        return;
    }

    $stmtQ = $pdo->prepare("SELECT id, enonce FROM questions WHERE id_evaluation = ? ORDER BY ordre ASC");
    $stmtQ->execute([$evaluationId]);
    $questions = $stmtQ->fetchAll(PDO::FETCH_ASSOC);

    $stmtOpt = $pdo->prepare("SELECT id, texte FROM options_reponse WHERE id_question = ? ORDER BY ordre ASC");
    foreach ($questions as &$q) {
        $stmtOpt->execute([$q["id"]]);
        $q["options"] = $stmtOpt->fetchAll(PDO::FETCH_ASSOC);
    }

    echo json_encode(["success" => true, "titre" => $titre, "questions" => $questions]);
}

function soumettre($pdo, $etudiantId) {
    $data = json_decode(file_get_contents("php://input"), true);
    $evaluationId = $data["evaluation_id"] ?? null;
    $reponses = $data["reponses"] ?? [];

    if (!$evaluationId) {
        echo json_encode(["success" => false, "message" => "Évaluation invalide."]);
        return;
    }

    if (!etudiantPeutAccederEvaluation($pdo, $etudiantId, $evaluationId)) {
        echo json_encode(["success" => false, "message" => "Vous n'avez pas accès à cette évaluation."]);
        return;
    }

    $stmtQ = $pdo->prepare("SELECT id FROM questions WHERE id_evaluation = ?");
    $stmtQ->execute([$evaluationId]);
    $questionIds = $stmtQ->fetchAll(PDO::FETCH_COLUMN);
    $total = count($questionIds);

    if ($total === 0) {
        echo json_encode(["success" => false, "message" => "Cette évaluation n'a pas de questions."]);
        return;
    }

    $stmtCorrecte = $pdo->prepare("SELECT id FROM options_reponse WHERE id_question = ? AND est_correcte = 1");

    $score = 0;
    foreach ($questionIds as $qid) {
        $stmtCorrecte->execute([$qid]);
        $bonneReponse = $stmtCorrecte->fetchColumn();
        $reponseEtudiant = $reponses[$qid] ?? null;

        if ($reponseEtudiant !== null && (int)$reponseEtudiant === (int)$bonneReponse) {
            $score++;
        }
    }

    $seuilReussite = 0.6;
    $reussi = ($score / $total) >= $seuilReussite;

    $stmtSave = $pdo->prepare("
        INSERT INTO resultats (id_etudiant, id_evaluation, score, total, reussi, date_passage)
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

function etudiantPeutAccederEvaluation($pdo, $etudiantId, $evaluationId) {
    $stmt = $pdo->prepare("
        SELECT i.id
        FROM evaluations e
        INNER JOIN lecons l ON l.id = e.id_lecon
        INNER JOIN inscriptions i ON i.id_cours = l.id_cours
        WHERE e.id = ? AND i.id_etudiant = ?
    ");
    $stmt->execute([$evaluationId, $etudiantId]);
    return (bool)$stmt->fetch();
}

/* ================= FONCTIONS ENSEIGNANT ================= */

function enseignantPossedeLecon($pdo, $enseignantId, $leconId) {
    $stmt = $pdo->prepare("
        SELECT l.id
        FROM lecons l
        INNER JOIN cours c ON c.id = l.id_cours
        WHERE l.id = ? AND c.id_enseignant = ?
    ");
    $stmt->execute([$leconId, $enseignantId]);
    return (bool)$stmt->fetch();
}

function enseignantPossedeEvaluation($pdo, $enseignantId, $evaluationId) {
    $stmt = $pdo->prepare("
        SELECT e.id
        FROM evaluations e
        INNER JOIN lecons l ON l.id = e.id_lecon
        INNER JOIN cours c ON c.id = l.id_cours
        WHERE e.id = ? AND c.id_enseignant = ?
    ");
    $stmt->execute([$evaluationId, $enseignantId]);
    return (bool)$stmt->fetch();
}

function validerStructureQcm($data) {
    if (empty($data["titre"]) || empty($data["questions"]) || !is_array($data["questions"])) {
        return "Titre et au moins une question sont obligatoires.";
    }
    foreach ($data["questions"] as $i => $q) {
        if (empty($q["enonce"])) {
            return "La question " . ($i + 1) . " n'a pas d'énoncé.";
        }
        if (empty($q["options"]) || !is_array($q["options"]) || count($q["options"]) < 2) {
            return "La question " . ($i + 1) . " doit avoir au moins 2 options.";
        }
        $nbCorrectes = count(array_filter($q["options"], fn($o) => !empty($o["correcte"])));
        if ($nbCorrectes !== 1) {
            return "La question " . ($i + 1) . " doit avoir exactement une bonne réponse.";
        }
        foreach ($q["options"] as $o) {
            if (empty($o["texte"]) || trim($o["texte"]) === "") {
                return "Une option est vide dans la question " . ($i + 1) . ".";
            }
        }
    }
    return null;
}

function creerQcm($pdo, $enseignantId) {
    $data = json_decode(file_get_contents("php://input"), true);

    if (empty($data["lecon_id"])) {
        echo json_encode(["success" => false, "message" => "Leçon invalide."]);
        return;
    }

    $erreur = validerStructureQcm($data);
    if ($erreur) {
        echo json_encode(["success" => false, "message" => $erreur]);
        return;
    }

    if (!enseignantPossedeLecon($pdo, $enseignantId, $data["lecon_id"])) {
        echo json_encode(["success" => false, "message" => "Cette leçon ne vous appartient pas."]);
        return;
    }

    $checkExiste = $pdo->prepare("SELECT id FROM evaluations WHERE id_lecon = ?");
    $checkExiste->execute([$data["lecon_id"]]);
    if ($checkExiste->fetch()) {
        echo json_encode(["success" => false, "message" => "Cette leçon a déjà un QCM. Utilisez plutôt la modification."]);
        return;
    }

    try {
        $pdo->beginTransaction();

        $stmtEval = $pdo->prepare("INSERT INTO evaluations (id_lecon, titre, date_creation) VALUES (?, ?, NOW())");
        $stmtEval->execute([$data["lecon_id"], $data["titre"]]);
        $evaluationId = $pdo->lastInsertId();

        insererQuestions($pdo, $evaluationId, $data["questions"]);

        $pdo->commit();
        echo json_encode(["success" => true, "evaluation_id" => (int)$evaluationId]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(["success" => false, "message" => "Erreur lors de la création : " . $e->getMessage()]);
    }
}

function insererQuestions($pdo, $evaluationId, $questions) {
    $stmtQ = $pdo->prepare("INSERT INTO questions (id_evaluation, enonce, ordre) VALUES (?, ?, ?)");
    $stmtOpt = $pdo->prepare("INSERT INTO options_reponse (id_question, texte, est_correcte, ordre) VALUES (?, ?, ?, ?)");

    foreach ($questions as $qIndex => $q) {
        $stmtQ->execute([$evaluationId, trim($q["enonce"]), $qIndex]);
        $questionId = $pdo->lastInsertId();

        foreach ($q["options"] as $oIndex => $o) {
            $stmtOpt->execute([$questionId, trim($o["texte"]), !empty($o["correcte"]) ? 1 : 0, $oIndex]);
        }
    }
}

function getQcmDetailEnseignant($pdo, $enseignantId) {
    $evaluationId = $_GET["evaluation_id"] ?? null;
    $leconId = $_GET["lecon_id"] ?? null;

    if (!$evaluationId && !$leconId) {
        echo json_encode(["success" => false, "message" => "Évaluation ou leçon invalide."]);
        return;
    }

    if (!$evaluationId && $leconId) {
        if (!enseignantPossedeLecon($pdo, $enseignantId, $leconId)) {
            echo json_encode(["success" => false, "message" => "Cette leçon ne vous appartient pas."]);
            return;
        }
        $stmtFind = $pdo->prepare("SELECT id FROM evaluations WHERE id_lecon = ?");
        $stmtFind->execute([$leconId]);
        $evaluationId = $stmtFind->fetchColumn();

        if (!$evaluationId) {
            echo json_encode(["success" => false, "message" => "Cette leçon n'a pas encore de QCM."]);
            return;
        }
    }

    if (!enseignantPossedeEvaluation($pdo, $enseignantId, $evaluationId)) {
        echo json_encode(["success" => false, "message" => "Ce QCM ne vous appartient pas."]);
        return;
    }

    $stmtEval = $pdo->prepare("SELECT id, id_lecon, titre FROM evaluations WHERE id = ?");
    $stmtEval->execute([$evaluationId]);
    $evaluation = $stmtEval->fetch(PDO::FETCH_ASSOC);

    $stmtQ = $pdo->prepare("SELECT id, enonce FROM questions WHERE id_evaluation = ? ORDER BY ordre ASC");
    $stmtQ->execute([$evaluationId]);
    $questions = $stmtQ->fetchAll(PDO::FETCH_ASSOC);

    $stmtOpt = $pdo->prepare("SELECT id, texte, est_correcte FROM options_reponse WHERE id_question = ? ORDER BY ordre ASC");
    foreach ($questions as &$q) {
        $stmtOpt->execute([$q["id"]]);
        $options = $stmtOpt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($options as &$o) {
            $o["est_correcte"] = (bool)$o["est_correcte"];
        }
        $q["options"] = $options;
    }

    $evaluation["questions"] = $questions;

    echo json_encode(["success" => true, "evaluation" => $evaluation]);
}

function modifierQcm($pdo, $enseignantId) {
    $data = json_decode(file_get_contents("php://input"), true);
    $evaluationId = $data["evaluation_id"] ?? null;

    if (!$evaluationId) {
        echo json_encode(["success" => false, "message" => "Évaluation invalide."]);
        return;
    }

    if (!enseignantPossedeEvaluation($pdo, $enseignantId, $evaluationId)) {
        echo json_encode(["success" => false, "message" => "Ce QCM ne vous appartient pas."]);
        return;
    }

    $erreur = validerStructureQcm($data);
    if ($erreur) {
        echo json_encode(["success" => false, "message" => $erreur]);
        return;
    }

    try {
        $pdo->beginTransaction();

        $stmtUpd = $pdo->prepare("UPDATE evaluations SET titre = ? WHERE id = ?");
        $stmtUpd->execute([$data["titre"], $evaluationId]);

        $stmtOldQ = $pdo->prepare("SELECT id FROM questions WHERE id_evaluation = ?");
        $stmtOldQ->execute([$evaluationId]);
        $anciennesQuestions = $stmtOldQ->fetchAll(PDO::FETCH_COLUMN);

        if ($anciennesQuestions) {
            $in = implode(",", array_fill(0, count($anciennesQuestions), "?"));
            $pdo->prepare("DELETE FROM options_reponse WHERE id_question IN ($in)")->execute($anciennesQuestions);
        }
        $pdo->prepare("DELETE FROM questions WHERE id_evaluation = ?")->execute([$evaluationId]);

        insererQuestions($pdo, $evaluationId, $data["questions"]);

        $pdo->commit();
        echo json_encode(["success" => true, "message" => "QCM mis à jour."]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(["success" => false, "message" => "Erreur lors de la modification : " . $e->getMessage()]);
    }
}

function supprimerQcm($pdo, $enseignantId) {
    $data = json_decode(file_get_contents("php://input"), true);
    $evaluationId = $data["evaluation_id"] ?? null;

    if (!$evaluationId) {
        echo json_encode(["success" => false, "message" => "Évaluation invalide."]);
        return;
    }

    if (!enseignantPossedeEvaluation($pdo, $enseignantId, $evaluationId)) {
        echo json_encode(["success" => false, "message" => "Ce QCM ne vous appartient pas."]);
        return;
    }

    $stmt = $pdo->prepare("DELETE FROM evaluations WHERE id = ?");
    $stmt->execute([$evaluationId]);

    echo json_encode(["success" => true, "message" => "QCM supprimé."]);
}