<?php

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

require_once "database.php";

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    exit;
}

$method = $_SERVER["REQUEST_METHOD"];
$action = $_GET["action"] ?? "";

if ($action === "health") {
    echo json_encode([
        "success" => true,
        "status" => "OPERATIONAL",
        "database" => "CONNECTED"
    ]);
    exit;
}

if ($action === "patients" && $method === "GET") {
    $result = $db->query("
        SELECT id, mrn, full_name, age, gender, modality, created_at
        FROM patients
        ORDER BY id DESC
    ");

    $patients = [];

    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $nameParts = preg_split('/\s+/', trim($row["full_name"]));
        $maskedName = "";

        foreach ($nameParts as $part) {
            if ($part !== "") {
                $maskedName .= substr($part, 0, 1) . "*** ";
            }
        }

        $row["masked_name"] = trim($maskedName);
        $patients[] = $row;
    }

    echo json_encode([
        "success" => true,
        "data" => $patients
    ]);

    exit;
}

if ($action === "patients" && $method === "POST") {

    $input = json_decode(file_get_contents("php://input"), true);

    $fullName = trim($input["full_name"] ?? "");
    $age = intval($input["age"] ?? -1);
    $gender = trim($input["gender"] ?? "");
    $modality = trim($input["modality"] ?? "");

    if ($fullName === "") {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "Full name is required."
        ]);
        exit;
    }

    if ($age < 0 || $age > 150) {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "Age must be between 0 and 150."
        ]);
        exit;
    }

    if ($gender === "") {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "Gender is required."
        ]);
        exit;
    }

    if ($modality === "") {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "Medical modality is required."
        ]);
        exit;
    }

    $mrn = "MRN-" . date("Ymd") . "-" .
        str_pad(random_int(100, 999), 3, "0", STR_PAD_LEFT);

    $stmt = $db->prepare("
        INSERT INTO patients
        (mrn, full_name, age, gender, modality)
        VALUES
        (:mrn, :full_name, :age, :gender, :modality)
    ");

    $stmt->bindValue(":mrn", $mrn, SQLITE3_TEXT);
    $stmt->bindValue(":full_name", $fullName, SQLITE3_TEXT);
    $stmt->bindValue(":age", $age, SQLITE3_INTEGER);
    $stmt->bindValue(":gender", $gender, SQLITE3_TEXT);
    $stmt->bindValue(":modality", $modality, SQLITE3_TEXT);

    $result = $stmt->execute();

    if (!$result) {
        http_response_code(500);
        echo json_encode([
            "success" => false,
            "message" => "Patient could not be registered."
        ]);
        exit;
    }

    $log = $db->prepare("
        INSERT INTO audit_logs
        (event_type, details, ip_address)
        VALUES
        (:event, :details, :ip)
    ");

    $log->bindValue(":event", "PATIENT_REGISTRATION", SQLITE3_TEXT);
    $log->bindValue(
        ":details",
        "Patient registered with MRN " . $mrn,
        SQLITE3_TEXT
    );
    $log->bindValue(
        ":ip",
        $_SERVER["REMOTE_ADDR"] ?? "unknown",
        SQLITE3_TEXT
    );

    $log->execute();

    echo json_encode([
        "success" => true,
        "message" => "Patient registered successfully.",
        "mrn" => $mrn
    ]);

    exit;
}

if ($action === "billing" && $method === "POST") {

    $input = json_decode(file_get_contents("php://input"), true);

    $mrn = trim($input["patient_mrn"] ?? "");
    $name = trim($input["patient_name"] ?? "");
    $amount = floatval($input["amount"] ?? 0);

    if ($mrn === "" || $name === "" || $amount <= 0) {
        http_response_code(400);

        echo json_encode([
            "success" => false,
            "message" => "Invalid billing information."
        ]);

        exit;
    }

    $tax = round($amount * 0.05, 2);
    $total = round($amount + $tax, 2);

    $invoiceId = "INV-" . date("YmdHis") . "-" .
        random_int(100, 999);

    $signatureData =
        $mrn . "|" .
        $name . "|" .
        $total . "|" .
        date("c");

    $signature = hash("sha256", $signatureData);

    $log = $db->prepare("
        INSERT INTO audit_logs
        (event_type, details, ip_address)
        VALUES
        (:event, :details, :ip)
    ");

    $log->bindValue(":event", "BILLING_ISSUED", SQLITE3_TEXT);

    $log->bindValue(
        ":details",
        "Invoice " . $invoiceId . " created for " . $mrn,
        SQLITE3_TEXT
    );

    $log->bindValue(
        ":ip",
        $_SERVER["REMOTE_ADDR"] ?? "unknown",
        SQLITE3_TEXT
    );

    $log->execute();

    echo json_encode([
        "success" => true,
        "invoice" => [
            "invoice_id" => $invoiceId,
            "patient_mrn" => $mrn,
            "patient_name" => $name,
            "subtotal" => $amount,
            "tax" => $tax,
            "total_amount" => $total,
            "digital_signature" => $signature
        ]
    ]);

    exit;
}

if ($action === "audit-logs" && $method === "GET") {

    $result = $db->query("
        SELECT event_type, details, ip_address, created_at
        FROM audit_logs
        ORDER BY id DESC
    ");

    $logs = [];

    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $logs[] = [
            "timestamp" => $row["created_at"],
            "event_type" => $row["event_type"],
            "details" => $row["details"],
            "ip_address" => $row["ip_address"]
        ];
    }

    echo json_encode([
        "success" => true,
        "data" => $logs
    ]);

    exit;
}

http_response_code(404);

echo json_encode([
    "success" => false,
    "message" => "API endpoint not found."
]);

?>