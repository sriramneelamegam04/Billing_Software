<?php
require_once __DIR__.'/../models/Subscription.php';

class SubscriptionService {
    private $subscriptionModel;
    public function __construct($pdo) {
        $this->subscriptionModel = new Subscription($pdo);
    }

    public function getActiveSubscription($org_id) {
        return $this->subscriptionModel->getActive($org_id);
    }

    public function checkActive($org_id) {
        $sub = $this->getActiveSubscription($org_id);
        if(!$sub) throw new Exception("No active subscription for this organization.");
        return $sub;
    }
}
