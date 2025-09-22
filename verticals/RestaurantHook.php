<?php

class RestaurantHook {
    public static function beforeSaleCreate($saleData) {
        // Example: Add service charge 5% if not set
        if(!isset($saleData['service_charge'])) {
            $saleData['service_charge'] = round($saleData['total_amount'] * 0.05, 2);
            $saleData['total_amount'] += $saleData['service_charge'];
        }
        return $saleData;
    }

    public static function afterSaleCreate($saleResult) {
        // Could send kitchen notification, etc.
        return $saleResult;
    }
}
