<?php
/**
 * Scholarship Tracker Pro - REST API Endpoint
 * Handles fetching, updating, adding, editing, deleting, data importing, and auto-seeding scholarship data.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/config.php';

$pdo = getDbConnection();
$action = $_GET['action'] ?? $_POST['action'] ?? 'get_scholarships';

// Auto-initialize DB tables if missing or add missing columns
initDatabaseIfEmpty($pdo);

switch ($action) {
    case 'get_scholarships':
        getScholarships($pdo);
        break;

    case 'update_progress':
        updateProgress($pdo);
        break;

    case 'add_scholarship':
        addScholarship($pdo);
        break;

    case 'update_scholarship':
        updateScholarship($pdo);
        break;

    case 'delete_scholarship':
        deleteScholarship($pdo);
        break;

    case 'reset_progress':
        resetProgress($pdo);
        break;

    case 'import_data':
        importData($pdo);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
        break;
}

/**
 * Ensures database tables exist and seeds 82 default scholarships if empty.
 * Auto-alters table if new columns are missing.
 */
function initDatabaseIfEmpty($pdo) {
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `scholarships` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(255) NOT NULL,
                `uni` VARCHAR(255) NOT NULL,
                `country` VARCHAR(100) NOT NULL,
                `level` VARCHAR(100) DEFAULT 'Master\'s',
                `funding` VARCHAR(20) DEFAULT 'fully',
                `priority_status` VARCHAR(20) DEFAULT 'medium',
                `deadline` VARCHAR(100) DEFAULT 'varies',
                `stipend` VARCHAR(255) DEFAULT '',
                `tags` TEXT,
                `toefl` VARCHAR(255) DEFAULT '',
                `unis` TEXT,
                `fee` VARCHAR(20) DEFAULT 'free',
                `is_custom` TINYINT(1) DEFAULT 0,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            CREATE TABLE IF NOT EXISTS `scholarship_progress` (
                `scholarship_id` INT PRIMARY KEY,
                `app_status` VARCHAR(50) DEFAULT 'not started',
                `rating` INT DEFAULT 0,
                `student_notes` TEXT,
                `counsellor_notes` TEXT,
                `checklist` TEXT,
                `doc_links` TEXT,
                `comment_history` TEXT,
                `tracked` TINYINT(1) DEFAULT 0,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (`scholarship_id`) REFERENCES `scholarships`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        // Migration check for missing columns
        $cols = $pdo->query("SHOW COLUMNS FROM `scholarship_progress`")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('doc_links', $cols)) {
            $pdo->exec("ALTER TABLE `scholarship_progress` ADD COLUMN `doc_links` TEXT AFTER `checklist`");
        }
        if (!in_array('comment_history', $cols)) {
            $pdo->exec("ALTER TABLE `scholarship_progress` ADD COLUMN `comment_history` TEXT AFTER `doc_links`");
        }

        $stmt = $pdo->query("SELECT COUNT(*) FROM `scholarships`");
        if ($stmt->fetchColumn() == 0) {
            seedDefaultScholarships($pdo);
        }
    } catch (Exception $e) {
        // Table creation errors logged silently or thrown
    }
}

/**
 * Returns all scholarships merged with progress data
 */
function getScholarships($pdo) {
    try {
        $sql = "
            SELECT 
                s.id, s.name, s.uni, s.country, s.level, s.funding, 
                s.priority_status AS status, s.deadline, s.stipend, 
                s.tags, s.toefl, s.unis, s.fee, s.is_custom,
                COALESCE(p.app_status, 'not started') AS appStatus,
                COALESCE(p.rating, 0) AS rating,
                COALESCE(p.student_notes, '') AS notes,
                COALESCE(p.counsellor_notes, '') AS counsellorNotes,
                COALESCE(p.checklist, '{}') AS checklist,
                COALESCE(p.doc_links, '{}') AS docLinks,
                COALESCE(p.comment_history, '[]') AS commentHistory,
                COALESCE(p.tracked, 0) AS tracked
            FROM `scholarships` s
            LEFT JOIN `scholarship_progress` p ON s.id = p.scholarship_id
            ORDER BY s.id ASC
        ";
        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll();

        $scholarships = array_map(function($r) {
            $r['id'] = (int)$r['id'];
            $r['rating'] = (int)$r['rating'];
            $r['tracked'] = (bool)$r['tracked'];
            $r['is_custom'] = (bool)$r['is_custom'];
            $r['tags'] = json_decode($r['tags'] ?? '[]', true) ?: [];
            $r['unis'] = json_decode($r['unis'] ?? '[]', true) ?: [];
            $r['checklist'] = json_decode($r['checklist'] ?? '{}', true) ?: new stdClass();
            $r['docLinks'] = json_decode($r['docLinks'] ?? '{}', true) ?: new stdClass();
            $r['commentHistory'] = json_decode($r['commentHistory'] ?? '[]', true) ?: [];
            return $r;
        }, $rows);

        echo json_encode(['success' => true, 'scholarships' => $scholarships]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

/**
 * Updates application progress, notes, ratings, checklist, doc links, or comment history
 */
function updateProgress($pdo) {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = intval($input['id'] ?? 0);

    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid scholarship ID']);
        return;
    }

    try {
        $sql = "
            INSERT INTO `scholarship_progress` 
                (scholarship_id, app_status, rating, student_notes, counsellor_notes, checklist, doc_links, comment_history, tracked)
            VALUES 
                (:id, :appStatus, :rating, :notes, :counsellorNotes, :checklist, :docLinks, :commentHistory, :tracked)
            ON DUPLICATE KEY UPDATE
                app_status = VALUES(app_status),
                rating = VALUES(rating),
                student_notes = VALUES(student_notes),
                counsellor_notes = VALUES(counsellor_notes),
                checklist = VALUES(checklist),
                doc_links = VALUES(doc_links),
                comment_history = VALUES(comment_history),
                tracked = VALUES(tracked)
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':id'               => $id,
            ':appStatus'        => $input['appStatus'] ?? 'not started',
            ':rating'           => intval($input['rating'] ?? 0),
            ':notes'            => $input['notes'] ?? '',
            ':counsellorNotes'  => $input['counsellorNotes'] ?? '',
            ':checklist'        => json_encode($input['checklist'] ?? new stdClass()),
            ':docLinks'         => json_encode($input['docLinks'] ?? new stdClass()),
            ':commentHistory'   => json_encode($input['commentHistory'] ?? []),
            ':tracked'          => !empty($input['tracked']) ? 1 : 0
        ]);

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

/**
 * Adds a new custom scholarship
 */
function addScholarship($pdo) {
    $input = json_decode(file_get_contents('php://input'), true);

    if (empty($input['name']) || empty($input['country'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Scholarship name and country are required']);
        return;
    }

    try {
        $sql = "
            INSERT INTO `scholarships` 
                (name, uni, country, level, funding, priority_status, deadline, stipend, tags, toefl, unis, fee, is_custom)
            VALUES 
                (:name, :uni, :country, :level, :funding, :status, :deadline, :stipend, :tags, :toefl, :unis, :fee, 1)
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':name'     => $input['name'],
            ':uni'      => $input['uni'] ?? '',
            ':country'  => $input['country'],
            ':level'    => $input['level'] ?? "Master's",
            ':funding'  => $input['funding'] ?? 'fully',
            ':status'   => $input['status'] ?? 'medium',
            ':deadline' => $input['deadline'] ?? 'varies',
            ':stipend'  => $input['stipend'] ?? '',
            ':tags'     => json_encode($input['tags'] ?? []),
            ':toefl'    => $input['toefl'] ?? '',
            ':unis'     => json_encode($input['unis'] ?? []),
            ':fee'      => $input['fee'] ?? 'free'
        ]);

        $newId = $pdo->lastInsertId();
        echo json_encode(['success' => true, 'id' => (int)$newId]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

/**
 * Updates details of an existing scholarship
 */
function updateScholarship($pdo) {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = intval($input['id'] ?? 0);

    if ($id <= 0 || empty($input['name'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
        return;
    }

    try {
        $sql = "
            UPDATE `scholarships` 
            SET name = :name, uni = :uni, country = :country, level = :level, 
                funding = :funding, priority_status = :status, deadline = :deadline, 
                stipend = :stipend, tags = :tags, toefl = :toefl, fee = :fee
            WHERE id = :id
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':id'       => $id,
            ':name'     => $input['name'],
            ':uni'      => $input['uni'] ?? '',
            ':country'  => $input['country'] ?? '',
            ':level'    => $input['level'] ?? "Master's",
            ':funding'  => $input['funding'] ?? 'fully',
            ':status'   => $input['status'] ?? 'medium',
            ':deadline' => $input['deadline'] ?? 'varies',
            ':stipend'  => $input['stipend'] ?? '',
            ':tags'     => json_encode($input['tags'] ?? []),
            ':toefl'    => $input['toefl'] ?? '',
            ':fee'      => $input['fee'] ?? 'free'
        ]);

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

/**
 * Deletes a scholarship
 */
function deleteScholarship($pdo) {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = intval($input['id'] ?? 0);

    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid ID']);
        return;
    }

    try {
        $stmt = $pdo->prepare("DELETE FROM `scholarships` WHERE id = :id");
        $stmt->execute([':id' => $id]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

/**
 * Resets all scholarship application progress
 */
function resetProgress($pdo) {
    try {
        $pdo->exec("TRUNCATE TABLE `scholarship_progress`");
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

/**
 * Imports dataset array into MySQL
 */
function importData($pdo) {
    $input = json_decode(file_get_contents('php://input'), true);
    $scholarships = $input['scholarships'] ?? [];

    if (!is_array($scholarships) || empty($scholarships)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'No scholarships data provided']);
        return;
    }

    try {
        $pdo->beginTransaction();
        foreach ($scholarships as $item) {
            if (empty($item['id'])) continue;
            
            $sqlProgress = "
                INSERT INTO `scholarship_progress` 
                    (scholarship_id, app_status, rating, student_notes, counsellor_notes, checklist, doc_links, comment_history, tracked)
                VALUES 
                    (:id, :appStatus, :rating, :notes, :counsellorNotes, :checklist, :docLinks, :commentHistory, :tracked)
                ON DUPLICATE KEY UPDATE
                    app_status = VALUES(app_status),
                    rating = VALUES(rating),
                    student_notes = VALUES(student_notes),
                    counsellor_notes = VALUES(counsellor_notes),
                    checklist = VALUES(checklist),
                    doc_links = VALUES(doc_links),
                    comment_history = VALUES(comment_history),
                    tracked = VALUES(tracked)
            ";
            $stmt = $pdo->prepare($sqlProgress);
            $stmt->execute([
                ':id'               => intval($item['id']),
                ':appStatus'        => $item['appStatus'] ?? 'not started',
                ':rating'           => intval($item['rating'] ?? 0),
                ':notes'            => $item['notes'] ?? '',
                ':counsellorNotes'  => $item['counsellorNotes'] ?? '',
                ':checklist'        => json_encode($item['checklist'] ?? new stdClass()),
                ':docLinks'         => json_encode($item['docLinks'] ?? new stdClass()),
                ':commentHistory'   => json_encode($item['commentHistory'] ?? []),
                ':tracked'          => !empty($item['tracked']) ? 1 : 0
            ]);
        }
        $pdo->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

/**
 * Seeds all 82 initial scholarships into MySQL
 */
function seedDefaultScholarships($pdo) {
    $defaultScholarships = [
        [1, 'Stipendium Hungaricum', 'Hungarian Universities', '🇭🇺 Hungary', "Master's", 'fully', 'high', 'Jan 15, 2027', 'HUF 43,700/mo', ['tuition','stipend','accommodation'], 'MOI accepted', ['Debrecen','Eötvös Loránd','BME','Szeged'], 'free'],
        [2, 'Türkiye Burslari', 'Turkish Universities', '🇹🇷 Turkey', "Master's", 'fully', 'high', 'Feb 20, 2027', '6,500 TL/mo', ['tuition','dorm','health','Turkish'], 'not required', ['Istanbul','Ankara','METU','Bogazici'], 'free'],
        [3, 'KAUST Scholarship', 'KAUST', '🇸🇦 Saudi Arabia', "Master's", 'fully', 'high', 'Jan 15, 2027', '$20k–30k/yr', ['CS/AI/DS','on-campus','health'], 'TOEFL 81+', ['KAUST'], 'free'],
        [4, 'Australia Awards', 'Australian Universities', '🇦🇺 Australia', "Master's", 'fully', 'high', 'Apr 30, 2026', '~AUD 3,500/mo', ['return obligation','MOI'], 'MOI accepted', ['Melbourne','Sydney','UNSW','ANU'], 'free'],
        [5, 'CSC Scholarship (China)', '280+ Chinese Universities', '🇨🇳 China', "Master's", 'fully', 'high', 'Mar 15, 2027', 'up to 3,500 Yuan/mo', ['MOI','accommodation'], 'MOI accepted', ['Tsinghua','Peking','Fudan','SJTU'], 'free'],
        [6, 'MEXT Scholarship', 'Japanese Universities', '🇯🇵 Japan', "Master's", 'fully', 'high', 'May 31, 2026', '¥144,000/mo', ['MOI','language training'], 'MOI accepted', ['Tokyo','Kyoto','Osaka','Tohoku'], 'free'],
        [7, 'KAIST Scholarship', 'KAIST', '🇰🇷 South Korea', "Master's", 'fully', 'high', 'Sep 1, 2026', '350,000–400,000 KRW/mo', ['CGPA 3.0+','tech'], 'TOEFL 83+', ['KAIST'], 'paid'],
        [8, 'GKS Scholarship (Korea)', 'Korean Universities', '🇰🇷 South Korea', "Master's", 'fully', 'high', 'Feb 28, 2027', '₩900,000/mo', ['TOPIK','top 5–13%'], 'required', ['Seoul National','Korea Univ','Yonsei','POSTECH'], 'free'],
        [9, 'Shanghai Government Scholarship', 'Shanghai Universities', '🇨🇳 China', "Master's", 'fully', 'high', 'varies', 'CNY 3,000/mo', ['MOI','accommodation'], 'MOI accepted', ['Fudan','SJTU','Tongji','ECNU'], 'free'],
        [10, 'Hubei Provincial Scholarship', 'Hubei Universities', '🇨🇳 China', "Master's", 'fully', 'high', 'varies', 'RMB 15,000/yr', ['MOI','age<30'], 'MOI accepted', ['Wuhan','HUST','CUG'], 'free'],
        [11, 'Malaysia MTCP Scholarship', 'Malaysian Universities', '🇲🇾 Malaysia', "Master's", 'fully', 'high', 'Jun 30, 2026', 'full + allowances', ['CGPA 3.0+','no IELTS'], 'MOI accepted', ['UM','UKM','USM','UTM'], 'free'],
        [12, 'Tianjin Government Scholarship', 'Nankai University', '🇨🇳 China', "Master's", 'fully', 'high', 'Jun 15, 2027', 'CNY 1,700/mo', ['settlement','MOI'], 'TOEFL 87+ / IELTS 6.0+', ['Nankai'], 'paid'],
        [13, 'DAAD Scholarship', 'German Universities', '🇩🇪 Germany', "Master's", 'fully', 'medium', 'Oct 15, 2026', '€992/mo', ['tuition-free','research'], 'IELTS/TOEFL', ['TU Munich','Heidelberg','HU Berlin','FU Berlin'], 'free'],
        [14, 'DAAD EPOS', 'German Universities', '🇩🇪 Germany', "Master's", 'fully', 'medium', 'Nov 15, 2026', '€992/mo', ['2+ yrs exp.','development'], 'MOI accepted', ['Göttingen','Bonn','TU Berlin'], 'free'],
        [15, 'SBW Berlin Scholarship', 'Berlin/Potsdam', '🇩🇪 Germany', "Master's", 'fully', 'medium', 'rolling', 'full + accommodation', ['social commitment','18–30'], 'MOI accepted', ['FU Berlin','HU Berlin','Potsdam'], 'free'],
        [16, 'Friedrich Ebert Stiftung', 'German Universities', '🇩🇪 Germany', "Master's", 'fully', 'medium', 'varies', 'up to €1,500/mo', ['social democracy'], 'IELTS 6.0+', ['Bonn','FU Berlin','Hamburg'], 'free'],
        [17, 'Heinrich Böll Foundation', 'German Universities', '🇩🇪 Germany', "Master's", 'fully', 'medium', 'varies', 'full', ['ecology','human rights'], 'IELTS 6.0+', ['Cologne','Freiburg','TU Berlin'], 'free'],
        [18, 'Konrad-Adenauer-Stiftung', 'German Universities', '🇩🇪 Germany', "Master's", 'fully', 'medium', 'varies', '€992/mo + tuition', ['democracy'], 'IELTS 6.0+', ['Tübingen','Munich','Heidelberg'], 'free'],
        [19, 'Finland Government Scholarships', 'Finnish Universities', '🇫🇮 Finland', "Master's", 'fully', 'medium', 'varies', '€5,000 relocation + tuition', ['MOI','first-year'], 'MOI accepted', ['Helsinki','Aalto','Tampere','Turku'], 'free'],
        [20, 'Eiffel Excellence (France)', 'French Universities', '🇫🇷 France', "Master's", 'fully', 'medium', 'Jan 10, 2027', '€1,181/mo', ['science/engineering','institution-nom'], 'required', ['Sorbonne','Paris-Saclay','Sciences Po','ENS Lyon'], 'free'],
        [21, 'Ampère Excellence (ENS Lyon)', 'ENS de Lyon', '🇫🇷 France', "Master's", 'fully', 'medium', 'Jan 31, 2027', '€1,000/mo', ['~20 awards','exact sciences'], 'required', ['ENS de Lyon'], 'free'],
        [22, 'Université Paris-Saclay', 'Paris-Saclay', '🇫🇷 France', "Master's", 'fully', 'medium', 'varies', '€10,000/yr + travel', ['science/tech'], 'required', ['Paris-Saclay'], 'free'],
        [23, 'Polish Banach Scholarship', 'Polish Universities', '🇵🇱 Poland', "Master's", 'fully', 'medium', 'Mar 31, 2026', 'PLN 2,500/mo', ['tuition-free','preparatory'], 'MOI accepted', ['Warsaw','Jagiellonian','WUT'], 'free'],
        [24, 'Ignacy Łukasiewicz', 'Polish Universities', '🇵🇱 Poland', "Master's", 'fully', 'medium', 'varies', 'tuition + stipend', ['MOI'], 'MOI accepted', ['AGH','Poznań','Wrocław'], 'free'],
        [25, 'University of Warsaw Scholarships', 'University of Warsaw', '🇵🇱 Poland', "Master's", 'fully', 'medium', 'varies', 'varies', ['NAWA','MOI'], 'MOI accepted', ['Warsaw'], 'free'],
        [26, 'NL Scholarship (Holland)', 'Dutch Universities', '🇳🇱 Netherlands', "Master's", 'partial', 'medium', 'Feb 1, 2027', '€5,000 one-time', ['partial','tuition gap'], 'IELTS 6.0+', ['Amsterdam','TU Delft','Utrecht','Leiden'], 'free'],
        [27, 'University of Twente Scholarship', 'University of Twente', '🇳🇱 Netherlands', "Master's", 'partial', 'medium', 'varies', '€3k–22k/yr', ['partial','tech'], 'IELTS 6.5+', ['Twente'], 'free'],
        [28, 'Holland Government Scholarships', 'Dutch Universities', '🇳🇱 Netherlands', "Master's", 'fully', 'medium', 'varies', 'full package', ['MOI'], 'MOI accepted', ['Amsterdam','Leiden','TU Delft','Utrecht'], 'free'],
        [29, 'GOI-IES Ireland', 'Irish Universities', '🇮🇪 Ireland', "Master's", 'partial', 'medium', 'Mar 15, 2027', '€10,000 + tuition waiver', ['1 year','60 awards'], 'required', ['Trinity','UCD','Galway'], 'free'],
        [30, 'UCD Scholarships', 'University College Dublin', '🇮🇪 Ireland', "Master's", 'fully', 'medium', 'varies', 'varies', ['CS/IT'], 'IELTS 6.5+', ['UCD'], 'free'],
        [31, 'University of Alberta Scholarship', 'University of Alberta', '🇨🇦 Canada', "Master's", 'fully', 'medium', 'varies', 'up to $40,000', ['CS/IT','thesis-based'], 'IELTS 6.5+', ['Alberta'], 'paid'],
        [32, 'Université de Montréal', 'UdeM', '🇨🇦 Canada', "Master's", 'fully', 'medium', 'varies', 'up to $27,300', ['tuition waiver'], 'IELTS 6.5+', ['UdeM'], 'paid'],
        [33, 'University of Waterloo', 'Waterloo', '🇨🇦 Canada', "Master's", 'fully', 'medium', 'varies', 'up to $10,000', ['CS/IT'], 'IELTS 6.5+', ['Waterloo'], 'paid'],
        [34, 'McGill University', 'McGill', '🇨🇦 Canada', "Master's", 'fully', 'medium', 'varies', 'bursaries', ['CS/IT'], 'IELTS 6.5+', ['McGill'], 'paid'],
        [35, 'Dalhousie University', 'Dalhousie', '🇨🇦 Canada', "Master's", 'fully', 'medium', 'varies', '$30,000 package', ['CS/IT'], 'IELTS 7.0+', ['Dalhousie'], 'paid'],
        [36, 'McMaster University', 'McMaster', '🇨🇦 Canada', "Master's", 'fully', 'medium', 'varies', 'up to $21,000', ['CS/IT'], 'IELTS 6.5+', ['McMaster'], 'paid'],
        [37, 'University of Saskatchewan', 'Saskatchewan', '🇨🇦 Canada', "Master's", 'fully', 'medium', 'varies', '$16,000', ['CS/IT'], 'IELTS 6.5+', ['Saskatchewan'], 'paid'],
        [38, 'University of New Haven', 'New Haven', '🇺🇸 USA', "Master's", 'fully', 'medium', 'varies', '75% tuition + wage', ['CS/IT','experiential'], 'IELTS 6.5+', ['New Haven'], 'paid'],
        [39, 'University of Minnesota Fellowship', 'Minnesota', '🇺🇸 USA', "Master's", 'fully', 'medium', 'varies', 'full + living', ['CS/IT'], 'IELTS 6.5+', ['Minnesota'], 'paid'],
        [40, 'AAUW International Fellowship', 'AAUW', '🇺🇸 USA', "Master's", 'fully', 'medium', 'varies', '$18k–30k', ['women'], 'IELTS 6.5+', ['AAUW'], 'free'],
        [41, 'ACI Foundation Scholarship', 'ACI Foundation', '🇺🇸 USA', "Master's", 'fully', 'medium', 'varies', '$10k–15k', ['engineering/CS'], 'IELTS 6.5+', ['ACI'], 'free'],
        [42, 'University of Florida Scholarships', 'Florida', '🇺🇸 USA', "Master's", 'fully', 'medium', 'varies', 'multiple', ['CS/DS'], 'required', ['Florida'], 'paid'],
        [43, 'Honjo International (Japan)', 'Honjo Foundation', '🇯🇵 Japan', "Master's", 'fully', 'medium', 'varies', 'allowance + accommodation', ['CS/IT'], 'required', ['Honjo'], 'free'],
        [44, 'SINGA Scholarship (Singapore)', 'NUS/NTU/SUTD', '🇸🇬 Singapore', "Master's", 'fully', 'medium', 'Jun 1, 2026', 'S$2,700–3,200/mo', ['CGPA 3.0+','tech'], 'optional', ['NUS','NTU','SUTD'], 'free'],
        [45, 'Brunei Darussalam BDGS', 'Brunei Universities', '🇧🇳 Brunei', "Master's", 'fully', 'medium', 'Feb 15, 2027', 'BND 650/mo', ['age<35','free dorm'], 'required', ['UBD','UTB'], 'free'],
        [46, 'Taiwan Scholarship (MOE)', 'Taiwan Universities', '🇹🇼 Taiwan', "Master's", 'fully', 'medium', 'Mar 31, 2027', 'NTD 20,000/mo', ['TOCFL'], 'required', ['NTU','NTHU','NCCU'], 'free'],
        [47, 'TaiwanICDF Scholarship', 'Taiwan Universities', '🇹🇼 Taiwan', "Master's", 'fully', 'medium', 'Mar 15, 2027', 'NTD 18–20k/mo', ['fixed programs','dorm'], 'required', ['ICDF'], 'free'],
        [48, 'University of South Australia', 'UniSA', '🇦🇺 Australia', "Master's", 'fully', 'medium', 'varies', 'stipend + tuition', ['MOI','300 scholarships'], 'MOI accepted', ['UniSA'], 'free'],
        [49, 'Deakin University RTP/DUPRS', 'Deakin', '🇦🇺 Australia', "Master's", 'fully', 'medium', 'varies', '$37,450/yr', ['research','OSHC'], 'IELTS 6.5+', ['Deakin'], 'free'],
        [50, 'University of Tasmania TGRS', 'UTAS', '🇦🇺 Australia', "Master's", 'fully', 'medium', 'Oct 1, 2026', 'AUD 34,315/yr', ['research','relocation'], 'IELTS 6.5+', ['UTAS'], 'free'],
        [51, 'Erasmus Mundus', 'EU Consortiums', '🇪🇺 Europe', "Master's", 'fully', 'medium', 'Jan 15, 2027', '€1,400/mo + travel', ['MOI','3,000/yr'], 'MOI accepted', ['EU'], 'free'],
        [52, 'Aga Khan Foundation (AKF)', 'Worldwide (excl. UK/Russia)', '🌍 Multi', "Master's", 'partial', 'medium', 'Mar 31, 2027', '50% grant + loan', ['development','age<30'], 'required', ['AKF'], 'free'],
        [53, 'KTH Royal Institute Scholarships', 'KTH', '🇸🇪 Sweden', "Master's", 'fully', 'medium', 'Jan 15, 2027', 'tuition + stipend (some)', ['CS/AI','top uni'], 'required', ['KTH'], 'free'],
        [54, 'LiU International Scholarship', 'Linköping', '🇸🇪 Sweden', "Master's", 'partial', 'medium', 'Mar 30, 2027', '50% tuition', ['partial'], 'IELTS 6.5+', ['Linköping'], 'free'],
        [55, 'Uppsala University Scholarships', 'Uppsala', '🇸🇪 Sweden', "Master's", 'fully', 'medium', 'varies', 'tuition + stipend', ['MOI'], 'MOI accepted', ['Uppsala'], 'free'],
        [56, 'Chalmers IPOET', 'Chalmers', '🇸🇪 Sweden', "Master's", 'partial', 'medium', 'varies', '75–100% tuition', ['partial'], 'MOI accepted', ['Chalmers'], 'free'],
        [57, 'Danish Government Scholarships', 'Danish Universities', '🇩🇰 Denmark', "Master's", 'fully', 'medium', 'Jan 15, 2027', 'tuition + stipend (varies)', ['DTU/CBS/SDU','98% GPA'], 'TOEFL 83+', ['Aarhus','Copenhagen','DTU','CBS','SDU'], 'free'],
        [58, 'Aarhus University Danish Gov.', 'Aarhus University', '🇩🇰 Denmark', "Master's", 'fully', 'medium', 'Sep 15, 2026', 'tuition + stipend (faculty)', ['non-EU','automatic'], 'required / MOI', ['Aarhus'], 'free'],
        [59, 'Portugal Government Scholarships', 'Portuguese Universities', '🇵🇹 Portugal', "Master's", 'fully', 'medium', 'Sep 30, 2026', 'up to €1,250/mo', ['CS/AI','tuition'], 'required', ['Lisbon','Porto','Coimbra'], 'free'],
        [60, 'Estonia Government Scholarships', 'Estonian Universities', '🇪🇪 Estonia', "Master's", 'fully', 'medium', 'varies', 'tuition + stipend', ['MOI'], 'MOI accepted', ['Tartu','Tallinn','TalTech'], 'free'],
        [61, 'Austria Government Scholarships', 'Austrian Universities', '🇦🇹 Austria', "Master's", 'fully', 'medium', 'Sep 1, 2026', 'monthly stipend', ['age<35','MOI'], 'MOI accepted', ['Vienna','TU Wien','Innsbruck'], 'free'],
        [62, 'Spanish Government Scholarships', 'Spanish Universities', '🇪🇸 Spain', "Master's", 'fully', 'medium', 'varies', 'grants', ['MOI'], 'MOI accepted', ['Barcelona','UAM','Granada'], 'free'],
        [63, 'Slovenia Scholarships', 'Slovenian Universities', '🇸🇮 Slovenia', "Master's", 'fully', 'medium', 'varies', '€2k–4.8k/yr', ['MOI'], 'MOI accepted', ['Ljubljana','Maribor','Primorska'], 'free'],
        [64, 'University of Bern Scholarships', 'University of Bern', '🇨🇭 Switzerland', "Master's", 'fully', 'medium', 'varies', 'tuition + stipend', ['Swiss Excellence'], 'required', ['Bern'], 'free'],
        [65, 'University of Western Australia', 'UWA', '🇦🇺 Australia', "Master's", 'partial', 'medium', 'rolling', 'up to AUD 24,000', ['automatic','WAM 70+'], 'required', ['UWA'], 'free'],
        [66, 'Macquarie University VCIS', 'Macquarie', '🇦🇺 Australia', "Master's", 'partial', 'medium', 'rolling', 'up to AUD 10,000', ['WAM 65+','tuition reduction'], 'required', ['Macquarie'], 'free'],
        [67, 'Mexico AMEXCID (No IELTS)', 'Mexican Universities', '🇲🇽 Mexico', "Master's", 'fully', 'medium', 'varies', 'tuition + stipend', ['MOI','OAS'], 'MOI accepted', ['UNAM','Monterrey IT','UDG'], 'free'],
        [68, 'Denmark Scholarships Without IELTS', 'Danish Universities', '🇩🇰 Denmark', "Master's", 'fully', 'medium', 'varies', 'varies', ['MOI','ITU/Roskilde'], 'MOI accepted', ['ITU','Roskilde','Aarhus'], 'free'],
        [69, 'UST Scholarship (Korea)', 'UST', '🇰🇷 South Korea', "Master's", 'fully', 'medium', 'May 7, 2027', 'KRW 1,430,000/mo', ['CGPA 3.0+','research'], 'required', ['UST'], 'free'],
        [70, 'Gotoh Tokyu Group (Japan)', 'Tokyo City University', '🇯🇵 Japan', "Master's", 'fully', 'medium', 'Sep 25, 2026', '¥1,000,000/yr', ['dormitory','tech'], 'required', ['TCU'], 'free'],
        [71, 'Dongguan University of Tech', 'DGUT', '🇨🇳 China', "Master's", 'fully', 'medium', 'Jun 30, 2027', 'RMB 20k tuition + 10k living', ['Chinese'], 'Chinese required', ['DGUT'], 'free'],
        [72, 'Beijing Government Scholarship', 'Beijing Universities', '🇨🇳 China', "Master's", 'fully', 'medium', 'varies', 'up to 40,000 Yuan', ['MOI'], 'MOI accepted', ['Beijing','BIT'], 'free'],
        [73, 'Stanford Knight-Hennessy', 'Stanford University', '🇺🇸 USA', "Master's", 'fully', 'low', 'Oct 6, 2026', 'full + stipend', ['top tier','up to 100'], 'required', ['Stanford'], 'free'],
        [74, 'Chevening Scholarship', 'UK Universities', '🇬🇧 UK', "Master's", 'fully', 'low', 'Oct 7, 2026', '£1,516/mo', ['2+ yrs exp.'], 'IELTS 6.5+', ['Oxford','Cambridge','LSE','Imperial'], 'free'],
        [75, 'Commonwealth Scholarship', 'UK Universities', '🇬🇧 UK', "Master's", 'fully', 'low', 'Dec 2026', '£1,347/mo', ['HEC nomination'], 'required', ['UK'], 'free'],
        [76, 'University of Bristol Think Big', 'Bristol', '🇬🇧 UK', "Master's", 'partial', 'low', 'varies', 'up to £20,000', ['partial'], 'IELTS 6.5+', ['Bristol'], 'free'],
        [77, 'Westminster University', 'Westminster', '🇬🇧 UK', "Master's", 'fully', 'low', 'varies', 'tuition + accommodation', ['CS/IT'], 'IELTS 6.5+', ['Westminster'], 'free'],
        [78, 'Gates Cambridge', 'Cambridge', '🇬🇧 UK', "Master's", 'fully', 'low', 'Dec 3, 2026', '£21,000/yr', ['highly competitive'], 'required', ['Cambridge'], 'free'],
        [79, 'Rhodes Scholarship', 'Oxford', '🇬🇧 UK', "Master's", 'fully', 'low', 'Sep 2026', '£20,400', ['GPA 3.7+'], 'required', ['Oxford'], 'free'],
        [80, 'Marconi International (USA)', 'Marconi International', '🇺🇸 USA', "Master's", 'partial', 'low', 'varies', '20–60% tuition', ['online/on-campus','regional'], 'required', ['Marconi'], 'paid'],
        [81, 'Master Mind (Belgium)', 'Flemish Universities', '🇧🇪 Belgium', "Master's", 'fully', 'low', 'Apr 28, 2027', '€10,000 + tuition waiver', ['GPA 3.5 border','30 awards'], 'required', ['KU Leuven','Antwerp','Ghent','VUB'], 'free'],
        [82, 'Romanian MFA Scholarship', 'Romanian Universities', '🇷🇴 Romania', "Master's", 'partial', 'low', 'Mar 2027', '€75/mo + tuition', ['Romanian language','low stipend'], 'required', ['Bucharest','Babes-Bolyai','Timișoara'], 'free'],
        [83, 'Swedish Institute Scholarships for Global Professionals (SISGP)', 'Swedish Universities', '🇸🇪 Sweden', "Master's", 'fully', 'high', 'Feb 15, 2026', 'Full tuition + SEK 12,000/mo + travel grant', ['SISGP','stipend','tuition','No IELTS'], 'TOEFL 80-100 / IELTS 5.5-6.5', ['KTH','Lund','Uppsala','Chalmers','Stockholm'], 'free'],
        [84, 'The Visby Programme Scholarships', 'Swedish Universities', '🇸🇪 Sweden', "Master's", 'fully', 'high', 'varies', 'Full tuition + monthly stipend', ['Visby','stipend','tuition','No IELTS'], 'TOEFL 80-100 / IELTS 5.5-6.5', ['Lund','Uppsala','KTH','Gothenburg'], 'free'],
        [85, 'Blekinge Institute of Technology Scholarship Programme', 'Blekinge Institute of Technology', '🇸🇪 Sweden', "Master's", 'partial', 'high', 'Jan 15, 2026', 'Tuition fee waiver', ['BTH','tuition waiver','CS/IT','No IELTS'], 'TOEFL 80-100 / IELTS 5.5-6.5', ['Blekinge Institute of Technology'], 'free'],
        [86, 'University of Borås Tuition Fee Waivers', 'University of Borås', '🇸🇪 Sweden', "Master's", 'partial', 'medium', 'varies', 'Full/partial tuition waiver', ['Borås','tuition waiver','No IELTS'], 'TOEFL 80-100 / IELTS 5.5-6.5', ['University of Borås'], 'free'],
        [87, 'Chalmers IPOET Scholarships', 'Chalmers University of Technology', '🇸🇪 Sweden', "Master's", 'partial', 'high', 'Jan 15, 2026', '75–100% tuition reduction', ['Chalmers','IPOET','Engineering','CS','No IELTS'], 'TOEFL 80-100 / IELTS 5.5-6.5', ['Chalmers University of Technology'], 'free'],
        [88, 'Halmstad University Scholarships', 'Halmstad University', '🇸🇪 Sweden', "Master's", 'partial', 'medium', 'varies', 'Up to 100% tuition waiver', ['Halmstad','tuition waiver','No IELTS'], 'TOEFL 80-100 / IELTS 5.5-6.5', ['Halmstad University'], 'free'],
        [89, 'Umeå University Scholarships', 'Umeå University', '🇸🇪 Sweden', "Master's", 'partial', 'medium', 'varies', 'Full/partial tuition coverage', ['Umeå','tuition waiver','CS','No IELTS'], 'TOEFL 80-100 / IELTS 5.5-6.5', ['Umeå University'], 'free'],
        [90, 'Stockholm University Scholarship Scheme', 'Stockholm University', '🇸🇪 Sweden', "Master's", 'partial', 'medium', 'varies', 'Tuition waivers', ['Stockholm','tuition waiver','CS/IT','No IELTS'], 'TOEFL 80-100 / IELTS 5.5-6.5', ['Stockholm University'], 'free'],
        [91, 'Skövde University Scholarships', 'University of Skövde', '🇸🇪 Sweden', "Master's", 'partial', 'medium', 'varies', 'Partial tuition waivers', ['Skövde','tuition waiver','No IELTS'], 'TOEFL 80-100 / IELTS 5.5-6.5', ['University of Skövde'], 'free'],
        [92, 'Mid-Sweden University Tuition Fee Scholarship', 'Mid Sweden University', '🇸🇪 Sweden', "Master's", 'partial', 'medium', 'varies', 'Partial to full tuition reduction', ['Mid Sweden','tuition waiver','No IELTS'], 'TOEFL 80-100 / IELTS 5.5-6.5', ['Mid Sweden University'], 'free'],
        [93, 'UIC Barcelona Scholarships', 'Universitat Internacional de Catalunya', '🇪🇸 Spain', "Master's", 'partial', 'medium', 'varies', 'Tuition fee waiver', ['UIC Barcelona','tuition waiver','No IELTS'], 'not required', ['UIC Barcelona'], 'free'],
        [95, 'University of Granada Scholarships', 'University of Granada', '🇪🇸 Spain', "Master's", 'fully', 'high', 'varies', 'Tuition support + travel/living allowance', ['Granada','stipend','living support','No IELTS'], 'not required', ['University of Granada'], 'free'],
        [96, 'Guillaume Dupaix International Master\'s Scholarship', 'University of Luxembourg', '🇱🇺 Luxembourg', "Master's", 'fully', 'high', 'varies', 'Full tuition + living stipend', ['Guillaume Dupaix','CS/IT/Engineering','No IELTS'], 'not required', ['University of Luxembourg'], 'free'],
        [97, 'Reach Oxford Scholarship', 'University of Oxford', '🇬🇧 UK', "Master's", 'fully', 'high', 'varies', 'Full tuition + living expenses', ['Reach Oxford','Oxford','No IELTS'], 'not required', ['University of Oxford'], 'free'],
        [99, 'Belt and Road Scholarship (Hong Kong)', 'Hong Kong Universities', '🇭🇰 Hong Kong', 'Master\'s', 'fully', 'high', 'varies', 'Full tuition + living allowance', ['Belt and Road','CS/IT','MOI'], 'MOI accepted', ['HKUST','HKU','CUHK','CityU','PolyU'], 'free'],
        [100, 'HKSAR Government Scholarship', 'Hong Kong Universities', '🇭🇰 Hong Kong', 'Master\'s', 'fully', 'medium', 'varies', 'Full or partial tuition & living funding', ['HKSAR','Government','MOI'], 'MOI accepted', ['HKU','CUHK','HKUST'], 'free'],
        [101, 'Self-Financing Post-Secondary Education Fund Scholarship', 'Hong Kong Universities', '🇭🇰 Hong Kong', 'Master\'s', 'partial', 'medium', 'varies', 'Outstanding Performance Scholarship grant', ['Post-Secondary Fund','MOI'], 'MOI accepted', ['HKBU','Lingnan','PolyU'], 'free'],
        [102, 'DAAD Helmut Schmidt Scholarship Program', 'German Universities', '🇩🇪 Germany', "Master's", 'fully', 'high', 'varies', 'Medical insurance + tuition + monthly allowance + travel', ['DAAD','Helmut Schmidt','No IELTS'], 'MOI accepted', ['Bonn','Passau','Duisburg-Essen'], 'free'],
        [103, 'IU International University Scholarship', 'IU International University of Applied Sciences', '🇩🇪 Germany', "Master's", 'partial', 'medium', 'rolling', '80% tuition waiver', ['IU Germany','80% tuition','CS/IT','No IELTS'], 'not required', ['IU International University'], 'free'],
        [104, 'Kadir Has University Scholarships', 'Kadir Has University', '🇹🇷 Turkey', "Master's", 'fully', 'high', 'varies', 'Full tuition waiver + stipend', ['Kadir Has','tuition waiver','No IELTS'], 'not required', ['Kadir Has University'], 'free'],
        [105, 'Koç University Scholarships', 'Koç University', '🇹🇷 Turkey', "Master's", 'fully', 'high', 'varies', 'Full tuition + monthly allowance + housing', ['Koç','CS/IT','No IELTS'], 'not required', ['Koç University'], 'free'],
        [106, 'ITU AI DeepMind Scholarship', 'Istanbul Technical University', '🇹🇷 Turkey', "Master's", 'fully', 'high', 'varies', 'Full tuition + monthly stipend + research grant', ['DeepMind','AI','Computer Science','No IELTS'], 'not required', ['Istanbul Technical University'], 'free'],
        [107, 'Success Scholarship Program (Turkey)', 'Turkish Universities', '🇹🇷 Turkey', "Master's", 'fully', 'medium', 'varies', 'Monthly stipend support', ['Success Scholarship','Turkey','No IELTS'], 'not required', ['Turkish Universities'], 'free'],
        [108, 'Kyoto University Scholarships', 'Kyoto University', '🇯🇵 Japan', "Master's", 'fully', 'high', 'varies', 'Full tuition + monthly stipend + return airfare', ['Kyoto','top uni','No IELTS'], 'MOI accepted', ['Kyoto University'], 'free'],
        [109, 'Toyohashi University of Technology Scholarship', 'Toyohashi University of Technology', '🇯🇵 Japan', "Master's", 'fully', 'high', 'varies', 'Full tuition + monthly stipend', ['Toyohashi','TUT','Engineering/CS','No IELTS'], 'MOI accepted', ['Toyohashi University of Technology'], 'free'],
        [110, 'University of Tokyo International Scholarships', 'University of Tokyo', '🇯🇵 Japan', "Master's", 'fully', 'high', 'varies', 'Full tuition + monthly stipend', ['UTokyo','top uni','CS/IT','No IELTS'], 'MOI accepted', ['University of Tokyo'], 'free'],
        [111, 'Yokohama National University (YNU) Scholarships', 'Yokohama National University', '🇯🇵 Japan', "Master's", 'fully', 'high', 'varies', 'Full tuition waiver + monthly allowance', ['YNU','Yokohama','No IELTS'], 'MOI accepted', ['Yokohama National University'], 'free'],
        [112, 'Manaaki New Zealand Government Scholarship', 'New Zealand Universities', '🇳🇿 New Zealand', "Master's", 'fully', 'high', 'varies', 'Full tuition + living allowance + airfare + establishment', ['Manaaki','NZ Govt','MOI'], 'MOI accepted', ['Auckland','Otago','Canterbury','Massey','Waikato'], 'free'],
        [113, 'University of Waikato International Excellence Scholarship', 'University of Waikato', '🇳🇿 New Zealand', "Master's", 'fully', 'high', 'varies', 'Full tuition & stipend funding', ['Waikato','Excellence','No IELTS'], 'MOI accepted', ['University of Waikato'], 'free'],
        [114, 'University of Auckland Excellence Scholarship', 'University of Auckland', '🇳🇿 New Zealand', "Master's", 'fully', 'high', 'varies', 'Up to NZ$10,000–$20,000 tuition coverage', ['Auckland','CS/IT','MOI'], 'MOI accepted', ['University of Auckland'], 'free'],
        [115, 'Lincoln University Pakistan Country Scholarship', 'Lincoln University', '🇳🇿 New Zealand', "Master's", 'partial', 'medium', 'varies', 'Tuition waiver grant for Pakistan students', ['Lincoln','Pakistan','MOI'], 'MOI accepted', ['Lincoln University'], 'free'],
        [116, 'Victoria University of Wellington Excellence Scholarship', 'Victoria University of Wellington', '🇳🇿 New Zealand', "Master's", 'partial', 'medium', 'varies', 'Up to NZ$15,000 tuition waiver', ['Wellington','CS/IT','MOI'], 'MOI accepted', ['Victoria University of Wellington'], 'free'],
        [117, 'Austrian Development Cooperation Scholarship', 'Austrian Universities', '🇦🇹 Austria', "Master's", 'fully', 'high', 'varies', 'Full tuition + monthly living allowance', ['ADC','Austria','CS/Tech','No IELTS'], 'not required', ['Vienna','TU Graz','Innsbruck'], 'free'],
        [118, 'IST Austria Internships & Master Scholarships', 'Institute of Science and Technology Austria', '🇦🇹 Austria', "Master's", 'fully', 'high', 'varies', 'Full tuition waiver + monthly stipend', ['IST Austria','CS','AI','Data Science','No IELTS'], 'not required', ['IST Austria'], 'free'],
        [119, 'Łukasiewicz Scholarship (NAWA)', 'Polish Universities', '🇵🇱 Poland', "Master's", 'fully', 'high', 'varies', 'Full tuition + monthly stipend + insurance', ['Łukasiewicz','NAWA','IT/Engineering','MOI'], 'MOI accepted', ['WUT','AGH','Warsaw','Wrocław'], 'free'],
        [120, 'Maria Curie-Skłodowska University Scholarship', 'Maria Curie-Skłodowska University', '🇵🇱 Poland', "Master's", 'fully', 'high', 'varies', 'Full tuition waiver & living support', ['UMCS','Curie-Skłodowska','MOI'], 'MOI accepted', ['Maria Curie-Skłodowska University'], 'free'],
        [121, 'Poland: My First Choice (NAWA)', 'Polish Universities', '🇵🇱 Poland', "Master's", 'fully', 'high', 'varies', 'Full tuition waiver + PLN 2,000/mo stipend', ['My First Choice','NAWA','CS/IT','MOI'], 'MOI accepted', ['University of Warsaw','Jagiellonian','AGH'], 'free'],
        [122, 'University of Silesia Scholarships', 'University of Silesia', '🇵🇱 Poland', "Master's", 'fully', 'medium', 'varies', 'Tuition waivers + living stipends', ['Silesia','tuition waiver','MOI'], 'MOI accepted', ['University of Silesia'], 'free'],
        [123, 'University of Adelaide Research Scholarship', 'University of Adelaide', '🇦🇺 Australia', "Master's", 'fully', 'high', 'varies', 'Full tuition + AUD $32,500/yr stipend + health', ['Adelaide','RTP','CS/IT','MOI'], 'MOI accepted', ['University of Adelaide'], 'free'],
        [124, 'University of Sydney International Scholarship (USydIS)', 'University of Sydney', '🇦🇺 Australia', "Master's", 'fully', 'high', 'varies', 'Full tuition + AUD $37,207/yr stipend + OSHC', ['USydIS','Sydney','CS/IT','MOI'], 'MOI accepted', ['University of Sydney'], 'free'],
        [125, 'University of Melbourne Graduate Research Scholarship', 'University of Melbourne', '🇦🇺 Australia', "Master's", 'fully', 'high', 'varies', 'Full tuition + AUD $37,000/yr stipend (600 awards)', ['Melbourne','RTP','CS/IT','MOI'], 'MOI accepted', ['University of Melbourne'], 'free'],
        [126, 'Monash University International Research Scholarship', 'Monash University', '🇦🇺 Australia', "Master's", 'fully', 'high', 'varies', 'Full tuition + AUD $33,000/yr stipend (800 awards)', ['Monash','RTP','CS/IT','MOI'], 'MOI accepted', ['Monash University'], 'free'],
        [127, 'Australian National University (ANU) Research Scholarship', 'Australian National University', '🇦🇺 Australia', "Master's", 'fully', 'high', 'varies', 'Full tuition + AUD $34,000/yr stipend + OSHC', ['ANU','Canberra','CS/IT','MOI'], 'MOI accepted', ['Australian National University'], 'free'],
        [128, 'University of Helsinki International Master\'s Scholarship', 'University of Helsinki', '🇫🇮 Finland', "Master's", 'fully', 'high', 'Jan 15, 2027', '100% tuition waiver + €5,000 relocation grant', ['Helsinki','CS/IT','MOI'], 'MOI accepted', ['University of Helsinki'], 'free'],
        [129, 'LUT University International Master\'s Scholarship', 'LUT University', '🇫🇮 Finland', "Master's", 'fully', 'high', 'Jan 22, 2027', '100% tuition waiver + living cost grant', ['LUT','Technology','CS/Engineering','MOI'], 'MOI accepted', ['LUT University'], 'free'],
        [130, 'ANSO Scholarship for International Students', 'CAS / USTC / UCAS', '🇨🇳 China', "Master's", 'fully', 'high', 'Mar 31, 2027', 'Tuition waiver + RMB 3,000/mo stipend + health', ['ANSO','CAS','USTC','CS/AI','MOI'], 'MOI accepted', ['USTC','UCAS'], 'free'],
        [131, 'Jiangsu Provincial Government Scholarship', 'Jiangsu Universities', '🇨🇳 China', "Master's", 'fully', 'high', 'varies', 'Full tuition + living allowance', ['Jiangsu','CS/IT','MOI'], 'MOI accepted', ['Nanjing University','SEU','NJUST'], 'free'],
        [132, 'Tsinghua University International Scholarship', 'Tsinghua University', '🇨🇳 China', "Master's", 'fully', 'high', 'varies', 'Full tuition + monthly stipend + housing', ['Tsinghua','top uni','CS/AI','MOI'], 'MOI accepted', ['Tsinghua University'], 'free'],
        [133, 'Zhengzhou University President Scholarship', 'Zhengzhou University', '🇨🇳 China', "Master's", 'fully', 'high', 'varies', 'Full tuition waiver + living stipend', ['Zhengzhou','President','CS/IT','MOI'], 'MOI accepted', ['Zhengzhou University'], 'free'],
        [134, 'French Government Scholarships (BGF / France Excellence)', 'French Universities', '🇫🇷 France', "Master's", 'fully', 'high', 'varies', 'Full tuition waiver + monthly stipend + health', ['France Excellence','BGF','CS/IT','MOI'], 'MOI accepted', ['Sorbonne','Paris-Saclay','EPITA','Grenoble INP'], 'free'],
        [135, 'EPITA Graduate School of Computer Science Scholarship', 'EPITA Paris', '🇫🇷 France', "Master's", 'fully', 'high', 'varies', 'Partial to full tuition waiver', ['EPITA','Computer Science','Software Eng','No IELTS'], 'MOI accepted', ['EPITA Paris'], 'free'],
        [136, 'University of Pavia International Scholarships', 'University of Pavia', '🇮🇹 Italy', "Master's", 'fully', 'high', 'varies', 'Free accommodation + meals + €8,000 stipend', ['Pavia','EDiSU','CS/Engineering','MOI'], 'MOI accepted', ['University of Pavia'], 'free'],
        [137, 'University of Pisa Excellence Scholarships', 'University of Pisa', '🇮🇹 Italy', "Master's", 'fully', 'high', 'varies', 'Tuition waiver + €8,000/yr grant', ['Pisa','Excellence','CS/IT','MOI'], 'MOI accepted', ['University of Pisa'], 'free'],
        [138, 'University of Saskatchewan Dean\'s Master\'s Scholarship', 'University of Saskatchewan', '🇨🇦 Canada', "Master's", 'fully', 'high', 'varies', 'CAD $18,000/yr stipend + tuition waiver', ['Saskatchewan','USask','CS/IT','MOI'], 'MOI accepted', ['University of Saskatchewan'], 'free'],
        [139, 'University of Ottawa International Master\'s Scholarship', 'University of Ottawa', '🇨🇦 Canada', "Master's", 'fully', 'high', 'varies', 'Differential tuition waiver + CAD $12,000/yr stipend', ['uOttawa','Ottawa','CS/IT','MOI'], 'MOI accepted', ['University of Ottawa'], 'free'],
        [140, 'US University Graduate Assistantships (GTA/GRA)', 'US Universities', '🇺🇸 USA', "Master's", 'fully', 'high', 'varies', '100% tuition waiver + $20,000–$35,000/yr stipend', ['GTA/GRA','Assistantship','CS/IT','GRE Waiver','No IELTS'], 'Duolingo / TOEFL / MOI', ['Cincinnati','George Mason','FIU','Stevens','WSU'], 'free'],
        [141, 'University of Cincinnati Graduate Assistantships', 'University of Cincinnati', '🇺🇸 USA', "Master's", 'fully', 'high', 'varies', 'Full tuition waiver + monthly stipend', ['Cincinnati','GTA/GRA','CS/IT','GRE Waiver'], 'Duolingo / TOEFL', ['University of Cincinnati'], 'free'],
        [142, 'George Mason University Graduate Assistantships', 'George Mason University', '🇺🇸 USA', "Master's", 'fully', 'high', 'varies', 'Tuition grant + $22,000/yr stipend', ['George Mason','GMU','CS/SE','GRE Waiver'], 'Duolingo / TOEFL / MOI', ['George Mason University'], 'free'],
        [143, 'SIIT Graduate Scholarship (Thammasat University)', 'Sirindhorn International Institute of Technology (SIIT)', '🇹🇭 Thailand', "Master's", 'fully', 'high', 'varies', 'Full tuition + 10,000 THB/mo living allowance + airfare', ['SIIT','IT','Computer Engineering','Digital Eng','No IELTS'], 'not required', ['SIIT Thammasat University'], 'free'],
        [144, 'Skoltech University Graduate Scholarship', 'Skolkovo Institute of Science and Technology', '🇷🇺 Russia', "Master's", 'fully', 'high', 'varies', '100% tuition waiver + 40,000–55,000 RUB/mo stipend + health', ['Skoltech','Data Science','AI','Robotics','No IELTS'], 'not required', ['Skoltech'], 'free'],
        [145, 'Siberian Federal University International Scholarship', 'Siberian Federal University', '🇷🇺 Russia', "Master's", 'fully', 'medium', 'varies', 'Full tuition waiver + monthly allowance + dorm', ['SibFU','CS/IT','Engineering','No IELTS'], 'not required', ['Siberian Federal University'], 'free'],
        [146, 'ETH Zurich Excellence Scholarship (ESOP)', 'ETH Zurich', '🇨🇭 Switzerland', "Master's", 'fully', 'high', 'Dec 15, 2026', 'CHF 12,000/semester stipend + tuition waiver', ['ETH Zurich','ESOP','CS/IT/Engineering','Top Uni'], 'MOI accepted', ['ETH Zurich'], 'free'],
        [147, 'EPFL Excellence Fellowships', 'EPFL Lausanne', '🇨🇭 Switzerland', "Master's", 'fully', 'high', 'Apr 15, 2027', 'CHF 10,000/semester stipend + tuition waiver', ['EPFL','Lausanne','CS/IT/Engineering','Top Uni'], 'MOI accepted', ['EPFL Lausanne'], 'free'],
        [148, 'University of Lausanne (UNIL) Master\'s Grants', 'University of Lausanne', '🇨🇭 Switzerland', "Master's", 'fully', 'medium', 'Nov 1, 2026', 'CHF 1,600/month stipend + tuition waiver', ['UNIL','Lausanne','CS/IT'], 'MOI accepted', ['University of Lausanne'], 'free'],
        [149, 'CERN Technical & Master Student Program', 'CERN (European Organization for Nuclear Research)', '🇨🇭 Switzerland', "Master's", 'fully', 'high', 'varies', 'CHF 3,407/month stipend + travel + health', ['CERN','Software Eng','CS/IT','Engineering'], 'MOI accepted', ['CERN'], 'free'],
        [150, 'Aarhus University Danish State Scholarship', 'Aarhus University', '🇩🇰 Denmark', "Master's", 'fully', 'high', 'Jan 15, 2027', 'Full tuition waiver + DKK 6,500/mo stipend', ['Aarhus','CS/IT','Data Science','Automatic'], 'required', ['Aarhus University'], 'free'],
        [151, 'University of Copenhagen Danish State Scholarship', 'University of Copenhagen', '🇩🇰 Denmark', "Master's", 'fully', 'high', 'Sep 1, 2026', 'Full or partial tuition waiver + living stipend', ['UCPH','Copenhagen','CS/IT','Automatic'], 'required', ['University of Copenhagen'], 'free'],
        [152, 'NTNU International Master\'s Fellowships', 'Norwegian University of Science and Technology (NTNU)', '🇳🇴 Norway', "Master's", 'fully', 'high', 'Dec 1, 2026', '100% tuition-free + NOK 126,357/yr living support grant', ['NTNU','Norway Tech','CS/IT/AI','MOI'], 'MOI accepted', ['NTNU'], 'free'],
        [153, 'University of Oslo International Master\'s Fellowships', 'University of Oslo', '🇳🇴 Norway', "Master's", 'fully', 'high', 'Dec 1, 2026', '100% tuition-free + monthly living allowance', ['UiO','Oslo','CS/IT','MOI'], 'MOI accepted', ['University of Oslo'], 'free'],
        [154, 'BI Norwegian Business School Master\'s Scholarship', 'BI Norwegian Business School', '🇳🇴 Norway', "Master's", 'fully', 'medium', 'Mar 1, 2027', 'Full tuition waiver + stipend for living expenses', ['BI Norway','Business/IT','MOI'], 'MOI accepted', ['BI Norwegian Business School'], 'free'],
        [155, 'King Fahd University Scholarship (KFUPM)', 'King Fahd University of Petroleum & Minerals', '🇸🇦 Saudi Arabia', "Master's", 'fully', 'high', 'varies', 'Full tuition waiver + monthly stipend + free housing + airfare', ['KFUPM','CS/AI/DS','Software Eng','Cybersecurity'], 'required', ['King Fahd University (KFUPM)'], 'free'],
        [156, 'National Chiao Tung University (NYCU) Scholarship', 'National Yang Ming Chiao Tung University (NYCU)', '🇹🇼 Taiwan', "Master's", 'fully', 'high', 'varies', 'Full tuition waiver + USD $733/mo (NTD 22,000/mo) stipend', ['NCTU','NYCU','CS/AI/DS','Tech Top Uni'], 'required', ['NYCU / NCTU'], 'free'],
        [157, 'University College Cork (UCC) Postgraduate Excellence Scholarship', 'University College Cork', '🇮🇪 Ireland', "Master's", 'fully', 'high', 'varies', 'Full tuition waiver + €16,000/yr stipend', ['UCC','Cork','CS/AI/DS','Ireland'], 'required', ['University College Cork'], 'free'],
        [158, 'Dublin City University (DCU) Postgraduate Master\'s Scholarship', 'Dublin City University', '🇮🇪 Ireland', "Master's", 'fully', 'high', 'varies', 'Full tuition waiver + €18,000/yr stipend', ['DCU','Dublin','CS/IT','Computing'], 'required', ['Dublin City University'], 'free'],
        [159, 'Hokkaido University MEXT Scholarship', 'Hokkaido University', '🇯🇵 Japan', "Master's", 'fully', 'high', 'Nov–Dec 2026', 'Full tuition + ¥144,000/mo stipend + roundtrip airfare', ['Hokkaido','MEXT','CS/IT/Engineering','Top Uni'], 'required', ['Hokkaido University'], 'free'],
        [160, 'ENDEAVOUR II Scholarship Scheme', 'Malta Government / Public Institutions', '🇲🇹 Malta', "Master's", 'fully', 'high', 'varies', 'Full tuition fee + bench fees + research support', ['ENDEAVOUR II','Malta','CS/IT','MOI'], 'MOI accepted', ['University of Malta','MCAST'], 'free'],
        [161, 'University of Malta Postgraduate Scholarships', 'University of Malta', '🇲🇹 Malta', "Master's", 'partial', 'medium', 'varies', '100% tuition fee waiver', ['University of Malta','tuition waiver','CS/IT','MOI'], 'MOI accepted', ['University of Malta'], 'free'],
        [162, 'Qatar University Graduate Scholarship', 'Qatar University', '🇶🇦 Qatar', "Master's", 'fully', 'high', 'varies', 'Full tuition + QAR 2,000/mo stipend + housing + airfare', ['Qatar Uni','CS/AI/IT','Engineering'], 'not required', ['Qatar University'], 'free'],
        [163, 'Honjo International Scholarship Foundation', 'Japanese Universities', '🇯🇵 Japan', "Master's", 'fully', 'high', 'Oct 31, 2026', 'JPY 180,000–210,000/mo living stipend', ['Honjo','Japan','CS/IT','Living Stipend'], 'MOI accepted', ['University of Tokyo','Kyoto','Titech'], 'free'],
        [164, 'Taiwan International Graduate Program (TIGP)', 'Academia Sinica / Taiwanese Unis', '🇹🇼 Taiwan', "Master's", 'fully', 'high', 'Mar 31, 2027', 'Full tuition + NTD 34,000/mo stipend + health', ['TIGP','Academia Sinica','CS/AI/Data Science'], 'required', ['Academia Sinica','NTU','NCTU'], 'free'],
        [165, 'Khalifa University Graduate Scholarship', 'Khalifa University', '🇦🇪 UAE', "Master's", 'fully', 'high', 'varies', 'Full tuition waiver + AED 8,000/mo stipend + health + airfare', ['Khalifa Uni','UAE','CS/AI/Robotics','Top Uni'], 'required', ['Khalifa University'], 'free'],
        [166, 'Asian Institute of Technology (AIT) Scholarships', 'Asian Institute of Technology (AIT)', '🇹🇭 Thailand', "Master's", 'fully', 'high', 'varies', '100% tuition coverage + monthly living stipend', ['AIT','Thailand','CS/IT/SE/AI','International'], 'MOI accepted', ['Asian Institute of Technology'], 'free'],
        [167, 'Université de Montréal International Scholarship', 'Université de Montréal', '🇨🇦 Canada', "Master's", 'fully', 'high', 'varies', 'Tuition waiver + MILA AI research stipend', ['UdeM','Montreal','MILA','AI','CS'], 'required', ['Université de Montréal'], 'free'],
        [168, 'Queen\'s University Graduate Excellence Scholarship', 'Queen\'s University', '🇨🇦 Canada', "Master's", 'fully', 'high', 'varies', 'Full tuition waiver + CAD $18,000/yr stipend', ['Queen\'s','Ontario','CS/IT'], 'required', ['Queen\'s University'], 'free'],
        [169, 'McGill University McCall MacBain Scholarship', 'McGill University', '🇨🇦 Canada', "Master's", 'fully', 'high', 'Aug 24, 2026', 'Full tuition + CAD $2,000/mo stipend + relocation grant', ['McCall MacBain','McGill','CS/IT','Leadership'], 'required', ['McGill University'], 'free'],
        [170, 'GIST Graduate Scholarship', 'Gwangju Institute of Science and Technology (GIST)', '🇰🇷 South Korea', "Master's", 'fully', 'high', 'varies', 'Full tuition waiver + KRW 140,000/mo stipend + meal grant', ['GIST','STEM','EECS/CS','Tech Uni'], 'required', ['GIST'], 'free'],
        [171, 'Jiangsu University Presidential Scholarship', 'Jiangsu University', '🇨🇳 China', "Master's", 'fully', 'high', 'varies', 'Full tuition waiver + RMB 20,000/yr stipend', ['Jiangsu Uni','Presidential','CS/IT','MOI'], 'MOI accepted', ['Jiangsu University'], 'free'],
        [172, 'University of Geneva Excellence Master Fellowships', 'University of Geneva', '🇨🇭 Switzerland', "Master's", 'fully', 'high', 'Mar 15, 2027', 'CHF 10,000–15,000/yr fellowship grant + tuition waiver', ['Geneva','Excellence','CS/IT/Data Science','MOI'], 'MOI accepted', ['University of Geneva'], 'free'],
        [173, 'NUS Graduate Master\'s Scholarship', 'National University of Singapore (NUS)', '🇸🇬 Singapore', "Master's", 'fully', 'high', 'varies', 'Full tuition waiver + SGD $2,200/mo stipend', ['NUS','Singapore Top Uni','CS/AI/IT'], 'MOI accepted', ['National University of Singapore'], 'free'],
        [174, 'NTU Nanyang Master\'s Scholarship', 'Nanyang Technological University (NTU)', '🇸🇬 Singapore', "Master's", 'fully', 'high', 'varies', 'Full tuition waiver + SGD $2,000/mo stipend', ['NTU','Nanyang','CS/IT/AI','Tech Top Uni'], 'MOI accepted', ['Nanyang Technological University'], 'free'],
        [175, 'SUTD Graduate Master\'s Fellowship', 'Singapore University of Technology and Design', '🇸🇬 Singapore', "Master's", 'fully', 'high', 'varies', 'Full tuition waiver + SGD $1,800/mo stipend', ['SUTD','Singapore','Technology & Design','CS/IT'], 'MOI accepted', ['SUTD'], 'free'],
        [176, 'Osaka University MEXT Graduate Scholarship', 'Osaka University', '🇯🇵 Japan', "Master's", 'fully', 'high', 'varies', 'Full tuition + ¥144,000/mo stipend + roundtrip airfare', ['Osaka Uni','MEXT','CS/IT/Information Sci'], 'required', ['Osaka University'], 'free'],
        [177, 'Japanese Government (MEXT) Young Leaders Program (YLP)', 'Japanese Partner Universities', '🇯🇵 Japan', "Master's", 'fully', 'medium', 'Oct–Nov 2026', 'Full tuition + ¥242,000/mo stipend + roundtrip airfare', ['YLP','MEXT','Governance','Leadership'], 'required', ['GRIPS','Hitotsubashi'], 'free'],
        [178, 'Commonwealth Scholarship (New Zealand)', 'University of Canterbury / NZ Universities', '🇳🇿 New Zealand', "Master's", 'fully', 'high', 'varies', 'Full tuition waiver + NZD $25,000/yr stipend', ['Commonwealth','New Zealand','Canterbury','CS/IT'], 'MOI accepted', ['University of Canterbury'], 'free'],
        [179, 'Sir Neil Isaac Scholarship', 'University of Canterbury', '🇳🇿 New Zealand', "Master's", 'fully', 'high', 'varies', 'NZD $20,000/yr living & research grant + tuition support', ['Sir Neil Isaac','Canterbury','CS/IT','New Zealand'], 'MOI accepted', ['University of Canterbury'], 'free'],
        [180, 'Ministry of Economy (MEAT) Romania Scholarships', 'Romanian Public Universities', '🇷🇴 Romania', "Master's", 'fully', 'high', 'varies', 'Full tuition waiver + monthly living stipend + free dorm', ['MEAT','Romania','CS/IT','MOI'], 'MOI accepted', ['Polytechnic University of Bucharest','Babes-Bolyai'], 'free'],
        [181, 'University of Bucharest Master\'s Scholarship', 'University of Bucharest', '🇷🇴 Romania', "Master's", 'fully', 'high', 'varies', 'Full tuition waiver + monthly living stipend', ['Bucharest','Romania','CS/IT','MOI'], 'MOI accepted', ['University of Bucharest'], 'free'],
        [182, 'Transilvania Academica Scholarship', 'Transilvania University of Brașov', '🇷🇴 Romania', "Master's", 'fully', 'high', 'Apr 25, 2027', 'Full tuition waiver + 800 RON/mo stipend + free housing', ['Transilvania','Brasov','CS/IT/Engineering','MOI'], 'MOI accepted', ['Transilvania University of Brasov'], 'free'],
        [183, 'University of Porto Merit Scholarships', 'University of Porto', '🇵🇹 Portugal', "Master's", 'fully', 'high', 'varies', '100% tuition waiver + merit grant', ['Porto','Portugal','CS/Engineering','MOI'], 'MOI accepted', ['University of Porto'], 'free'],
        [184, 'Finnish Government Scholarship Pool (EDUFI)', 'Finnish Universities', '🇫🇮 Finland', "Master's", 'fully', 'high', 'varies', '€1,500/month stipend + travel allowance', ['EDUFI','Finland Pool','CS/IT','MOI'], 'MOI accepted', ['University of Helsinki','Aalto University','Tampere'], 'free'],
        [185, 'Erasmus University Rotterdam Excellence Scholarship', 'Erasmus University Rotterdam', '🇳🇱 Netherlands', "Master's", 'fully', 'high', 'Feb 1, 2027', 'Tuition grant + living support grant', ['Erasmus Uni','Rotterdam','CS/IT','Netherlands'], 'MOI accepted', ['Erasmus University Rotterdam'], 'free'],
        [186, 'University of Amsterdam (UvA) Merit Scholarship', 'University of Amsterdam', '🇳🇱 Netherlands', "Master's", 'fully', 'high', 'Jan 15, 2027', 'Full tuition waiver + €10,500 living stipend', ['UvA','Amsterdam','CS/AI/Data Science','Top Uni'], 'MOI accepted', ['University of Amsterdam'], 'free'],
        [187, 'Ghent University Master Grants', 'Ghent University', '🇧🇪 Belgium', "Master's", 'fully', 'high', 'varies', 'Full tuition waiver + €1,000/mo stipend + insurance', ['Ghent','Belgium','CS/IT/Engineering','MOI'], 'MOI accepted', ['Ghent University'], 'free'],
        [188, 'CIUF-CUD Master\'s Scholarships', 'French Community Universities in Belgium', '🇧🇪 Belgium', "Master's", 'fully', 'high', 'varies', 'Full tuition waiver + housing + monthly stipend + insurance', ['CIUF-CUD','Belgium','CS/IT','MOI'], 'MOI accepted', ['University of Liège','ULB','UCLouvain'], 'free'],
        [189, 'University of Liège International Scholarships', 'University of Liège', '🇧🇪 Belgium', "Master's", 'fully', 'high', 'varies', 'Full tuition waiver + monthly stipend', ['Liège','Belgium','CS/IT/Engineering','MOI'], 'MOI accepted', ['University of Liège'], 'free'],
        [190, 'Roskilde University (RUC) Danish State Scholarship', 'Roskilde University', '🇩🇰 Denmark', "Master's", 'fully', 'high', 'Jan 15, 2027', 'Full tuition waiver + DKK 6,000/mo stipend', ['RUC','Roskilde','CS/IT','Automatic'], 'required', ['Roskilde University'], 'free'],
        [191, 'Aalborg University (AAU) Danish State Scholarship', 'Aalborg University', '🇩🇰 Denmark', "Master's", 'fully', 'high', 'Jan 15, 2027', 'Full tuition waiver + living grant support', ['AAU','Aalborg','CS/Engineering','Top Uni'], 'required', ['Aalborg University'], 'free'],
        [192, 'Irish Research Council (IRC) Postgraduate Scholarship', 'Irish Higher Education Institutions', '🇮🇪 Ireland', "Master's", 'fully', 'high', 'Oct 20, 2026', 'Full tuition coverage + €19,000/yr stipend + research expenses', ['IRC','Ireland','Postgraduate','CS/IT'], 'required', ['TCD','UCD','DCU','UCC','NUIG'], 'free'],
        [193, 'Karolinska Institutet Global Master\'s Scholarship', 'Karolinska Institutet', '🇸🇪 Sweden', "Master's", 'partial', 'high', 'Jan 15, 2027', 'Full or partial tuition fee waiver', ['Karolinska','Health Informatics','Data Science','Bioinformatics'], 'required', ['Karolinska Institutet'], 'free'],
        [194, 'Politecnico di Torino International Scholarships', 'Politecnico di Torino', '🇮🇹 Italy', "Master's", 'fully', 'high', 'varies', '100% tuition waiver + €8,000/yr stipend', ['PoliTo','Torino','CS/IT/Engineering','MOI'], 'MOI accepted', ['Politecnico di Torino'], 'free'],
        [195, 'ENS International Selection Scholarships', 'École Normale Supérieure (ENS PSL)', '🇫🇷 France', "Master's", 'fully', 'high', 'Dec 11, 2026', '€1,000/mo stipend + room on campus', ['ENS PSL','Paris','CS/Maths','Top Uni'], 'MOI accepted', ['ENS Paris'], 'free'],
        [196, 'Université Grenoble Alpes (UGA) Master\'s Scholarships', 'Université Grenoble Alpes', '🇫🇷 France', "Master's", 'fully', 'high', 'varies', '€8,000–€10,000/yr stipend + tuition waiver', ['Grenoble Alpes','UGA','Informatics','Cybersecurity','CS'], 'MOI accepted', ['Université Grenoble Alpes'], 'free'],
        [197, 'Université Paris Cité SMARTS-UP Scholarships', 'Université Paris Cité', '🇫🇷 France', "Master's", 'fully', 'high', 'Jan 16, 2027', '€10,000/yr living grant + tuition support', ['Paris Cité','SMARTS-UP','CS/AI/Data Science'], 'MOI accepted', ['Université Paris Cité'], 'free'],
        [198, 'East-West Center Graduate Degree Fellowship', 'University of Hawaii / EWC', '🇺🇸 USA', "Master's", 'fully', 'high', 'Dec 1, 2026', 'Full tuition waiver + housing + monthly stipend + airfare', ['EWC','Hawaii','USA','Graduate Fellowship'], 'Duolingo / TOEFL / MOI', ['University of Hawaii'], 'free'],
        [199, 'German Chancellor Fellowship for Tomorrow\'s Leaders', 'Alexander von Humboldt Foundation', '🇩🇪 Germany', "Master's", 'fully', 'high', 'Oct 15, 2026', '€2,170–€2,770/mo stipend + travel + family allowance', ['German Chancellor','Humboldt','Leadership','CS/Tech'], 'MOI accepted', ['Humboldt Foundation'], 'free'],
        [200, 'IU International University Scholarship', 'IU International University of Applied Sciences', '🇩🇪 Germany', "Master's", 'partial', 'medium', 'varies', 'Up to 75% tuition fee waiver', ['IU Germany','CS/IT/AI','Data Science','Online & On-Campus'], 'MOI accepted', ['IU International University'], 'free'],
        [201, 'Hof University Graduate Scholarship', 'Hof University of Applied Sciences', '🇩🇪 Germany', "Master's", 'fully', 'high', 'Oct 20, 2026', '€300–€850/mo living grant + study material allowance', ['Hof Uni','Bavaria','CS/Software Eng','MOI'], 'MOI accepted', ['Hof University'], 'free'],
        [202, 'National Taiwan University of Science and Technology (Taiwan Tech) Scholarship', 'National Taiwan University of Science and Technology (NTUST)', '🇹🇼 Taiwan', "Master's", 'fully', 'high', 'varies', 'Full tuition waiver + NTD 10,000/mo stipend', ['Taiwan Tech','NTUST','CS/AI/IT','Top Tech Uni'], 'required', ['Taiwan Tech (NTUST)'], 'free'],
        [203, 'National Central University (NCU) International Scholarship', 'National Central University', '🇹🇼 Taiwan', "Master's", 'fully', 'high', 'varies', 'Full tuition waiver + NTD 6,000–10,000/mo stipend', ['NCU','Taiwan','CS/IT/Data Science'], 'required', ['National Central University'], 'free'],
        [204, 'National Taipei University of Technology (Taipei Tech) Scholarship', 'National Taipei University of Technology', '🇹🇼 Taiwan', "Master's", 'fully', 'high', 'varies', 'Full tuition waiver + monthly living stipend', ['Taipei Tech','CS/IT','Robotics','AI'], 'required', ['Taipei Tech'], 'free'],
        [205, 'National Sun Yat-Sen University (NSYSU) International Scholarship', 'National Sun Yat-Sen University', '🇹🇼 Taiwan', "Master's", 'fully', 'high', 'varies', 'Full tuition waiver + monthly stipend', ['NSYSU','Taiwan','CS/Engineering','IT'], 'required', ['National Sun Yat-Sen University'], 'free'],
        [206, 'Kuwait Government MOHE Scholarships', 'Kuwait Public Universities', '🇰🇼 Kuwait', "Master's", 'fully', 'high', 'varies', 'Full tuition + monthly stipend + free housing + airfare', ['Kuwait MOHE','Kuwait','CS/IT','STEM'], 'MOI accepted', ['Kuwait University'], 'free'],
        [207, 'Kuwait University Graduate Scholarship', 'Kuwait University', '🇰🇼 Kuwait', "Master's", 'fully', 'high', 'varies', 'Full tuition waiver + monthly allowance + free accommodation', ['Kuwait Uni','CS/IT/Engineering','Kuwait'], 'MOI accepted', ['Kuwait University'], 'free'],
        [208, 'KFAS Science & Technology Graduate Scholarship', 'Kuwait Foundation for the Advancement of Sciences (KFAS)', '🇰🇼 Kuwait', "Master's", 'fully', 'high', 'varies', 'Full tuition coverage + monthly research stipend', ['KFAS','Science & Tech','CS/AI/IT','Kuwait'], 'MOI accepted', ['KFAS Partner Institutions'], 'free'],
        [209, 'State Scholarships Foundation (IKY Greece)', 'Greek Higher Education Institutions', '🇬🇷 Greece', "Master's", 'fully', 'high', 'varies', 'Full tuition + housing + monthly stipend + travel + health', ['IKY','Greece','CS/IT','MOI'], 'MOI accepted', ['NKUA','NTUA','Aristotle University'], 'free'],
        [210, 'Stavros Niarchos Foundation (SNF) Scholarships', 'Greek Partner Universities', '🇬🇷 Greece', "Master's", 'fully', 'high', 'varies', 'Full tuition waiver + monthly living stipend', ['SNF','Greece','CS/AI/IT','MOI'], 'MOI accepted', ['SNF Partner Universities'], 'free'],
        [211, 'University of Athens (NKUA) Master\'s Scholarships', 'National and Kapodistrian University of Athens', '🇬🇷 Greece', "Master's", 'fully', 'high', 'varies', 'Full tuition waiver + monthly living allowance', ['NKUA','Athens','CS/IT/Data Science','Greece'], 'MOI accepted', ['University of Athens'], 'free'],
        [212, 'Mente Argentina Graduate Scholarships', 'University of Buenos Aires & Partner Institutions', '🇦🇷 Argentina', "Master's", 'fully', 'high', 'varies', 'Tuition waiver + housing & living grant', ['Mente Argentina','Buenos Aires','CS/IT','Latin America'], 'MOI accepted', ['University of Buenos Aires'], 'free'],
        [213, 'Argentina Ministry of Education International Master\'s Scholarship', 'Argentine National Universities', '🇦🇷 Argentina', "Master's", 'fully', 'high', 'varies', 'Full tuition waiver + monthly living stipend', ['Ministry of Education','Argentina','CS/IT','Latin America'], 'MOI accepted', ['University of Buenos Aires','UTN'], 'free'],
        [214, 'Belarusian State University & Yanka Kupala Master\'s Scholarship', 'Belarusian State University / YKSUG', '🇧🇾 Belarus', "Master's", 'fully', 'high', 'varies', 'Full tuition waiver + monthly stipend + free housing', ['BSU','YKSUG','Belarus','CS/IT'], 'MOI accepted', ['Belarusian State University','Yanka Kupala State University'], 'free'],
        [215, 'University of Sheffield International Merit Scholarship', 'University of Sheffield', '🇬🇧 UK', "Master's", 'partial', 'high', 'May 17, 2027', '£10,000 tuition fee waiver', ['Sheffield','UK','CS/IT/Data Science','Merit'], 'required', ['University of Sheffield'], 'free'],
        [216, 'University of Glasgow International Leadership Scholarship', 'University of Glasgow', '🇬🇧 UK', "Master's", 'partial', 'high', 'varies', '£10,000 tuition fee waiver', ['Glasgow','UK','CS/IT/AI','Leadership'], 'required', ['University of Glasgow'], 'free'],
        [217, 'University of Sussex Chancellor\'s International Scholarship', 'University of Sussex', '🇬🇧 UK', "Master's", 'partial', 'high', 'May 1, 2027', '50% tuition fee waiver', ['Sussex','UK','CS/IT/Robotics','Chancellor'], 'required', ['University of Sussex'], 'free'],
        [218, 'University of Groningen Eric Bleumink Fund', 'University of Groningen', '🇳🇱 Netherlands', "Master's", 'fully', 'high', 'Dec 1, 2026', 'Full tuition waiver + €1,000/mo stipend + travel + health', ['Groningen','Netherlands','Eric Bleumink','CS/IT'], 'MOI accepted', ['University of Groningen'], 'free'],
        [219, 'iFlexion Computer Science Scholarship', 'iFlexion / Partner Universities', '🌍 Global', "Master's", 'partial', 'high', 'Dec 31, 2026', '$1,000–$2,000 merit award for CS/IT students', ['iFlexion','CS/IT','Software Engineering','Global'], 'MOI accepted', ['Global Universities'], 'free'],
        [220, 'University of Birmingham CS Master\'s Scholarships', 'University of Birmingham', '🇬🇧 UK', "Master's", 'partial', 'high', 'varies', '£3,000–£5,000 tuition fee waiver', ['Birmingham','UK','School of CS','CS/AI/Data Science'], 'required', ['University of Birmingham'], 'free'],
        [221, 'University of Edinburgh Master\'s CS & Informatics Scholarship', 'University of Edinburgh', '🇬🇧 UK', "Master's", 'partial', 'high', 'May 31, 2027', '£5,000–£10,000 tuition fee waiver', ['Edinburgh','UK','Informatics','CS/AI/Data Science'], 'required', ['University of Edinburgh'], 'free'],
        [222, 'University of York Postgraduate CS Scholarships', 'University of York', '🇬🇧 UK', "Master's", 'partial', 'high', 'varies', '£3,000–£10,000 tuition fee waiver', ['York','UK','CS/IT/Cybersecurity'], 'required', ['University of York'], 'free'],
        [223, 'Google Women Techmakers (Anita Borg) Scholarship', 'Google', '🌍 Global', "Master's", 'fully', 'high', 'Dec 2026', '€7,000 / $10,000 grant + Google Retreat', ['Google','Women in Tech','CS/IT','EMEA'], 'MOI accepted', ['Global Universities'], 'free'],
        [224, 'Palantir Women in Technology Scholarship', 'Palantir Technologies', '🌍 Global', "Master's", 'fully', 'high', 'Dec 2026', '$7,000 grant + virtual workshop + mentorship', ['Palantir','Women in STEM','CS/IT/SE'], 'MOI accepted', ['Global Universities'], 'free'],
        [225, 'VIP Women in Technology Scholarship (WITS)', 'Visionary Integration Professionals (VIP)', '🇺🇸 USA', "Master's", 'partial', 'medium', 'Mar 31, 2027', '$2,500 merit scholarship award', ['VIP WITS','Women in Tech','CS/IT/SE'], 'MOI accepted', ['US & Global Universities'], 'free'],
        [226, 'Berrows International Scholarship Program (BISP)', 'Berrows Foundation / Partner Universities', '🌍 Global', "Master's", 'partial', 'high', 'varies', 'Up to $5,000 financial aid grant', ['Berrows','BISP','CS/IT','Global'], 'MOI accepted', ['Global Universities'], 'free'],
        [227, 'Wells Mountain Initiative (WMI) Scholar Program', 'Wells Mountain Initiative', '🌍 Global', "Master's", 'partial', 'high', 'Mar 1, 2027', '$1,500–$3,000/yr tuition + books & living grant', ['WMI','Developing Nations','CS/IT','Global'], 'MOI accepted', ['Global Universities'], 'free'],
        [228, 'WIPO Fellowship Program (UN Geneva)', 'World Intellectual Property Organization (WIPO)', '🇨🇭 Switzerland', "Master's", 'fully', 'high', 'Aug 2026', 'CHF 3,000–CHF 8,000/mo stipend + airfare + health insurance', ['WIPO','UN Agency','Geneva','CS/IT','Technical Fellowship'], 'TOEFL / MOI', ['WIPO Geneva Headquarters'], 'free'],
        [229, 'GREAT Scholarships UK (British Council)', '60+ UK Partner Universities', '🇬🇧 UK', "Master's", 'partial', 'high', 'May 31, 2027', 'Minimum £10,000 tuition fee waiver', ['GREAT UK','British Council','CS/AI/Data Science','Pakistan Eligible'], 'required', ['Manchester','Edinburgh','Glasgow','Sheffield','60+ UK Unis'], 'free'],
        [230, 'University of Milan DSU Regional Scholarship', 'University of Milan (UniMi)', '🇮🇹 Italy', "Master's", 'fully', 'high', 'Sep 30, 2026', 'Full tuition waiver + €7,000–€8,000/yr living allowance + free meals', ['UniMi','Milan','DSU','CS/IT/Data Science'], 'MOI accepted', ['University of Milan'], 'free'],
        [231, 'Sapienza University Worthy Students & DiscoLazio Scholarship', 'Sapienza University of Rome', '🇮🇹 Italy', "Master's", 'fully', 'high', 'Sep 2026', 'Full tuition waiver + €7,000–€8,000/yr DiscoLazio grant + dorm', ['Sapienza','Rome','DiscoLazio','CS/IT/AI'], 'MOI accepted', ['Sapienza University of Rome'], 'free'],
        [232, 'University of Padua International Excellence Scholarship', 'University of Padua (UNIPD)', '🇮🇹 Italy', "Master's", 'fully', 'high', 'Feb 2, 2027', 'Full tuition waiver + €8,000/yr living allowance', ['Padua','UNIPD','Italy','CS/IT/Data Science'], 'MOI accepted', ['University of Padua'], 'free'],
        [233, 'Haliç University International Scholarship', 'Haliç University', '🇹🇷 Turkey', "Master's", 'partial', 'medium', 'varies', '25%–75% tuition fee reduction waiver', ['Haliç','Turkey','CS/Cyber Security/MIS','No IELTS'], 'MOI accepted', ['Haliç University'], 'free'],
        [234, 'Linköping University (LiU) International Scholarship', 'Linköping University', '🇸🇪 Sweden', "Master's", 'partial', 'high', 'Apr 2, 2026', '25%, 50%, or 75% tuition fee waiver reduction', ['LiU','Linköping','Sweden','CS/IT/Data Science'], 'required', ['Linköping University'], 'free'],
        [235, 'Goethe Goes Global Master\'s Scholarship', 'Goethe University Frankfurt', '🇩🇪 Germany', "Master's", 'fully', 'high', 'May 31, 2027', 'Full tuition waiver + €500/mo stipend + academic mentoring', ['Goethe','Frankfurt','CS/Data Science','Bioinformatics'], 'required', ['Goethe University Frankfurt'], 'free'],
        [236, 'TU Darmstadt International Graduate Scholarship', 'Technical University of Darmstadt (TU Darmstadt)', '🇩🇪 Germany', "Master's", 'fully', 'high', 'varies', 'Full tuition waiver + €850/mo living grant', ['TU Darmstadt','TU9','CS/Software Eng','Cybersecurity'], 'required', ['TU Darmstadt'], 'free'],
        [237, 'Malmö University Master\'s Scholarship & MUES', 'Malmö University', '🇸🇪 Sweden', "Master's", 'partial', 'high', 'Apr 30, 2026', 'Up to 100% tuition fee waiver', ['Malmö','Sweden','CS/IT','Media Technology'], 'required', ['Malmö University'], 'free'],
        [238, 'University of Calabria (Unical) International Scholarship', 'University of Calabria', '🇮🇹 Italy', "Master's", 'fully', 'high', 'May 31, 2027', 'Full tuition waiver + free dorm housing + free meals + €1,000 stipend', ['Unical','Calabria','CS/AI/Robotics','MOI'], 'MOI accepted', ['University of Calabria'], 'free'],
        [239, 'University of Trento International Master\'s Scholarship', 'University of Trento', '🇮🇹 Italy', "Master's", 'fully', 'high', 'Mar 8, 2027', 'Full tuition waiver + €6,000/yr stipend + accommodation', ['Trento','Italy','CS/AI/Data Science','Top CS Uni'], 'MOI accepted', ['University of Trento'], 'free'],
        [240, 'Tokyo Institute of Technology MEXT Graduate Scholarship', 'Tokyo Institute of Technology (Tokyo Tech)', '🇯🇵 Japan', "Master's", 'fully', 'high', 'Dec 8, 2026', 'Full tuition waiver + ¥147,000/mo stipend + roundtrip airfare', ['Tokyo Tech','MEXT','CS','Computing Science','MOI'], 'MOI accepted', ['Tokyo Institute of Technology'], 'free'],
        [241, 'University of Turin 2-Year Master\'s & EDISU Scholarships', 'University of Turin (UniTo)', '🇮🇹 Italy', "Master's", 'fully', 'high', 'Apr 30, 2026', 'Full tuition waiver + €7,000–€8,000/yr EDISU grant + dorm', ['UniTo','Turin','EDISU','CS/IT/Data Science'], 'MOI accepted', ['University of Turin'], 'free'],
        [242, 'Victoria University of Wellington Postgraduate Scholarship', 'Victoria University of Wellington', '🇳🇿 New Zealand', "Master's", 'partial', 'high', 'Nov 1, 2026', 'NZ$5,000–NZ$10,000 tuition grant (Master\'s) / NZ$27,500/yr + tuition (PhD)', ['Wellington','New Zealand','CS/IT','Postgraduate'], 'required', ['Victoria University of Wellington'], 'free'],
        [243, 'Lund University Global Scholarship', 'Lund University', '🇸🇪 Sweden', "Master's", 'partial', 'high', 'Jan 15, 2027', '25%, 50%, 75%, or 100% tuition fee waiver', ['Lund','Sweden','CS/Software Eng','Data Science'], 'required', ['Lund University'], 'free'],
        [244, 'University of Canterbury Postgraduate Scholarship', 'University of Canterbury', '🇳🇿 New Zealand', "Master's", 'fully', 'high', 'Oct 15, 2026', 'Up to $20,000/yr tuition fee waiver & stipend allowance', ['Canterbury','New Zealand','CS','Software Eng','Data Science'], 'required', ['University of Canterbury'], 'free'],
        [245, 'University of Melbourne Graduate Research Scholarship', 'University of Melbourne', '🇦🇺 Australia', "Master's", 'fully', 'high', 'Oct 31, 2026', '100% tuition remission + $39,500/yr stipend + $3,000 relocation + health', ['Melbourne','Australia','CS/IT','600 Scholarships'], 'required', ['University of Melbourne'], 'free'],
        [246, 'Uppsala University Fee-Waiver Scholarship', 'Uppsala University', '🇸🇪 Sweden', "Master's", 'partial', 'high', 'Jan 22, 2026', 'Full tuition fee waiver', ['Uppsala','Sweden','CS/Bioinformatics','Info Systems'], 'required', ['Uppsala University'], 'free'],
        [247, 'Kyoto University of Advanced Science (KUAS) Super KUAS-E Scholarship', 'Kyoto University of Advanced Science', '🇯🇵 Japan', "Master's", 'fully', 'high', 'Dec 2026', '100% tuition & admission waiver + ¥1,200,000/yr stipend + airfare', ['KUAS','Super KUAS-E','Japan','CS/Robotics/Engineering'], 'required', ['Kyoto University of Advanced Science'], 'free'],
        [248, 'LUT University Master\'s Scholarship', 'LUT University', '🇫🇮 Finland', "Master's", 'fully', 'high', 'Jan 20, 2027', '100% tuition fee waiver + €5,000 relocation grant', ['LUT','Finland','Software Eng','CS/Data Science','MOI'], 'MOI accepted', ['LUT University'], 'free'],
        [249, 'Metropolia & LAB University IT Master\'s Scholarships', 'Metropolia / LAB Universities of Applied Sciences', '🇫🇮 Finland', "Master's", 'partial', 'high', 'Jan 20, 2027', '50%–100% tuition fee waiver + early bird discount', ['Metropolia','LAB','Finland','IT/Software Eng','MOI'], 'MOI accepted', ['Metropolia UAS','LAB UAS'], 'free'],
        [250, 'University of Auckland ADB-Japan Scholarship', 'University of Auckland', '🇳🇿 New Zealand', "Master's", 'fully', 'high', 'Jul 21, 2026', 'Full tuition + NZ$18,000+/yr stipend + accommodation + airfare', ['Auckland','ADB','New Zealand','CS/IT/SE','300 Awards'], 'required', ['University of Auckland'], 'free'],
        [251, 'University of Waikato International Excellence Scholarship', 'University of Waikato', '🇳🇿 New Zealand', "Master's", 'partial', 'high', 'Dec 2026', 'Up to NZ$15,000 tuition fee waiver grant', ['Waikato','New Zealand','CS/IT/AI','Software Eng'], 'required', ['University of Waikato'], 'free'],
        [252, 'University of Barcelona (UB) Master\'s & MAEC-AECID Grants', 'University of Barcelona', '🇪🇸 Spain', "Master's", 'fully', 'high', 'May 31, 2027', 'Full tuition waiver + €900/mo stipend + free housing + insurance', ['UB','Barcelona','Spain','CS/AI/Data Science','Duolingo'], 'Duolingo / TOEFL / MOI', ['University of Barcelona'], 'free'],
        [253, 'KTH Royal Institute of Technology Master\'s Scholarship', 'KTH Royal Institute of Technology', '🇸🇪 Sweden', "Master's", 'partial', 'high', 'Jan 15, 2027', '100% full tuition fee waiver', ['KTH','Sweden','CS/Software Eng','Machine Learning'], 'TOEFL 80-100 / MOI', ['KTH Royal Institute of Technology'], 'free'],
        [254, 'Yokohama National University MEXT Graduate Scholarship', 'Yokohama National University (YNU)', '🇯🇵 Japan', "Master's", 'fully', 'high', 'Oct 14, 2026', 'Full tuition waiver + ¥144,000/mo stipend + dorm + roundtrip airfare', ['YNU','MEXT','Yokohama','CS/IT','MOI'], 'MOI accepted', ['Yokohama National University'], 'free'],
        [255, 'TU Delft Justus & Louise van Effen Excellence Scholarship', 'Delft University of Technology (TU Delft)', '🇳🇱 Netherlands', "Master's", 'fully', 'high', 'Dec 1, 2026', 'Full tuition waiver + €30,000/yr living allowance', ['TU Delft','van Effen','Netherlands','CS/IT/Computer Eng'], 'required', ['Delft University of Technology'], 'free'],
        [256, 'Hamad Bin Khalifa University (HBKU) Scholarship', 'Hamad Bin Khalifa University', '🇶🇦 Qatar', "Master's", 'fully', 'high', 'May 3, 2026', 'Full tuition waiver + QAR 5,000/mo stipend + dorm + return airfare', ['HBKU','Qatar','Cybersecurity','Data Science','MOI'], 'MOI accepted', ['Hamad Bin Khalifa University'], 'free'],
        [257, 'University of Geneva Excellence Master Fellowships', 'University of Geneva (UNIGE)', '🇨🇭 Switzerland', "Master's", 'partial', 'high', 'Feb 28, 2026', 'CHF 10,000–CHF 15,000/yr stipend (tuition CHF 500/sem)', ['Geneva','UNIGE','Switzerland','CS/Bioinformatics'], 'required', ['University of Geneva'], 'free'],
        [258, 'University of Oslo (UiO) & BI Master\'s Scholarships', 'University of Oslo / BI Norwegian', '🇳🇴 Norway', "Master's", 'fully', 'high', 'Dec 1, 2026', 'Full tuition waiver + NOK 100,000/yr living grant stipend', ['UiO','Oslo','Norway','Informatics/CS','MOI'], 'MOI accepted', ['University of Oslo','BI Norwegian Business School'], 'free'],
        [259, 'NTNU Norwegian University of Science & Technology Fellowship', 'NTNU Norway', '🇳🇴 Norway', "Master's", 'fully', 'high', 'Dec 1, 2026', 'Tuition-free + NOK 120,000/yr stipend research grant', ['NTNU','Norway','CS/AI/Cybersecurity','Top Tech Uni'], 'MOI accepted', ['NTNU Trondheim'], 'free'],
        [260, 'Australia Awards Scholarships (AAS)', 'Australian Government (DFAT)', '🇦🇺 Australia', "Master's", 'fully', 'high', 'Apr 30, 2026', 'Full tuition + living allowance + roundtrip airfare + OSHC health cover', ['AAS','Australia Awards','Pakistan Eligible','CS/IT'], 'required', ['Australian Universities'], 'free'],
        [261, 'Queen Elizabeth Commonwealth Scholarships (QECS)', 'Association of Commonwealth Universities (ACU)', '🌍 Multi', "Master's", 'fully', 'high', 'May 24, 2026', 'Full tuition + monthly stipend + return airfare + arrival allowance', ['QECS','Commonwealth','Pakistan Eligible','CS/IT/Data Science'], 'required', ['Commonwealth Partner Universities'], 'free']
    ];

    $sql = "
        INSERT IGNORE INTO `scholarships` 
            (id, name, uni, country, level, funding, priority_status, deadline, stipend, tags, toefl, unis, fee, is_custom)
        VALUES 
            (:id, :name, :uni, :country, :level, :funding, :status, :deadline, :stipend, :tags, :toefl, :unis, :fee, 0)
    ";

    $stmt = $pdo->prepare($sql);
    foreach ($defaultScholarships as $item) {
        $stmt->execute([
            ':id'       => $item[0],
            ':name'     => $item[1],
            ':uni'      => $item[2],
            ':country'  => $item[3],
            ':level'    => $item[4],
            ':funding'  => $item[5],
            ':status'   => $item[6],
            ':deadline' => $item[7],
            ':stipend'  => $item[8],
            ':tags'     => json_encode($item[9]),
            ':toefl'    => $item[10],
            ':unis'     => json_encode($item[11]),
            ':fee'      => $item[12]
        ]);
    }
}
