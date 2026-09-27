try:
    from flask import Flask, request, jsonify  # type: ignore[reportMissingImports]
    from flask_cors import CORS  # type: ignore[reportMissingImports]
except ImportError as exc:
    raise RuntimeError(
        "Required backend dependencies are missing. Install flask and flask-cors before starting the server."
    ) from exc

from datetime import datetime
import hashlib

app = Flask(__name__)
CORS(app)

patients = []
audit_logs = []

# System startup log
audit_logs.append({
    "timestamp": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
    "event_type": "SYSTEM_STARTUP",
    "details": "Clinical Server Started Successfully",
    "ip_address": "127.0.0.1"
})


def log_audit(event_type, details):
    audit_logs.insert(0, {
        "timestamp": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
        "event_type": event_type,
        "details": details,
        "ip_address": request.remote_addr or "127.0.0.1"
    })


# Health
@app.route("/api/v1/health", methods=["GET"])
def health():
    return jsonify({
        "status": "OPERATIONAL",
        "vault_db": "CONNECTED"
    })


# Get patients
@app.route("/api/v1/patients", methods=["GET"])
def get_patients():
    return jsonify({
        "success": True,
        "data": patients
    })


# Register patient
@app.route("/api/v1/patients", methods=["POST"])
def create_patient():
    data = request.get_json() or {}

    name = data.get("full_name") or data.get("name") or "Anonymous Patient"
    age = data.get("age") or "N/A"
    gender = data.get("gender") or "N/A"
    modality = data.get("modality") or data.get("scan") or "General Scan"

    masked_name = " ".join(
        [part[0] + "***" for part in name.split() if part]
    )

    mrn = f"MRN-{datetime.now().strftime('%Y%m%d')}-{101 + len(patients)}"

    patient = {
        "id": int(datetime.now().timestamp() * 1000),
        "mrn": mrn,
        "masked_name": masked_name,
        "age": age,
        "gender": gender,
        "modality": modality
    }

    patients.insert(0, patient)

    log_audit(
        "PATIENT_REGISTRATION",
        f"Patient record created for {mrn}"
    )

    return jsonify({
        "success": True,
        "mrn": mrn,
        "data": patient
    }), 201


# Billing
@app.route("/api/v1/billing/create", methods=["POST"])
def create_billing():
    data = request.get_json() or {}

    patient_mrn = data.get("patient_mrn") or "MRN-TEMP-001"
    patient_name = data.get("patient_name") or "Standard Patient"

    try:
        amount = float(data.get("amount") or 100)
    except (ValueError, TypeError):
        return jsonify({
            "success": False,
            "error": "Invalid billing amount"
        }), 400

    subtotal = round(amount, 2)
    tax = round(subtotal * 0.05, 2)
    total_amount = round(subtotal + tax, 2)

    timestamp = datetime.now().isoformat()

    raw_data = f"{patient_mrn}:{patient_name}:{total_amount}:{timestamp}"
    digital_signature = hashlib.sha256(
        raw_data.encode()
    ).hexdigest()

    invoice_id = "INV-" + hashlib.md5(
        raw_data.encode()
    ).hexdigest()[:6].upper()

    log_audit(
        "BILLING_ISSUED",
        f"Invoice created for {patient_mrn} (${total_amount})"
    )

    return jsonify({
        "success": True,
        "invoice": {
            "invoice_id": invoice_id,
            "patient_mrn": patient_mrn,
            "patient_name": patient_name,
            "subtotal": subtotal,
            "tax": tax,
            "total_amount": total_amount,
            "digital_signature": digital_signature
        }
    })


# Audit logs
@app.route("/api/v1/audit-logs", methods=["GET"])
def get_audit_logs():
    return jsonify({
        "success": True,
        "data": audit_logs
    })


if __name__ == "__main__":
    print("KINETIX Medical Platform Python Backend")
    print("Server running on http://localhost:5000")
    app.run(host="127.0.0.1", port=5000, debug=True)
