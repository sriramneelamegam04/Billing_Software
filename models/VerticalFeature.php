<?php
class VerticalFeature {
    private $pdo;
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    // Get features for an org based on vertical
    public function list($org_id) {
        $stmt = $this->pdo->prepare("
            SELECT f.id, f.name, f.code, vf.is_required, vf.enabled
            FROM vertical_features vf
            JOIN features f ON f.id = vf.feature_id
            JOIN orgs o ON o.vertical = vf.vertical
            WHERE o.id = ?
        ");
        $stmt->execute([$org_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Assign or update feature toggle
    public function assignFeature($org_id, $feature_id, $enabled = 1) {
        $stmt = $this->pdo->prepare("
            INSERT INTO vertical_features (vertical, feature_id, is_required, enabled)
            SELECT o.vertical, ?, 0, ?
            FROM orgs o WHERE o.id = ?
            ON DUPLICATE KEY UPDATE enabled = VALUES(enabled)
        ");
        return $stmt->execute([$feature_id, $enabled, $org_id]);
    }
}
