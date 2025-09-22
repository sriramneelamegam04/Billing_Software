<?php
require_once __DIR__.'/../models/Feature.php';
require_once __DIR__.'/../models/VerticalFeature.php';

class FeatureService {
    private $featureModel;
    private $verticalFeatureModel;

    public function __construct($pdo) {
        $this->featureModel = new Feature($pdo);
        $this->verticalFeatureModel = new VerticalFeature($pdo);
    }

    // Org features (only enabled)
    public function listOrgFeatures($org_id) {
        $features = $this->verticalFeatureModel->list($org_id);
        return array_filter($features, fn($f) => $f['enabled'] == 1);
    }

    // Assign / toggle feature
    public function assignFeatureToOrg($org_id, $feature_id, $enabled = 1) {
        return $this->verticalFeatureModel->assignFeature($org_id, $feature_id, $enabled);
    }

    // List all features
    public function listAllFeatures() {
        return $this->featureModel->list();
    }
}
